<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Sales\RfqStatus;
use App\Domain\Shared\RuleViolation;
use App\Repositories\RfqRepository;
use App\Security\Auth;

final class RfqService
{
    private const HEADER = ['client_id', 'client_reference', 'received_on', 'due_at', 'buyer_name', 'buyer_email', 'delivery_site', 'owner_id', 'notes'];

    public function __construct(
        private Database $db,
        private RfqRepository $rfqs,
        private Sequences $sequences,
        private ActivityLogger $logger,
        private Auth $auth,
        private DeadlinePolicy $policy,
        private FileStorage $files,
    ) {
    }

    private int $createdItems = 0;

    /**
     * @param array<string, mixed> $header
     * @param list<array{item_id: ?int, description: string, quantity_milli: int, unit: string, required_by: ?string}> $lines
     */
    public function create(array $header, array $lines, array $uploads = []): int
    {
        return $this->db->transaction(function () use ($header, $lines, $uploads): int {
            $header = array_intersect_key($header, array_flip(self::HEADER));
            // Sans date imposée par le client : délai de réponse paramétré (client, sinon société).
            $header['due_at'] = !empty($header['due_at']) ? $header['due_at'] : $this->policy->rfqDueAt((int) $header['client_id'], (string) $header['received_on']);
            $reference = $this->sequences->next('rfq', 'RFQ');
            $id = $this->db->insert(
                'INSERT INTO rfqs (reference, client_id, client_reference, received_on, due_at, buyer_name, buyer_email, delivery_site, owner_id, notes, created_by)
                 VALUES (:reference, :client_id, :client_reference, :received_on, :due_at, :buyer_name, :buyer_email, :delivery_site, :owner_id, :notes, :created_by)',
                $header + ['reference' => $reference, 'created_by' => $this->auth->user()?->id],
            );
            $this->insertLines($id, $this->resolveItems($lines));
            $this->attach($id, $uploads);
            $this->logger->log('rfq', $id, 'created', [], $header + ['reference' => $reference, 'lines' => count($lines), 'files' => count($uploads)]);
            return $id;
        });
    }

