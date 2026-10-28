<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Orders\ClientOrderStatus;
use App\Domain\Orders\Coverage;
use App\Domain\Sales\QuotationStatus;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ClientOrderRepository;
use App\Repositories\QuotationRepository;
use App\Repositories\RfqRepository;
use App\Security\Auth;
use PDOException;

final class ClientOrderService
{
    private const HEADER = ['client_po_number', 'received_on', 'required_by', 'delivery_site', 'incoterm', 'payment_terms_days', 'vat_applicable', 'notes'];

    public function __construct(
        private Database $db,
        private ClientOrderRepository $orders,
        private QuotationRepository $quotations,
        private RfqRepository $rfqs,
        private QuotationService $quotationService,
        private Sequences $sequences,
        private ActivityLogger $logger,
        private Auth $auth,
        private FileStorage $files,
    ) {
    }

    /**
     * PO client reçu sur une quotation envoyée ou acceptée (une quotation envoyée passe en « acceptée »).
     * Quantités et prix peuvent différer de la quotation (commande partielle, prix renégocié).
     *
     * @param array<string, mixed> $header
     * @param array<int, array{quantity_milli: int, unit_price_minor: int, description: string}> $lines id de ligne de quotation → valeurs (lignes absentes = non commandées)
     */
    public function createFromQuotation(int $quotationId, array $header, array $lines, array $uploads = []): int
    {
        try {
            return $this->db->transaction(function () use ($quotationId, $header, $lines, $uploads): int {
                $quotation = $this->quotations->get($quotationId);
                $status = QuotationStatus::from((string) $quotation['status']);
                if ($status === QuotationStatus::Sent) {
                    $this->quotationService->transition($quotationId, 'accept');
                } elseif ($status !== QuotationStatus::Accepted) {
                    throw new RuleViolation('client_orders.error_quotation_status');
                }
                if ($lines === []) {
                    throw new RuleViolation('client_orders.error_no_lines');
                }
                $header = array_intersect_key($header, array_flip(self::HEADER));
                $reference = $this->sequences->next('client_order', 'CMD');
                $id = $this->db->insert(
                    'INSERT INTO client_orders (reference, client_po_number, client_id, rfq_id, quotation_id, received_on, required_by, delivery_site,
                                                incoterm, payment_terms_days, vat_applicable, vat_rate_bp, notes, created_by)
                     VALUES (:reference, :client_po_number, :client_id, :rfq_id, :quotation_id, :received_on, :required_by, :delivery_site,
                             :incoterm, :payment_terms_days, :vat_applicable, :vat_rate_bp, :notes, :created_by)',
                    $header + [
                        'reference' => $reference, 'client_id' => (int) $quotation['client_id'], 'rfq_id' => (int) $quotation['rfq_id'],
                        'quotation_id' => $quotationId, 'vat_rate_bp' => (int) $quotation['vat_rate_bp'], 'created_by' => $this->auth->user()?->id,
                    ],
                );
                $no = 0;
                foreach ($this->quotations->lines($quotationId) as $ql) {
                    $input = $lines[(int) $ql['id']] ?? null;
                    if ($input === null) {
                        continue;
                    }
                    $this->db->run(
                        'INSERT INTO client_order_lines (client_order_id, line_no, quotation_line_id, rfq_line_id, supplier_quote_line_id, description,
                                                        quantity_milli, unit, unit_price_minor, est_unit_cost_minor, est_extra_unit_minor)
                         VALUES (:order, :no, :ql, :rl, :sql, :description, :qty, :unit, :price, :cost, :extra)',
                        [
                            'order' => $id, 'no' => ++$no, 'ql' => (int) $ql['id'], 'rl' => $ql['rfq_line_id'], 'sql' => $ql['supplier_quote_line_id'],
                            'description' => $input['description'], 'qty' => $input['quantity_milli'], 'unit' => $ql['unit'],
                            'price' => $input['unit_price_minor'], 'cost' => (int) $ql['cost_unit_usd_minor'], 'extra' => (int) $ql['extra_unit_usd_minor'],
                        ],
                    );
                }
                $this->logger->log('client_order', $id, 'created', [], $header + ['reference' => $reference, 'quotation_id' => $quotationId, 'lines' => $no]);
                $this->attach($id, $uploads);
                return $id;
            });
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_client_orders_po')) {
                throw new RuleViolation('client_orders.error_duplicate_po');
            }
            throw $e;
        }
    }

    /**
     * En-tête modifiable tant que le PO n'est pas clos ; lignes modifiables tant qu'aucun PO fournisseur ne les couvre.
     *
     * @param array<string, mixed> $header
     * @param array<int, array{quantity_milli: int, unit_price_minor: int, description: string}> $lines id de ligne → valeurs
     */
    public function update(int $id, array $header, array $lines, array $uploads = []): void
    {
        try {
            $this->db->transaction(function () use ($id, $header, $lines, $uploads): void {
                $this->attach($id, $uploads);
                $order = $this->orders->get($id);
                if (ClientOrderStatus::from((string) $order['status'])->isFinal()) {
                    throw new RuleViolation('client_orders.error_final');
                }
                $header = array_intersect_key($header, array_flip(self::HEADER));
                $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($header)));
                $this->db->run("UPDATE client_orders SET {$set} WHERE id = :id", $header + ['id' => $id]);
                $changed = false;
                foreach ($this->orders->lines($id) as $line) {
                    $input = $lines[(int) $line['id']] ?? null;
                    if ($input === null || ((int) $input['quantity_milli'] === (int) $line['quantity_milli']
                        && (int) $input['unit_price_minor'] === (int) $line['unit_price_minor'] && $input['description'] === $line['description'])) {
                        continue;
                    }
                    if ((int) $input['quantity_milli'] < (int) $line['allocated_milli']) {
                        throw new RuleViolation('client_orders.error_below_allocated', ['n' => (int) $line['line_no']]);
                    }
                    $this->db->run(
                        'UPDATE client_order_lines SET description = :d, quantity_milli = :q, unit_price_minor = :p WHERE id = :id',
                        ['d' => $input['description'], 'q' => $input['quantity_milli'], 'p' => $input['unit_price_minor'], 'id' => (int) $line['id']],
                    );
                    $changed = true;
                }
                $this->logger->log('client_order', $id, 'updated', array_intersect_key($order, $header), $header + ($changed ? ['lines' => 'updated'] : []));
                $this->refreshStatus($id);
            });
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_client_orders_po')) {
                throw new RuleViolation('client_orders.error_duplicate_po');
            }
            throw $e;
        }
    }

    /** Statut suivant la couverture par les PO fournisseurs (received → ordering → ordered). */
    public function refreshStatus(int $id): void
    {
        $order = $this->orders->get($id);
        $current = ClientOrderStatus::from((string) $order['status']);
        if (!$current->followsCoverage()) {
            return;
        }
        $lines = $this->orders->lines($id);
        $ordered = [];
        $allocated = [];
        $delivered = [];
        foreach ($lines as $l) {
            $ordered[(int) $l['id']] = (int) $l['quantity_milli'];
            $allocated[(int) $l['id']] = (int) $l['allocated_milli'];
            $delivered[(int) $l['id']] = (int) $l['delivered_milli'];
        }
        $next = ClientOrderStatus::derive(
            Coverage::status(Coverage::lines($ordered, $allocated)),
            Coverage::status(Coverage::lines($ordered, $delivered)),
        );
        if ($next !== $current) {
            $this->db->run('UPDATE client_orders SET status = :s WHERE id = :id', ['s' => $next->value, 'id' => $id]);
            $this->logger->log('client_order', $id, 'status', ['status' => $current->value], ['status' => $next->value]);
        }
    }

    /** Clôture manuelle (affaire terminée) ou annulation (aucun PO fournisseur actif). */
    public function finish(int $id, ClientOrderStatus $to, ?string $reason): void
    {
        $this->db->transaction(function () use ($id, $to, $reason): void {
            $order = $this->orders->get($id);
            $from = ClientOrderStatus::from((string) $order['status']);
            if ($from->isFinal() || !in_array($to, [ClientOrderStatus::Closed, ClientOrderStatus::Cancelled], true)) {
                throw new RuleViolation('common.error_transition');
            }
            if ($to === ClientOrderStatus::Cancelled && $this->orders->hasSupplierOrders($id)) {
                throw new RuleViolation('client_orders.error_cancel_with_po');
            }
            $this->db->run('UPDATE client_orders SET status = :s, closed_reason = :r WHERE id = :id', ['s' => $to->value, 'r' => $reason, 'id' => $id]);
            $this->logger->log('client_order', $id, 'status', ['status' => $from->value], ['status' => $to->value, 'closed_reason' => $reason]);
        });
    }

    /**
     * Bon de commande du client (PDF, scan, e-mail) : stocké puis rattaché au PO client.
     *
     * @param list<array<string, mixed>> $uploads entrées de $_FILES
     */
    private function attach(int $orderId, array $uploads): void
    {
        foreach ($uploads as $upload) {
            $file = $this->files->store($upload, (array) config('uploads.document_mimes'));
            $this->db->run('INSERT INTO client_order_files (client_order_id, file_id, created_by) VALUES (:o, :f, :u)', ['o' => $orderId, 'f' => $file['id'], 'u' => $this->auth->user()?->id]);
        }
        if ($uploads !== []) {
            $this->logger->log('client_order', $orderId, 'files_added', [], ['files' => count($uploads)]);
        }
    }
}
