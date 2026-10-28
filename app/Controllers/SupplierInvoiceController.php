<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Core\Validator;
use App\Domain\Purchasing\SupplierInvoiceStatus;
use App\Domain\Shared\RuleViolation;
use App\Repositories\CurrencyRepository;
use App\Repositories\ShipmentRepository;
use App\Repositories\SupplierInvoiceRepository;
use App\Repositories\SupplierOrderRepository;
use App\Repositories\SupplierPaymentRepository;
use App\Repositories\SupplierRepository;
use App\Services\ActivityLogger;
use App\Services\SupplierInvoiceService;

final class SupplierInvoiceController extends Controller
{
    public function __construct(
        private SupplierInvoiceRepository $invoices,
        private SupplierInvoiceService $service,
        private SupplierOrderRepository $orders,
        private SupplierRepository $suppliers,
        private ActivityLogger $logger,
        private SupplierPaymentRepository $payments,
        private CurrencyRepository $currencies,
        private ShipmentRepository $shipments,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($request->str('format') === 'xlsx') {
            return $this->xlsx(t('nav.supplier_invoices'), self::exportColumns(), array_map(self::exportRow(...), $this->invoices->list(['status' => $request->str('status'), 'supplier_id' => $request->int('supplier_id'), 'kind' => $request->str('kind')])));
        }
        $status = $request->str('status');
        $supplierId = $request->int('supplier_id');
        return $this->view('supplier_invoices/index', [
            'title' => t('supplier_invoices.title'),
            'rows' => $this->invoices->list(['status' => $status, 'supplier_id' => $supplierId, 'kind' => $request->str('kind')]),
            'kind' => $request->str('kind'),
            'status' => $status,
            'supplierId' => $supplierId,
            'suppliers' => $this->suppliers->options(),
            'kpis' => $this->invoices->kpis(),
        ]);
    }

    public function create(Request $request, string $orderId): Response
    {
        $data = $this->formData((int) $orderId);
        if ($request->str('fragment') === '1') {
            return $this->view('supplier_invoices/_form', $data + ['modal' => true], null)->withHeader('Cache-Control', 'no-store');
        }
        return $this->view('supplier_invoices/form', $data + ['title' => t('supplier_invoices.new_title')]);
    }