    /**
     * En-tête modifiable tant que la RFQ est ouverte ; lignes modifiables tant qu'aucune offre ni quotation ne les reprend.
     *
     * @param array<string, mixed> $header
     * @param list<array{item_id: ?int, description: string, quantity_milli: int, unit: string, required_by: ?string}> $lines
     */
    public function update(int $id, array $header, array $lines, array $uploads = []): void
    {
        $this->db->transaction(function () use ($id, $header, $lines, $uploads): void {
            $before = $this->rfqs->get($id);
            if (!RfqStatus::from((string) $before['status'])->isOpen()) {
                throw new RuleViolation('rfqs.error_closed');
            }
            $header = array_intersect_key($header, array_flip(self::HEADER));
            $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($header)));
            $this->db->run("UPDATE rfqs SET {$set} WHERE id = :id", $header + ['id' => $id]);
            $lines = $this->resolveItems($lines);
            $this->attach($id, $uploads);
            if (!$this->sameLines($this->rfqs->lines($id), $lines)) {
                if ($this->rfqs->linesAreReferenced($id)) {
                    throw new RuleViolation('rfqs.error_lines_locked');
                }
                $this->db->run('DELETE FROM rfq_lines WHERE rfq_id = :id', ['id' => $id]);
                $this->insertLines($id, $lines);
            }
            $this->logger->log('rfq', $id, 'updated', array_intersect_key($before, $header), $header);
        });
    }

    /** Clôture manuelle : perdue ou sans suite. */
    public function close(int $id, RfqStatus $to, ?string $reason): void
    {
        $this->db->transaction(function () use ($id, $to, $reason): void {
            $rfq = $this->rfqs->get($id);
            $from = RfqStatus::from((string) $rfq['status']);
            if (!in_array($to, [RfqStatus::Lost, RfqStatus::Cancelled], true) || !$from->canTransitionTo($to)) {
                throw new RuleViolation('common.error_transition');
            }
            $this->db->run('UPDATE rfqs SET status = :s, closed_reason = :r WHERE id = :id', ['s' => $to->value, 'r' => $reason, 'id' => $id]);
            $this->logger->log('rfq', $id, 'status', ['status' => $from->value], ['status' => $to->value, 'closed_reason' => $reason]);
        });
    }

    /** Avancement automatique (demande de prix, envoi, acceptation) ; sans effet si la transition n'a pas de sens. */
    public function advance(int $id, RfqStatus $to): void
    {
        $from = RfqStatus::from((string) $this->db->fetchValue('SELECT status FROM rfqs WHERE id = :id FOR UPDATE', ['id' => $id]));
        if ($from->canTransitionTo($to)) {
            $this->db->run('UPDATE rfqs SET status = :s WHERE id = :id', ['s' => $to->value, 'id' => $id]);
            $this->logger->log('rfq', $id, 'status', ['status' => $from->value], ['status' => $to->value]);
        }
    }

    /** Nombre d'articles créés par le dernier enregistrement (message de confirmation). */
    public function createdItems(): int
    {
        return $this->createdItems;
    }

    /**
     * Ligne saisie avec un article inconnu (new_item) : l'article est repris s'il existe déjà sous ce nom
     * (actif, sans tenir compte de la casse), sinon créé dans le référentiel avec un code ART-AAAA-NNNN.
     * Sans le droit de gérer les articles, la ligne reste en texte libre.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function resolveItems(array $lines): array
    {
        $this->createdItems = 0;
        $canCreate = $this->auth->user()?->can('items.manage') ?? false;
        $known = [];
        foreach ($lines as $i => $line) {
            $name = trim((string) ($line['new_item'] ?? ''));
            unset($lines[$i]['new_item']);
            if ($line['item_id'] !== null || $name === '' || !$canCreate) {
                continue;
            }
            $key = mb_strtolower($name);
            $id = $known[$key] ?? $this->db->fetchValue('SELECT id FROM items WHERE is_active = 1 AND LOWER(TRIM(name)) = :n ORDER BY id LIMIT 1', ['n' => $key]);
            if (!$id) {
                $code = $this->sequences->next('item', 'ART');
                $id = $this->db->insert(
                    'INSERT INTO items (code, name, unit, is_active) VALUES (:code, :name, :unit, 1)',
                    ['code' => $code, 'name' => mb_substr($name, 0, 190), 'unit' => $line['unit']],
                );
                $this->logger->log('items', (int) $id, 'created', [], ['code' => $code, 'name' => $name, 'unit' => $line['unit'], 'source' => 'rfq']);
                $this->createdItems++;
            }
            $known[$key] = (int) $id;
            $lines[$i]['item_id'] = (int) $id;
        }
        return array_values($lines);
    }

    /**
     * Pièces jointes du client (demande, cahier des charges, e-mail) : stockées puis rattachées à la RFQ.
     *
     * @param list<array<string, mixed>> $uploads entrées de $_FILES
     */
    private function attach(int $rfqId, array $uploads): void
    {
        foreach ($uploads as $upload) {
            $file = $this->files->store($upload, (array) config('uploads.document_mimes'));
            $this->db->run('INSERT INTO rfq_files (rfq_id, file_id, created_by) VALUES (:r, :f, :u)', ['r' => $rfqId, 'f' => $file['id'], 'u' => $this->auth->user()?->id]);
        }
        if ($uploads !== []) {
            $this->logger->log('rfq', $rfqId, 'files_added', [], ['files' => count($uploads)]);
        }
    }

    /** @param list<array{item_id: ?int, description: string, quantity_milli: int, unit: string, required_by: ?string}> $lines */
    private function insertLines(int $rfqId, array $lines): void
    {
        foreach (array_values($lines) as $i => $line) {
            $this->db->run(
                'INSERT INTO rfq_lines (rfq_id, line_no, item_id, description, quantity_milli, unit, required_by)
                 VALUES (:rfq, :no, :item, :description, :qty, :unit, :required_by)',
                [
                    'rfq' => $rfqId, 'no' => $i + 1, 'item' => $line['item_id'], 'description' => $line['description'],
                    'qty' => $line['quantity_milli'], 'unit' => $line['unit'], 'required_by' => $line['required_by'],
                ],
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $current
     * @param list<array<string, mixed>> $next
     */
    private function sameLines(array $current, array $next): bool
    {
        $normalize = static fn (array $l): string => implode('|', [
            $l['item_id'] ?? '', $l['description'], (int) $l['quantity_milli'], $l['unit'], $l['required_by'] ?? '',
        ]);
        return array_map($normalize, $current) === array_map($normalize, $next);
    }
}
