<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Domain\Shared\Fx;
use App\Repositories\CreditNoteRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\SupplierInvoiceRepository;
use App\Repositories\SupplierPaymentRepository;

/**
 * Pack comptable d'une période : journal des ventes (factures et avoirs), journal des achats,
 * encaissements et décaissements, en un classeur Excel. Pièces annulées et brouillons exclus.
 */
final class AccountingExportController extends Controller
{
    public function __construct(
        private InvoiceRepository $invoices,
        private CreditNoteRepository $creditNotes,
        private PaymentRepository $payments,
        private SupplierInvoiceRepository $supplierInvoices,
        private SupplierPaymentRepository $supplierPayments,
    ) {
    }

    public function index(Request $request): Response
    {
        [$from, $to] = $this->period($request);
        $data = $this->collect($from, $to);
        if ($request->str('format') === 'xlsx') {
            return $this->export($from, $to, $data);
        }
        return $this->view('exports/index', [
            'title' => t('export.title'),
            'from' => $from,
            'to' => $to,
            'counts' => array_map('count', $data),
        ]);
    }

    /** @return array{0: string, 1: string} période saisie, ou le mois précédent par défaut */
    private function period(Request $request): array
    {
        $from = self::date($request->str('from')) ?? date('Y-m-01', strtotime('first day of last month'));
        $to = self::date($request->str('to')) ?? date('Y-m-t', strtotime($from));
        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /** @return array{sales: list<array<string, mixed>>, purchases: list<array<string, mixed>>, receipts: list<array<string, mixed>>, disbursements: list<array<string, mixed>>} */
    private function collect(string $from, string $to): array
    {
        $in = static fn (?string $date): bool => $date !== null && $date >= $from && $date <= $to;
        $sales = [];
        foreach ($this->invoices->list() as $i) {
            if (!in_array($i['status'], ['draft', 'cancelled'], true) && $in($i['issue_date'])) {
                $sales[] = ['date' => $i['issue_date'], 'type' => t('export.type.invoice'), 'reference' => $i['reference'], 'client_code' => $i['client_code'],
                    'client' => $i['client_name'], 'linked' => $i['order_reference'] . ' / ' . $i['client_po_number'], 'due_date' => $i['due_date'],
                    'currency' => $i['currency_code'], 'subtotal' => $i['subtotal'], 'vat' => $i['vat'], 'total' => $i['total']];
            }
        }
        foreach ($this->creditNotes->list() as $n) {
            if ($in($n['issue_date'])) {
                $sales[] = ['date' => $n['issue_date'], 'type' => t('export.type.credit_note'), 'reference' => $n['reference'], 'client_code' => $n['client_code'],
                    'client' => $n['client_name'], 'linked' => $n['invoice_reference'], 'due_date' => null,
                    'currency' => 'USD', 'subtotal' => -(int) $n['subtotal_minor'], 'vat' => -(int) $n['vat_minor'], 'total' => -(int) $n['total_minor']];
            }
        }
        usort($sales, static fn (array $a, array $b): int => [$a['date'], $a['reference']] <=> [$b['date'], $b['reference']]);

        $purchases = array_values(array_filter($this->supplierInvoices->list(), static fn (array $i): bool => $i['status'] !== 'cancelled' && $in($i['invoice_date'])));
        usort($purchases, static fn (array $a, array $b): int => [$a['invoice_date'], $a['reference']] <=> [$b['invoice_date'], $b['reference']]);
        $receipts = array_reverse(array_values(array_filter($this->payments->list(), static fn (array $p): bool => $in($p['received_on']))));
        $disbursements = array_reverse(array_values(array_filter($this->supplierPayments->list(), static fn (array $p): bool => $in($p['paid_on']))));
        return ['sales' => $sales, 'purchases' => $purchases, 'receipts' => $receipts, 'disbursements' => $disbursements];
    }

    /** @param array<string, list<array<string, mixed>>> $data */
    private function export(string $from, string $to, array $data): Response
    {
        $money = static fn (string $key): array => ['label' => t($key), 'type' => Xlsx::MONEY];
        $period = t('export.period', ['from' => format_date($from), 'to' => format_date($to)]);
        $meta = static fn (string $sheet): array => self::exportMeta(t('export.title') . ' — ' . $sheet, $period);
        $book = (new Xlsx())
            ->sheet(t('export.sheet.sales'), [
                ['label' => t('export.col.date'), 'type' => Xlsx::DATE], ['label' => t('export.col.type')], ['label' => t('export.col.reference')],
                ['label' => t('export.col.code')], ['label' => t('export.col.client'), 'width' => 28], ['label' => t('export.col.linked'), 'width' => 26],
                ['label' => t('export.col.due_date'), 'type' => Xlsx::DATE], ['label' => t('export.col.currency')],
                $money('export.col.subtotal'), $money('export.col.vat'), $money('export.col.total'),
            ], array_map(static fn (array $s): array => [
                $s['date'], $s['type'], $s['reference'], $s['client_code'], $s['client'], $s['linked'], $s['due_date'], $s['currency'], $s['subtotal'], $s['vat'], $s['total'],
            ], $data['sales']), $meta(t('export.sheet.sales')))
            ->sheet(t('export.sheet.purchases'), [
                ['label' => t('export.col.date'), 'type' => Xlsx::DATE], ['label' => t('export.col.reference')], ['label' => t('export.col.supplier_invoice_number')],
                ['label' => t('export.col.kind')], ['label' => t('export.col.code')], ['label' => t('export.col.supplier'), 'width' => 28],
                ['label' => t('export.col.linked'), 'width' => 22], ['label' => t('export.col.due_date'), 'type' => Xlsx::DATE], ['label' => t('export.col.currency')],
                ['label' => t('export.col.subtotal'), 'type' => Xlsx::MONEY, 'total' => false], ['label' => t('export.col.vat'), 'type' => Xlsx::MONEY, 'total' => false],
                ['label' => t('export.col.total'), 'type' => Xlsx::MONEY, 'total' => false], ['label' => t('export.col.rate'), 'type' => Xlsx::RATE],
                $money('export.col.subtotal_usd'), $money('export.col.vat_usd'), $money('export.col.total_usd'),
            ], array_map(static function (array $i): array {
                $fx = (int) $i['fx_units_per_usd_micro'];
                $subtotalUsd = Fx::toUsd((int) $i['subtotal_minor'], $fx);
                return [
                    $i['invoice_date'], $i['reference'], $i['supplier_invoice_number'], t($i['kind'] === 'logistics' ? 'logistics.kind' : 'logistics.kind_goods'),
                    $i['supplier_code'], $i['supplier_name'], $i['order_reference'] ?? $i['shipment_references'], $i['due_date'], $i['currency_code'],
                    $i['subtotal_minor'], $i['vat_minor'], $i['total_minor'], $fx, $subtotalUsd, (int) $i['total_usd'] - $subtotalUsd, $i['total_usd'],
                ];
            }, $data['purchases']), $meta(t('export.sheet.purchases')))
            ->sheet(t('export.sheet.receipts'), PaymentController::exportColumns(), array_map(PaymentController::exportRow(...), $data['receipts']), $meta(t('export.sheet.receipts')))
            ->sheet(t('export.sheet.disbursements'), SupplierPaymentController::exportColumns(), array_map(SupplierPaymentController::exportRow(...), $data['disbursements']), $meta(t('export.sheet.disbursements')));
        return Response::download($book->build(), t('export.pack_file', ['from' => $from, 'to' => $to]) . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    private static function date(string $value): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }
}