    /**
     * Données de la saisie, partagées par la page, la fenêtre et la fiche PO (fenêtre rouverte après erreur).
     *
     * @return array{order: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public function formData(int $orderId): array
    {
        return ['order' => $this->orders->get($orderId), 'lines' => $this->invoices->matchLines($orderId)];
    }

    public function store(Request $request, string $orderId): Response
    {
        // Erreur : retour à la fiche PO, fenêtre de saisie rouverte avec les valeurs saisies.
        $back = '/supplier-orders/' . $orderId . '?invoice=1';
        $validator = Validator::make($request->body(), [
            'supplier_invoice_number' => 'required|max:64',
            'invoice_date' => 'required|date',
            'fx_rate' => 'required|rate',
            'vat' => 'nullable|money',
            'notes' => 'nullable|max:2000',
        ]);
        [$lines, $errors] = $this->lines($request);
        $errors = $validator->errors() + $errors;
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        $d = $validator->validated();
        $upload = $request->file('invoice_file');
        try {
            $result = $this->service->record((int) $orderId, [
                'supplier_invoice_number' => trim($d['supplier_invoice_number']), 'invoice_date' => $d['invoice_date'],
                'fx_units_per_usd_micro' => $d['fx_rate'], 'vat_minor' => $d['vat'] ?? 0, 'notes' => $d['notes'],
            ], $lines, $upload);
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        $approved = $result['status'] === SupplierInvoiceStatus::Approved;
        return $this->redirect('/supplier-invoices/' . $result['id'], $approved ? 'supplier_invoices.flash_matched' : 'supplier_invoices.flash_on_hold', $approved ? 'success' : 'info');
    }

    public function createLogistics(Request $request): Response
    {
        return $this->view('supplier_invoices/logistics_form', [
            'title' => t('logistics.new_title'),
            'suppliers' => $this->suppliers->options(),
            'currencies' => $this->currencies->activeCodes(),
            'rates' => array_map(static fn (array $r): int => $r['rate'], $this->currencies->latestRates()),
            'shipments' => array_values(array_filter($this->shipments->list(), static fn (array $s): bool => $s['status'] !== 'cancelled')),
            'shipmentId' => $request->int('shipment_id'),
        ]);
    }

    public function storeLogistics(Request $request): Response
    {
        $back = '/supplier-invoices/logistics/new';
        $validator = Validator::make($request->body(), [
            'supplier_id' => 'required|int',
            'supplier_invoice_number' => 'required|max:64',
            'invoice_date' => 'required|date',
            'currency' => 'required|currency',
            'fx_rate' => 'required|rate',
            'vat' => 'nullable|money',
            'notes' => 'nullable|max:2000',
        ]);
        $errors = $validator->errors();
        $d = $validator->validated();
        if ($validator->passes() && !in_array($d['currency'], $this->currencies->activeCodes(), true)) {
            $errors['currency'] = t('validation.currency');
        }
        if ($validator->passes() && $d['currency'] === \App\Domain\Shared\Fx::USD && $d['fx_rate'] !== \App\Domain\Shared\Fx::ONE) {
            $errors['fx_rate'] = t('validation.rate');
        }
        $lines = [];
        foreach ($request->rows('costs') as $row) {
            if (($row['amount'] ?? '') === '') {
                continue;
            }
            $v = Validator::make($row, ['shipment_id' => 'required|int', 'cost_type' => 'required|in:' . implode(',', \App\Domain\Finance\LandedCost::TYPES), 'amount' => 'required|money', 'description' => 'nullable|max:255']);
            if (!$v->passes()) {
                $errors['costs'] = t('logistics.error_line');
                continue;
            }
            $r = $v->validated();
            $lines[] = ['shipment_id' => (int) $r['shipment_id'], 'cost_type' => $r['cost_type'], 'description' => $r['description'], 'amount' => $r['amount']];
        }
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        try {
            $id = $this->service->recordLogistics((int) $d['supplier_id'], [
                'supplier_invoice_number' => trim($d['supplier_invoice_number']), 'invoice_date' => $d['invoice_date'], 'currency_code' => $d['currency'],
                'fx_units_per_usd_micro' => $d['fx_rate'], 'vat_minor' => $d['vat'] ?? 0, 'notes' => $d['notes'],
            ], $lines, $request->file('invoice_file'));
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        return $this->redirect('/supplier-invoices/' . $id, 'logistics.flash_created');
    }

    public function show(Request $request, string $id): Response
    {
        $invoice = $this->invoices->get((int) $id);
        return $this->view('supplier_invoices/show', [
            'title' => (string) $invoice['reference'],
            'invoice' => $invoice,
            'status' => SupplierInvoiceStatus::from((string) $invoice['status']),
            'lines' => $this->invoices->lines((int) $id),
            'match' => array_column($this->invoices->matchLines((int) $invoice['supplier_order_id']), null, 'id'),
            'payments' => $this->payments->forInvoice((int) $id),
            'costLines' => $invoice['kind'] === 'logistics' ? $this->invoices->costLines((int) $id) : [],
            'history' => $this->logger->history('supplier_invoice', (int) $id),
        ]);
    }

    public function recheck(Request $request, string $id): Response
    {
        try {
            $status = $this->service->recheck((int) $id);
        } catch (RuleViolation $e) {
            return $this->refused('/supplier-invoices/' . $id, $e);
        }
        return $status === SupplierInvoiceStatus::Approved
            ? $this->redirect('/supplier-invoices/' . $id, 'supplier_invoices.flash_matched')
            : $this->redirect('/supplier-invoices/' . $id, 'supplier_invoices.flash_still_on_hold', 'info');
    }

    public function approve(Request $request, string $id): Response
    {
        $note = mb_substr(trim($request->str('note')), 0, 255);
        if ($note === '') {
            return $this->redirect('/supplier-invoices/' . $id, 'supplier_invoices.error_note', 'error');
        }
        try {
            $this->service->approve((int) $id, $note);
        } catch (RuleViolation $e) {
            return $this->refused('/supplier-invoices/' . $id, $e);
        }
        return $this->redirect('/supplier-invoices/' . $id, 'supplier_invoices.flash_approved');
    }

    public function cancel(Request $request, string $id): Response
    {
        $reason = mb_substr(trim($request->str('reason')), 0, 255);
        if ($reason === '') {
            return $this->redirect('/supplier-invoices/' . $id, 'invoices.error_reason', 'error');
        }
        try {
            $this->service->cancel((int) $id, $reason);
        } catch (RuleViolation $e) {
            return $this->refused('/supplier-invoices/' . $id, $e);
        }
        return $this->redirect('/supplier-invoices/' . $id, 'supplier_invoices.flash_cancelled');
    }

    /** @return array{0: array<int, array{quantity: int, price: int}>, 1: array<string, string>} */
    private function lines(Request $request): array
    {
        $out = [];
        $errors = [];
        foreach ($request->rows('lines') as $lineId => $row) {
            if (($row['quantity'] ?? '') === '' || ($row['quantity'] ?? '') === '0') {
                continue;
            }
            $v = Validator::make($row, ['quantity' => 'required|qty', 'price' => 'required|money']);
            if (!$v->passes()) {
                $errors['lines'] = t('client_orders.line_error', ['error' => implode(' ', $v->errors())]);
                continue;
            }
            $out[(int) $lineId] = ['quantity' => $v->validated()['quantity'], 'price' => $v->validated()['price']];
        }
        return [$out, $errors];
    }

