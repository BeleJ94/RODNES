<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Sales\Deadlines;
use App\Domain\Shared\RuleViolation;
use App\Security\Auth;

/**
 * Relances : devis envoyé sans réponse (quote), devis accepté sans PO client (client_po),
 * PO fournisseur non confirmé (supplier_confirm), mise à disposition à vérifier avant la date prévue (supplier_ready).
 * L'échéance de chaque relance découle du rythme paramétré et du nombre de relances déjà notées.
 */
final class FollowupService
{
    public const KINDS = ['quote', 'client_po', 'supplier_confirm', 'supplier_ready'];
    public const CHANNELS = ['phone', 'email', 'whatsapp', 'visit', 'other'];

    /** Permission requise pour voir / noter chaque type de relance. */
    public const PERMS = [
        'quote' => ['quotations.view', 'quotations.manage'],
        'client_po' => ['quotations.view', 'quotations.manage'],
        'supplier_confirm' => ['supplier_orders.view', 'supplier_orders.manage'],
        'supplier_ready' => ['supplier_orders.view', 'supplier_orders.manage'],
    ];

    private const UNSHIPPED = "EXISTS (SELECT 1 FROM supplier_order_lines sl WHERE sl.supplier_order_id = so.id
        AND sl.quantity_milli > (SELECT COALESCE(SUM(l.quantity_milli), 0) FROM shipment_lines l JOIN shipments sh ON sh.id = l.shipment_id
                                  WHERE l.supplier_order_line_id = sl.id AND sh.status <> 'cancelled'))";

    public function __construct(
        private Database $db,
        private DeadlinePolicy $policy,
        private ActivityLogger $logger,
        private Auth $auth,
    ) {
    }

    /**
     * Relances ouvertes (toutes échéances), triées par date due.
     *
     * @param list<string> $kinds types visibles par l'utilisateur
     * @return list<array{kind: string, subject_id: int, reference: string, party: string, party_id: int, owner_id: ?int, owner_name: ?string,
     *                    start: string, done: int, planned: int, due_on: string, last_on: ?string, url: string, expires_on: ?string}>
     */
    public function open(array $kinds = self::KINDS, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $mode = $this->policy->mode();
        $items = [];
        foreach ($this->subjects($kinds) as $s) {
            $kind = $s['kind'];
            $done = (int) $s['done'];
            if ($kind === 'supplier_ready') {
                if ($done > 0) {
                    continue;
                }
                $due = $this->policy->readyCheckOn((string) $s['start']);
                $planned = 1;
            } else {
                $schedule = $this->policy->schedule($kind);
                $due = Deadlines::nextFollowup((string) $s['start'], $schedule, $done, $mode);
                $planned = count($schedule);
                if ($due === null) {
                    continue;
                }
            }
            $items[] = [
                'kind' => $kind,
                'subject_id' => (int) $s['id'],
                'reference' => (string) $s['reference'],
                'party' => (string) $s['party'],
                'party_id' => (int) $s['party_id'],
                'owner_id' => $s['owner_id'] !== null ? (int) $s['owner_id'] : null,
                'owner_name' => $s['owner_name'],
                'start' => substr((string) $s['start'], 0, 10),
                'done' => $done,
                'planned' => $planned,
                'due_on' => $due,
                'last_on' => $s['last_on'],
                'url' => url(($kind === 'quote' || $kind === 'client_po' ? '/quotations/' : '/supplier-orders/') . $s['id']),
                'expires_on' => $s['expires_on'],
                'late_days' => $due < $today ? Deadlines::between($due, $today, $mode) : 0,
            ];
        }
        usort($items, static fn (array $a, array $b): int => [$a['due_on'], $a['reference']] <=> [$b['due_on'], $b['reference']]);
        return $items;
    }

    /** @param list<string> $kinds */
    public function dueCount(array $kinds, ?string $today = null): int
    {
        $today ??= date('Y-m-d');
        return count(array_filter($this->open($kinds, $today), static fn (array $f): bool => $f['due_on'] <= $today));
    }

    /** Prochaine relance d'un devis ou d'un PO fournisseur (null si aucune prévue). */
    public function nextFor(string $kind, int $subjectId): ?array
    {
        foreach ($this->open([$kind]) as $f) {
            if ($f['subject_id'] === $subjectId) {
                return $f;
            }
        }
        return null;
    }

    /** @return list<array<string, mixed>> relances notées, la plus récente d'abord */
    public function history(string $kind, int $subjectId): array
    {
        return $this->db->fetchAll(
            'SELECT f.*, u.name AS created_by_name FROM followups f LEFT JOIN users u ON u.id = f.created_by
              WHERE f.kind = :k AND f.subject_id = :s ORDER BY f.contacted_on DESC, f.id DESC',
            ['k' => $kind, 's' => $subjectId],
        );
    }

    /** @return list<array<string, mixed>> toutes les relances d'un ensemble de devis (suivi commercial) */
    public function countsForQuotations(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        return array_column($this->db->fetchAll(
            "SELECT subject_id, COUNT(*) AS n, MAX(contacted_on) AS last_on FROM followups
              WHERE kind IN ('quote', 'client_po') AND subject_id IN ({$in}) GROUP BY subject_id"
        ), null, 'subject_id');
    }

    public function record(string $kind, int $subjectId, string $channel, string $contactedOn, ?string $comment): int
    {
        if (!in_array($kind, self::KINDS, true) || !in_array($channel, self::CHANNELS, true)) {
            throw new RuleViolation('common.error_transition');
        }
        if ($contactedOn > date('Y-m-d')) {
            throw new RuleViolation('followups.error_future');
        }
        return $this->db->transaction(function () use ($kind, $subjectId, $channel, $contactedOn, $comment): int {
            if (!in_array($subjectId, array_map('intval', array_column($this->subjects([$kind]), 'id')), true)) {
                throw new RuleViolation('followups.error_not_open');
            }
            $id = $this->db->insert(
                'INSERT INTO followups (kind, subject_id, channel, contacted_on, comment, created_by) VALUES (:k, :s, :c, :d, :m, :u)',
                ['k' => $kind, 's' => $subjectId, 'c' => $channel, 'd' => $contactedOn, 'm' => $comment, 'u' => $this->auth->user()?->id],
            );
            $entity = $kind === 'quote' || $kind === 'client_po' ? 'quotation' : 'supplier_order';
            $this->logger->log($entity, $subjectId, 'followup', [], ['kind' => $kind, 'channel' => $channel, 'contacted_on' => $contactedOn, 'comment' => $comment]);
            return $id;
        });
    }

    /**
     * Pièces à relancer, avec le point de départ du rythme et le nombre de relances déjà faites.
     *
     * @param list<string> $kinds
     * @return list<array<string, mixed>>
     */
    private function subjects(array $kinds): array
    {
        $count = static fn (string $kind, string $alias): string => "(SELECT COUNT(*) FROM followups f WHERE f.kind = '{$kind}' AND f.subject_id = {$alias}.id) AS done,
            (SELECT MAX(f.contacted_on) FROM followups f WHERE f.kind = '{$kind}' AND f.subject_id = {$alias}.id) AS last_on";
        $parts = [];
        if (in_array('quote', $kinds, true)) {
            $parts[] = "SELECT 'quote' AS kind, q.id, CONCAT(q.reference, ' v', q.version) AS reference, c.name AS party, c.id AS party_id,
                               r.owner_id, u.name AS owner_name, q.sent_at AS start, q.valid_until AS expires_on, {$count('quote', 'q')}
                          FROM quotations q JOIN clients c ON c.id = q.client_id JOIN rfqs r ON r.id = q.rfq_id LEFT JOIN users u ON u.id = r.owner_id
                         WHERE q.status = 'sent'";
        }
        if (in_array('client_po', $kinds, true)) {
            $parts[] = "SELECT 'client_po' AS kind, q.id, CONCAT(q.reference, ' v', q.version) AS reference, c.name AS party, c.id AS party_id,
                               r.owner_id, u.name AS owner_name, q.decided_at AS start, NULL AS expires_on, {$count('client_po', 'q')}
                          FROM quotations q JOIN clients c ON c.id = q.client_id JOIN rfqs r ON r.id = q.rfq_id LEFT JOIN users u ON u.id = r.owner_id
                         WHERE q.status = 'accepted' AND NOT EXISTS (SELECT 1 FROM client_orders o WHERE o.quotation_id = q.id AND o.status <> 'cancelled')";
        }
        if (in_array('supplier_confirm', $kinds, true)) {
            $parts[] = "SELECT 'supplier_confirm' AS kind, so.id, so.reference, s.name AS party, s.id AS party_id,
                               so.created_by AS owner_id, u.name AS owner_name, so.sent_at AS start, NULL AS expires_on, {$count('supplier_confirm', 'so')}
                          FROM supplier_orders so JOIN suppliers s ON s.id = so.supplier_id LEFT JOIN users u ON u.id = so.created_by
                         WHERE so.status = 'sent' AND so.sent_at IS NOT NULL";
        }
        if (in_array('supplier_ready', $kinds, true)) {
            $parts[] = "SELECT 'supplier_ready' AS kind, so.id, so.reference, s.name AS party, s.id AS party_id,
                               so.created_by AS owner_id, u.name AS owner_name, so.expected_ready_on AS start, so.expected_ready_on AS expires_on, {$count('supplier_ready', 'so')}
                          FROM supplier_orders so JOIN suppliers s ON s.id = so.supplier_id LEFT JOIN users u ON u.id = so.created_by
                         WHERE so.status = 'confirmed' AND so.expected_ready_on IS NOT NULL AND " . self::UNSHIPPED;
        }
        return $parts === [] ? [] : $this->db->fetchAll(implode("\nUNION ALL\n", $parts));
    }
}
