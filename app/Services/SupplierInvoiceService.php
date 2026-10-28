<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Finance\LandedCost;
use App\Domain\Orders\SupplierOrderStatus;
use App\Domain\Purchasing\SupplierInvoiceStatus as S;
use App\Domain\Purchasing\ThreeWayMatch;
use App\Domain\Shared\Money;
use App\Domain\Shared\RuleViolation;
use App\Repositories\SupplierInvoiceRepository;
use App\Repositories\SupplierOrderRepository;
use App\Repositories\SupplierRepository;
use App\Security\Auth;

/**
 * Factures fournisseurs : saisies telles que reçues, rapprochées du PO et de la réception (POD).
 * Aucun écart → approuvée (bon à payer) ; écart → en attente, validation motivée par un responsable.
 */
final class SupplierInvoiceService
{
    public function __construct(
        private Database $db,
        private SupplierInvoiceRepository $invoices,
        private SupplierOrderRepository $orders,
        private SupplierRepository $suppliers,
        private Settings $settings,
        private FileStorage $files,
        private Sequences $sequences,
        private ActivityLogger $logger,
        private Auth $auth,
    ) {
    }

    /**
     * @param array{supplier_invoice_number: string, invoice_date: string, fx_units_per_usd_micro: int, vat_minor: int, notes: ?string} $header
     * @param array<int, array{quantity: int, price: int}> $lines ligne de PO → quantité (millièmes) et prix unitaire facturés
     * @param array<string, mixed>|null $upload facture scannée ($_FILES), facultative
     * @return array{id: int, status: S}
     */
    public function record(int $supplierOrderId, array $header, array $lines, ?array $upload): array
    {
        $stored = null;
        try {
            return $this->db->transaction(function () use ($supplierOrderId, $header, $lines, $upload, &$stored): array {
                $order = $this->orders->get($supplierOrderId);
                $orderStatus = SupplierOrderStatus::from((string) $order['status']);
                if ($orderStatus === SupplierOrderStatus::Draft || $orderStatus === SupplierOrderStatus::Cancelled) {
                    throw new RuleViolation('supplier_invoices.error_order_status');
                }
                if ($this->invoices->numberExists((int) $order['supplier_id'], $header['supplier_invoice_number'])) {
                    throw new RuleViolation('supplier_invoices.error_duplicate', ['number' => $header['supplier_invoice_number']]);
                }
                $poLines = array_column($this->invoices->matchLines($supplierOrderId), null, 'id');
                $lines = array_filter($lines, static fn (array $l): bool => $l['quantity'] > 0);
                if ($lines === []) {
                    throw new RuleViolation('supplier_invoices.error_no_lines');
                }
                $subtotal = 0;
                $matchInput = [];
                foreach ($lines as $lineId => $l) {
                    $po = $poLines[$lineId] ?? throw new RuleViolation('supplier_invoices.error_no_lines');
                    $subtotal += Money::lineAmount($l['quantity'], $l['price']);
                    $matchInput[] = [
                        'line_no' => (int) $po['line_no'], 'ordered_milli' => (int) $po['ordered_milli'], 'received_milli' => (int) $po['received_milli'],
                        'invoiced_before_milli' => (int) $po['invoiced_milli'], 'quantity_milli' => $l['quantity'],
                        'po_price_minor' => (int) $po['po_price_minor'], 'price_minor' => $l['price'],
                    ];
                }
                $variances = ThreeWayMatch::check($matchInput, $this->settings->int('supplier_price_tolerance_bp'));
                $status = $variances === [] ? S::Approved : S::OnHold;
                if ($upload !== null) {
                    $stored = $this->files->store($upload);
                }
                $reference = $this->sequences->next('supplier_invoice', 'FF');
                $due = date('Y-m-d', strtotime($header['invoice_date'] . ' +' . (int) $order['payment_terms_days'] . ' days'));
                $id = $this->db->insert(
                    'INSERT INTO supplier_invoices (reference, supplier_id, supplier_order_id, supplier_invoice_number, status, invoice_date, due_date,
                            currency_code, fx_units_per_usd_micro, subtotal_minor, vat_minor, total_minor, variances, approved_at, file_id, notes, created_by)
                     VALUES (:ref, :supplier, :order, :number, :status, :date, :due, :cur, :fx, :sub, :vat, :total, :var, :approved, :file, :notes, :user)',
                    [
                        'ref' => $reference, 'supplier' => (int) $order['supplier_id'], 'order' => $supplierOrderId,
                        'number' => $header['supplier_invoice_number'], 'status' => $status->value, 'date' => $header['invoice_date'], 'due' => $due,
                        'cur' => $order['currency_code'], 'fx' => $header['fx_units_per_usd_micro'], 'sub' => $subtotal, 'vat' => $header['vat_minor'],
                        'total' => $subtotal + $header['vat_minor'], 'var' => $variances === [] ? null : json_encode($variances),
                        'approved' => $status === S::Approved ? date('Y-m-d H:i:s') : null, 'file' => $stored['id'] ?? null,
                        'notes' => $header['notes'], 'user' => $this->auth->user()?->id,
                    ],
                );
                $no = 0;
                foreach ($lines as $lineId => $l) {
                    $po = $poLines[$lineId];
                    $this->db->run(
                        'INSERT INTO supplier_invoice_lines (invoice_id, line_no, supplier_order_line_id, description, quantity_milli, unit, unit_price_minor, po_unit_price_minor)
                         VALUES (:i, :no, :line, :d, :q, :u, :p, :po)',
                        ['i' => $id, 'no' => ++$no, 'line' => $lineId, 'd' => $po['description'], 'q' => $l['quantity'], 'u' => $po['unit'], 'p' => $l['price'], 'po' => (int) $po['po_price_minor']],
                    );
                }
                $this->logger->log('supplier_invoice', $id, 'created', [], [
                    'status' => $status->value, 'reference' => $reference, 'supplier_invoice_number' => $header['supplier_invoice_number'],
                    'total_minor' => $subtotal + $header['vat_minor'], 'variances' => count($variances),
                ]);
                return ['id' => $id, 'status' => $status];
            });
        } catch (\Throwable $e) {
            if ($stored !== null && is_file($stored['path'])) {
                @unlink($stored['path']);
            }
            throw self::duplicate($e, $header['supplier_invoice_number']);
        }
    }

    /**
     * Facture de frais logistiques (transitaire, douane, frontière…) : pas de PO à rapprocher, bon à payer d'office.
     * Chaque ligne porte sur une expédition ; ses frais seront répartis sur les lignes livrées pour la marge réelle.
     *
     * @param array{supplier_invoice_number: string, invoice_date: string, currency_code: string, fx_units_per_usd_micro: int, vat_minor: int, notes: ?string} $header
     * @param list<array{shipment_id: int, cost_type: string, description: ?string, amount: int}> $lines
     * @param array<string, mixed>|null $upload
     */
    public function recordLogistics(int $supplierId, array $header, array $lines, ?array $upload): int
    {
        $stored = null;
        try {
            return $this->db->transaction(function () use ($supplierId, $header, $lines, $upload, &$stored): int {
                $supplier = $this->suppliers->get($supplierId);
                if ($this->invoices->numberExists($supplierId, $header['supplier_invoice_number'])) {
                    throw new RuleViolation('supplier_invoices.error_duplicate', ['number' => $header['supplier_invoice_number']]);
                }
                $lines = array_values(array_filter($lines, static fn (array $l): bool => $l['amount'] > 0));
                if ($lines === []) {
                    throw new RuleViolation('supplier_invoices.error_no_lines');
                }
                foreach ($lines as $l) {
                    $status = $this->db->fetchValue('SELECT status FROM shipments WHERE id = :id', ['id' => $l['shipment_id']]);
                    if ($status === null || $status === false || $status === 'cancelled' || !in_array($l['cost_type'], LandedCost::TYPES, true)) {
                        throw new RuleViolation('logistics.error_line');
                    }
                }
                $subtotal = array_sum(array_column($lines, 'amount'));
                if ($upload !== null) {
                    $stored = $this->files->store($upload);
                }
                $reference = $this->sequences->next('supplier_invoice', 'FF');
                $due = date('Y-m-d', strtotime($header['invoice_date'] . ' +' . (int) $supplier['payment_terms_days'] . ' days'));
                $id = $this->db->insert(
                    "INSERT INTO supplier_invoices (reference, kind, supplier_id, supplier_order_id, supplier_invoice_number, status, invoice_date, due_date,
                            currency_code, fx_units_per_usd_micro, subtotal_minor, vat_minor, total_minor, approved_at, file_id, notes, created_by)
                     VALUES (:ref, 'logistics', :supplier, NULL, :number, 'approved', :date, :due, :cur, :fx, :sub, :vat, :total, NOW(), :file, :notes, :user)",
                    [
                        'ref' => $reference, 'supplier' => $supplierId, 'number' => $header['supplier_invoice_number'], 'date' => $header['invoice_date'],
                        'due' => $due, 'cur' => $header['currency_code'], 'fx' => $header['fx_units_per_usd_micro'], 'sub' => $subtotal,
                        'vat' => $header['vat_minor'], 'total' => $subtotal + $header['vat_minor'], 'file' => $stored['id'] ?? null,
                        'notes' => $header['notes'], 'user' => $this->auth->user()?->id,
                    ],
                );
                foreach ($lines as $no => $l) {
                    $this->db->run(
                        'INSERT INTO supplier_invoice_cost_lines (invoice_id, line_no, shipment_id, cost_type, description, amount_minor)
                         VALUES (:i, :no, :sh, :type, :d, :a)',
                        ['i' => $id, 'no' => $no + 1, 'sh' => $l['shipment_id'], 'type' => $l['cost_type'], 'd' => $l['description'], 'a' => $l['amount']],
                    );
                }
                $this->logger->log('supplier_invoice', $id, 'created', [], [
                    'status' => S::Approved->value, 'kind' => 'logistics', 'reference' => $reference,
                    'supplier_invoice_number' => $header['supplier_invoice_number'], 'total_minor' => $subtotal + $header['vat_minor'],
                ]);
                return $id;
            });
        } catch (\Throwable $e) {
            if ($stored !== null && is_file($stored['path'])) {
                @unlink($stored['path']);
            }
            throw self::duplicate($e, $header['supplier_invoice_number']);
        }
    }

    /** Nouveau rapprochement d'une facture en attente (ex. POD arrivée depuis) : conforme → approuvée. */
    public function recheck(int $id): S
    {
        return $this->db->transaction(function () use ($id): S {
            $invoice = $this->invoices->get($id);
            if ((string) $invoice['status'] !== S::OnHold->value) {
                throw new RuleViolation('supplier_invoices.error_not_on_hold');
            }
            $variances = $this->variancesFor($invoice);
            $status = $variances === [] ? S::Approved : S::OnHold;
            $this->db->run(
                'UPDATE supplier_invoices SET variances = :v, status = :s, approved_at = IF(:approved = 1, NOW(), approved_at) WHERE id = :id',
                ['v' => $variances === [] ? null : json_encode($variances), 's' => $status->value, 'approved' => $status === S::Approved ? 1 : 0, 'id' => $id],
            );
            $this->logger->log('supplier_invoice', $id, 'recheck', ['status' => S::OnHold->value, 'variances' => count($invoice['variance_list'])], ['status' => $status->value, 'variances' => count($variances)]);
            return $status;
        });
    }

    /** Validation d'une facture en écart (bon à payer malgré l'écart), motif obligatoire. */
    public function approve(int $id, string $note): void
    {
        $this->db->transaction(function () use ($id, $note): void {
            $invoice = $this->invoices->get($id);
            if ((string) $invoice['status'] !== S::OnHold->value) {
                throw new RuleViolation('supplier_invoices.error_not_on_hold');
            }
            $this->db->run(
                "UPDATE supplier_invoices SET status = 'approved', approved_by = :u, approved_at = NOW(), approval_note = :n WHERE id = :id",
                ['u' => $this->auth->user()?->id, 'n' => $note, 'id' => $id],
            );
            $this->logger->log('supplier_invoice', $id, 'approve', ['status' => S::OnHold->value], ['status' => S::Approved->value, 'note' => $note]);
        });
    }

    /** Annulation (facture contestée, doublon, avoir total du fournisseur) : ses quantités redeviennent facturables. */
    public function cancel(int $id, string $reason): void
    {
        $this->db->transaction(function () use ($id, $reason): void {
            $invoice = $this->invoices->get($id);
            $status = S::from((string) $invoice['status']);
            if (!$status->canCancel() || (int) $invoice['paid'] > 0) {
                throw new RuleViolation('supplier_invoices.error_cancel');
            }
            $this->db->run(
                "UPDATE supplier_invoices SET status = 'cancelled', cancelled_at = NOW(), cancelled_reason = :r WHERE id = :id",
                ['r' => $reason, 'id' => $id],
            );
            $this->logger->log('supplier_invoice', $id, 'cancel', ['status' => $status->value], ['status' => S::Cancelled->value, 'reason' => $reason]);
        });
    }

    /**
     * @param array<string, mixed> $invoice
     * @return list<array{type: string, line_no: int, expected: int, actual: int}>
     */
    private function variancesFor(array $invoice): array
    {
        $poLines = array_column($this->invoices->matchLines((int) $invoice['supplier_order_id'], (int) $invoice['id']), null, 'id');
        $input = [];
        foreach ($this->invoices->lines((int) $invoice['id']) as $l) {
            $po = $poLines[(int) $l['supplier_order_line_id']];
            $input[] = [
                'line_no' => (int) $po['line_no'], 'ordered_milli' => (int) $po['ordered_milli'], 'received_milli' => (int) $po['received_milli'],
                'invoiced_before_milli' => (int) $po['invoiced_milli'], 'quantity_milli' => (int) $l['quantity_milli'],
                'po_price_minor' => (int) $l['po_unit_price_minor'], 'price_minor' => (int) $l['unit_price_minor'],
            ];
        }
        return ThreeWayMatch::check($input, $this->settings->int('supplier_price_tolerance_bp'));
    }

    /** Saisie simultanée du même numéro : la contrainte unique tranche, l'utilisateur voit le message métier. */
    private static function duplicate(\Throwable $e, string $number): \Throwable
    {
        return $e instanceof \PDOException && $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_supplier_invoices_number')
            ? new RuleViolation('supplier_invoices.error_duplicate', ['number' => $number])
            : $e;
    }
}