    /** @return list<array{label: string, type?: string, width?: int}> */
    public static function exportColumns(): array
    {
        return [
            ['label' => t('export.col.reference')], ['label' => t('export.col.kind')], ['label' => t('export.col.supplier'), 'width' => 28],
            ['label' => t('export.col.supplier_invoice_number')], ['label' => t('export.col.supplier_order')], ['label' => t('export.col.shipments')],
            ['label' => t('export.col.status')], ['label' => t('export.col.issue_date'), 'type' => Xlsx::DATE], ['label' => t('export.col.due_date'), 'type' => Xlsx::DATE],
            ['label' => t('export.col.currency')], ['label' => t('export.col.subtotal'), 'type' => Xlsx::MONEY, 'total' => false], ['label' => t('export.col.vat'), 'type' => Xlsx::MONEY, 'total' => false],
            ['label' => t('export.col.total'), 'type' => Xlsx::MONEY, 'total' => false], ['label' => t('export.col.paid'), 'type' => Xlsx::MONEY, 'total' => false], ['label' => t('export.col.balance'), 'type' => Xlsx::MONEY, 'total' => false],
            ['label' => t('export.col.rate'), 'type' => Xlsx::RATE], ['label' => t('export.col.total_usd'), 'type' => Xlsx::MONEY], ['label' => t('export.col.balance_usd'), 'type' => Xlsx::MONEY],
        ];
    }

    /** @param array<string, mixed> $i @return list<mixed> */
    public static function exportRow(array $i): array
    {
        return [
            $i['reference'], t($i['kind'] === 'logistics' ? 'logistics.kind' : 'logistics.kind_goods'), $i['supplier_name'], $i['supplier_invoice_number'],
            $i['order_reference'], $i['shipment_references'], t(SupplierInvoiceStatus::from($i['status'])->labelKey()), $i['invoice_date'], $i['due_date'],
            $i['currency_code'], $i['subtotal_minor'], $i['vat_minor'], $i['total_minor'], $i['paid'], $i['balance'],
            $i['fx_units_per_usd_micro'], $i['total_usd'], $i['balance_usd'],
        ];
    }
}
