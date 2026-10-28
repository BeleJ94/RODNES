<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Sales\RfqStatus;
use App\Domain\Shared\RuleViolation;
use App\Repositories\RfqRepository;
use App\Repositories\SupplierQuoteRepository;
use App\Repositories\SupplierRepository;
use App\Security\Auth;
use PDOException;

/** Sourcing : demandes de prix aux fournisseurs et saisie de leurs offres. */
final class SourcingService
{
    public function __construct(
        private Database $db,
        private RfqRepository $rfqs,
        private SupplierRepository $suppliers,
        private SupplierQuoteRepository $quotes,
        private RfqService $rfqService,
        private ActivityLogger $logger,
        private Auth $auth,
        private Sequences $sequences,
        private FileStorage $files,
    ) {
    }

    /**
     * Demande de prix à chaque fournisseur : document DP-AAAA-NNNN avec les lignes et quantités choisies
     * (toutes les lignes de la RFQ, à ses quantités, si rien n'est précisé), une date de réponse et un message.
     *
     * @param list<int> $supplierIds
     * @param array<int, int>|null $lines id de ligne de RFQ → quantité en millièmes
     */
    public function request(int $rfqId, array $supplierIds, ?array $lines = null, ?string $responseDue = null, ?string $message = null): int
    {
        return $this->db->transaction(function () use ($rfqId, $supplierIds, $lines, $responseDue, $message): int {
            $rfq = $this->rfqs->get($rfqId);
            if (RfqStatus::from((string) $rfq['status'])->isClosed()) {
                throw new RuleViolation('rfqs.error_closed');
            }
            $rfqLines = array_column($this->rfqs->lines($rfqId), 'quantity_milli', 'id');
            $lines ??= array_map('intval', $rfqLines);
            foreach ($lines as $lineId => $qty) {
                if (!isset($rfqLines[$lineId]) || $qty <= 0) {
                    throw new RuleViolation('sourcing.error_lines');
                }
            }
            if ($lines === []) {
                throw new RuleViolation('sourcing.error_no_line');
            }
            $created = 0;
            foreach (array_unique($supplierIds) as $supplierId) {
                $supplier = $this->suppliers->get($supplierId);
                try {
                    $id = $this->db->insert(
                        'INSERT INTO supplier_quotes (reference, rfq_id, supplier_id, requested_on, response_due, currency_code, message, created_by)
                         VALUES (:ref, :rfq, :supplier, CURDATE(), :due, :currency, :message, :user)',
                        ['ref' => $this->sequences->next('supplier_rfq', 'DP'), 'rfq' => $rfqId, 'supplier' => $supplierId, 'due' => $responseDue,
                            'currency' => $supplier['currency_code'], 'message' => $message, 'user' => $this->auth->user()?->id],
                    );
                } catch (PDOException $e) {
                    if ((string) $e->getCode() === '23000') {
                        continue;
                    }
                    throw $e;
                }
                foreach ($lines as $lineId => $qty) {
                    $this->db->run('INSERT INTO supplier_quote_requested_lines (supplier_quote_id, rfq_line_id, quantity_milli) VALUES (:q, :l, :n)', ['q' => $id, 'l' => $lineId, 'n' => $qty]);
                }
                $this->logger->log('supplier_quote', $id, 'requested', [], ['rfq_id' => $rfqId, 'supplier_id' => $supplierId, 'lines' => count($lines), 'response_due' => $responseDue]);
                $created++;
            }
            if ($created > 0) {
                $this->rfqService->advance($rfqId, RfqStatus::Sourcing);
            }
            return $created;
        });
    }

    /**
     * Saisie (ou correction) de l'offre reçue. Une ligne sans prix = article non proposé par ce fournisseur.
     *
     * @param array{supplier_reference: ?string, received_on: string, currency_code: string, incoterm: ?string, lead_time_days: ?int, valid_until: ?string, notes: ?string} $header
     * @param array<int, array{unit_price_minor: int, lead_time_days: ?int, note: ?string}|null> $lines rfq_line_id → prix
     */
    public function recordOffer(int $supplierQuoteId, array $header, array $lines, array $uploads = []): void
    {
        $this->db->transaction(function () use ($supplierQuoteId, $header, $lines, $uploads): void {
            $before = $this->quotes->get($supplierQuoteId);
            $validLineIds = array_map('intval', array_column($this->rfqs->lines((int) $before['rfq_id']), 'id'));
            $this->db->run(
                "UPDATE supplier_quotes SET status = 'received', supplier_reference = :ref, received_on = :received, currency_code = :currency,
                        incoterm = :incoterm, lead_time_days = :lead, valid_until = :valid, notes = :notes
                  WHERE id = :id",
                [
                    'ref' => $header['supplier_reference'], 'received' => $header['received_on'], 'currency' => $header['currency_code'],
                    'incoterm' => $header['incoterm'], 'lead' => $header['lead_time_days'], 'valid' => $header['valid_until'],
                    'notes' => $header['notes'], 'id' => $supplierQuoteId,
                ],
            );
            foreach ($lines as $rfqLineId => $line) {
                if (!in_array($rfqLineId, $validLineIds, true)) {
                    continue;
                }
                if ($line === null) {
                    // Une ligne déjà reprise dans une quotation reste (la traçabilité prime) ; sinon elle disparaît.
                    $this->db->run(
                        'DELETE l FROM supplier_quote_lines l
                          WHERE l.supplier_quote_id = :q AND l.rfq_line_id = :line
                            AND NOT EXISTS (SELECT 1 FROM quotation_lines ql WHERE ql.supplier_quote_line_id = l.id)',
                        ['q' => $supplierQuoteId, 'line' => $rfqLineId],
                    );
                    continue;
                }
                $this->db->run(
                    'INSERT INTO supplier_quote_lines (supplier_quote_id, rfq_line_id, unit_price_minor, lead_time_days, note)
                     VALUES (:q, :line, :price, :lead, :note)
                     ON DUPLICATE KEY UPDATE unit_price_minor = VALUES(unit_price_minor), lead_time_days = VALUES(lead_time_days), note = VALUES(note)',
                    ['q' => $supplierQuoteId, 'line' => $rfqLineId, 'price' => $line['unit_price_minor'], 'lead' => $line['lead_time_days'], 'note' => $line['note']],
                );
            }
            // Fichiers de l'offre : stockés et rattachés dans la même transaction.
            foreach ($uploads as $upload) {
                $file = $this->files->store($upload, (array) config('uploads.document_mimes'));
                $this->db->run('INSERT INTO supplier_quote_files (supplier_quote_id, file_id, created_by) VALUES (:q, :f, :u)', ['q' => $supplierQuoteId, 'f' => $file['id'], 'u' => $this->auth->user()?->id]);
            }
            $this->logger->log('supplier_quote', $supplierQuoteId, 'offer_recorded', array_intersect_key($before, $header), $header + ['lines' => count(array_filter($lines)), 'files' => count($uploads)]);
        });
    }

    public function decline(int $supplierQuoteId): void
    {
        $this->db->transaction(function () use ($supplierQuoteId): void {
            $before = $this->quotes->get($supplierQuoteId);
            if ($before['status'] !== 'requested') {
                throw new RuleViolation('common.error_transition');
            }
            $this->db->run("UPDATE supplier_quotes SET status = 'declined' WHERE id = :id", ['id' => $supplierQuoteId]);
            $this->logger->log('supplier_quote', $supplierQuoteId, 'declined', ['status' => 'requested'], ['status' => 'declined']);
        });
    }
}
