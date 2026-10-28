<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Sales\Deadlines;

/**
 * Suivi commercial : pour chaque RFQ, la réponse (premier devis envoyé, délai, respect de l'échéance),
 * le sort du devis en cours (en attente, accepté, perdu et pourquoi) et le PO client obtenu.
 */
final class SalesTrackingService
{
    /** Étapes, dans l'ordre du processus. */
    public const STAGES = ['to_answer', 'awaiting_client', 'awaiting_po', 'won', 'lost', 'cancelled'];

    public function __construct(private Database $db, private DeadlinePolicy $policy, private FollowupService $followups)
    {
    }

    /**
     * @param array{client_id?: ?int, from?: ?string, to?: ?string, stage?: string} $filters période sur la date de réception
     * @return list<array<string, mixed>>
     */
    public function rows(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['client_id'])) {
            $where[] = 'r.client_id = :client';
            $params['client'] = (int) $filters['client_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'r.received_on >= :from';
            $params['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'r.received_on <= :to';
            $params['to'] = $filters['to'];
        }
        // Devis en cours = la dernière version non remplacée ; première réponse = premier envoi, toutes versions confondues.
        $rows = $this->db->fetchAll(
            "SELECT r.id, r.reference, r.client_id, c.name AS client_name, c.code AS client_code, r.client_reference, r.received_on, r.due_at,
                    r.status AS rfq_status, r.closed_reason, u.name AS owner_name,
                    (SELECT MIN(q.sent_at) FROM quotations q WHERE q.rfq_id = r.id) AS first_sent_at,
                    q.id AS quotation_id, q.reference AS quotation_reference, q.version, q.status AS quotation_status, q.sent_at, q.decided_at,
                    q.valid_until, q.lost_reason, q.lost_comment,
                    (SELECT COALESCE(SUM(ROUND(l.quantity_milli * l.unit_price_minor / 1000)), 0) FROM quotation_lines l WHERE l.quotation_id = q.id) AS quotation_amount,
                    o.id AS order_id, o.reference AS order_reference, o.client_po_number, o.received_on AS order_received_on
               FROM rfqs r
               JOIN clients c ON c.id = r.client_id
               LEFT JOIN users u ON u.id = r.owner_id
               LEFT JOIN quotations q ON q.id = (SELECT q2.id FROM quotations q2 WHERE q2.rfq_id = r.id AND q2.status <> 'superseded' ORDER BY q2.version DESC LIMIT 1)
               LEFT JOIN client_orders o ON o.id = (SELECT o2.id FROM client_orders o2 WHERE o2.rfq_id = r.id AND o2.status <> 'cancelled' ORDER BY o2.id LIMIT 1)
              WHERE " . implode(' AND ', $where) . '
              ORDER BY r.received_on DESC, r.id DESC',
            $params,
        );
        $followups = $this->followups->countsForQuotations(array_values(array_filter(array_map(static fn (array $r): ?int => $r['quotation_id'] !== null ? (int) $r['quotation_id'] : null, $rows))));
        $mode = $this->policy->mode();
        $now = date('Y-m-d H:i:s');
        $out = [];
        foreach ($rows as $r) {
            $answered = $r['first_sent_at'] !== null;
            $answerAt = $answered ? (string) $r['first_sent_at'] : null;
            $r['response_days'] = $answered ? Deadlines::between((string) $r['received_on'], (string) $answerAt, $mode) : null;
            // À l'heure : premier envoi avant l'échéance ; sans réponse, en retard dès l'échéance passée.
            $r['on_time'] = $r['due_at'] === null ? null : ($answered ? $answerAt <= $r['due_at'] : ($now <= $r['due_at'] ? null : false));
            $r['late_days'] = $r['due_at'] !== null && ($answerAt ?? $now) > $r['due_at']
                ? max(1, Deadlines::between(substr((string) $r['due_at'], 0, 10), substr($answerAt ?? $now, 0, 10), $mode)) : 0;
            $r['stage'] = self::stage($r);
            $r['followups'] = (int) ($followups[$r['quotation_id']]['n'] ?? 0);
            $r['last_followup_on'] = $followups[$r['quotation_id']]['last_on'] ?? null;
            if (empty($filters['stage']) || $filters['stage'] === $r['stage']) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * Indicateurs d'un ensemble de RFQ.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function summary(array $rows): array
    {
        $count = static fn (callable $f): int => count(array_filter($rows, $f));
        $answered = array_values(array_filter($rows, static fn (array $r): bool => $r['response_days'] !== null));
        $withDue = array_filter($answered, static fn (array $r): bool => $r['on_time'] !== null);
        $won = array_filter($rows, static fn (array $r): bool => $r['stage'] === 'won');
        $lost = array_filter($rows, static fn (array $r): bool => $r['stage'] === 'lost');
        $decided = count($won) + count($lost);
        $reasons = [];
        foreach ($lost as $r) {
            $key = $r['lost_reason'] ?? 'unknown';
            $reasons[$key] = ($reasons[$key] ?? 0) + 1;
        }
        arsort($reasons);
        return [
            'rfqs' => count($rows),
            'answered' => count($answered),
            'to_answer' => $count(static fn (array $r): bool => $r['stage'] === 'to_answer'),
            'late_to_answer' => $count(static fn (array $r): bool => $r['stage'] === 'to_answer' && $r['on_time'] === false),
            'on_time' => count(array_filter($withDue, static fn (array $r): bool => $r['on_time'] === true)),
            'on_time_base' => count($withDue),
            'avg_response_days' => $answered === [] ? null : round(array_sum(array_column($answered, 'response_days')) / count($answered), 1),
            'awaiting' => $count(static fn (array $r): bool => in_array($r['stage'], ['awaiting_client', 'awaiting_po'], true)),
            'won' => count($won),
            'lost' => count($lost),
            'conversion_bp' => $decided > 0 ? intdiv(count($won) * 10000, $decided) : null,
            'won_amount' => array_sum(array_map(static fn (array $r): int => (int) $r['quotation_amount'], $won)),
            'lost_amount' => array_sum(array_map(static fn (array $r): int => (int) $r['quotation_amount'], $lost)),
            'reasons' => $reasons,
            'stages' => array_map(static fn (string $stage): array => [
                'n' => count(array_filter($rows, static fn (array $r): bool => $r['stage'] === $stage)),
                'amount' => array_sum(array_map(static fn (array $r): int => $r['stage'] === $stage && $r['quotation_id'] !== null ? (int) $r['quotation_amount'] : 0, $rows)),
            ], array_combine(self::STAGES, self::STAGES)),
        ];
    }

    /** @param array<string, mixed> $r */
    private static function stage(array $r): string
    {
        if ($r['order_id'] !== null) {
            return 'won';
        }
        if ($r['rfq_status'] === 'cancelled') {
            return 'cancelled';
        }
        return match ($r['quotation_status']) {
            'sent' => 'awaiting_client',
            'accepted' => 'awaiting_po',
            'rejected' => 'lost',
            default => $r['rfq_status'] === 'lost' ? 'lost' : 'to_answer',
        };
    }
}
