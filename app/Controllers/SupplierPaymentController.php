<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Core\Validator;
use App\Domain\Shared\Fx;
use App\Domain\Shared\RuleViolation;
use App\Repositories\CurrencyRepository;
use App\Repositories\SupplierInvoiceRepository;
use App\Repositories\SupplierPaymentRepository;
use App\Repositories\SupplierRepository;
use App\Services\ActivityLogger;
use App\Services\PaymentService;
use App\Services\SupplierPaymentService;

final class SupplierPaymentController extends Controller
{
    public function __construct(
        private SupplierPaymentRepository $payments,
        private SupplierPaymentService $service,
        private SupplierInvoiceRepository $invoices,
        private SupplierRepository $suppliers,
        private CurrencyRepository $currencies,
        private ActivityLogger $logger,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($request->str('format') === 'xlsx') {
            return $this->xlsx(t('nav.supplier_payments'), self::exportColumns(), array_map(self::exportRow(...), $this->payments->list(['supplier_id' => $request->int('supplier_id')])));
        }
        $supplierId = $request->int('supplier_id');
        return $this->view('supplier_payments/index', [
            'title' => t('supplier_payments.title'),
            'rows' => $this->payments->list(['supplier_id' => $supplierId]),
            'supplierId' => $supplierId,
            'suppliers' => $this->suppliers->options(),
        ]);
    }

    /** Fournisseur puis devise (rechargement), puis montant, taux du jour et imputation sur ses factures bon à payer. */
    public function create(Request $request): Response
    {
        $supplierId = $request->int('supplier_id');
        $supplier = $supplierId !== null ? $this->suppliers->find($supplierId) : null;
        $payable = $supplier !== null ? $this->invoices->payableCurrencies((int) $supplier['id']) : [];
        $currency = strtoupper($request->str('currency'));
        $codes = $this->currencies->activeCodes();
        if (!in_array($currency, $codes, true)) {
            $currency = $payable[0] ?? Fx::USD;
        }
        return $this->view('supplier_payments/form', [
            'title' => t('supplier_payments.new'),
            'suppliers' => $this->suppliers->options(),
            'supplier' => $supplier,
            'currencies' => $codes,
            'payableCurrencies' => $payable,
            'currency' => $currency,
            'rate' => $this->currencies->rateFor($currency) ?? Fx::ONE,
            'open' => $supplier !== null ? $this->invoices->payableFor((int) $supplier['id'], $currency) : [],
            'focusInvoice' => $request->int('invoice_id'),
            'methods' => PaymentService::METHODS,
        ]);
    }

    public function store(Request $request): Response
    {
        $supplierId = (int) $request->int('supplier_id', 0);
        $back = '/supplier-payments/new?' . http_build_query(['supplier_id' => $supplierId, 'currency' => $request->str('currency')]);
        $validator = Validator::make($request->body(), [
            'currency' => 'required|currency',
            'paid_on' => 'required|date',
            'amount' => 'required|money',
            'fx_rate' => 'required|rate',
            'method' => 'required|in:' . implode(',', PaymentService::METHODS),
            'bank_reference' => 'nullable|max:80',
            'notes' => 'nullable|max:2000',
        ]);
        [$allocations, $errors] = $this->allocations($request);
        $errors = $validator->errors() + $errors;
        $d = $validator->validated();
        if ($validator->passes() && $d['amount'] === 0) {
            $errors['amount'] = t('validation.money');
        }
        if ($validator->passes() && !in_array($d['currency'], $this->currencies->activeCodes(), true)) {
            $errors['currency'] = t('validation.currency');
        }
        if ($validator->passes() && $d['currency'] === Fx::USD && $d['fx_rate'] !== Fx::ONE) {
            $errors['fx_rate'] = t('validation.rate');
        }
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        try {
            $id = $this->service->record($supplierId, [
                'paid_on' => $d['paid_on'], 'currency_code' => $d['currency'], 'amount_minor' => $d['amount'], 'fx_units_per_usd_micro' => $d['fx_rate'],
                'method' => $d['method'], 'bank_reference' => $d['bank_reference'], 'notes' => $d['notes'],
            ], $allocations);
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        return $this->redirect('/supplier-payments/' . $id, 'supplier_payments.flash_created');
    }

    public function show(Request $request, string $id): Response
    {
        $payment = $this->payments->get((int) $id);
        $allocations = $this->payments->allocations((int) $id);
        $allocated = array_map('intval', array_column($allocations, 'invoice_id'));
        return $this->view('supplier_payments/show', [
            'title' => (string) $payment['reference'],
            'payment' => $payment,
            'allocations' => $allocations,
            'open' => $payment['unallocated'] > 0 ? array_values(array_filter(
                $this->invoices->payableFor((int) $payment['supplier_id'], (string) $payment['currency_code']),
                static fn (array $i): bool => !in_array((int) $i['id'], $allocated, true),
            )) : [],
            'history' => $this->logger->history('supplier_payment', (int) $id),
        ]);
    }

    public function allocate(Request $request, string $id): Response
    {
        [$allocations, $errors] = $this->allocations($request);
        if ($errors !== []) {
            return $this->invalid('/supplier-payments/' . $id, $request, $errors);
        }
        try {
            $this->service->allocateRemaining((int) $id, $allocations);
        } catch (RuleViolation $e) {
            return $this->violated('/supplier-payments/' . $id, $request, $e);
        }
        return $this->redirect('/supplier-payments/' . $id, 'payments.flash_allocated');
    }

    /** @return array{0: array<int, int>, 1: array<string, string>} */
    private function allocations(Request $request): array
    {
        $out = [];
        $errors = [];
        foreach ($request->rows('alloc') as $invoiceId => $row) {
            if (($row['amount'] ?? '') === '') {
                continue;
            }
            $v = Validator::make($row, ['amount' => 'required|money']);
            if (!$v->passes()) {
                $errors['alloc'] = t('validation.money');
                continue;
            }
            $out[(int) $invoiceId] = $v->validated()['amount'];
        }
        return [$out, $errors];
    }

    /** @return list<array{label: string, type?: string, width?: int}> */
    public static function exportColumns(): array
    {
        return [
            ['label' => t('export.col.reference')], ['label' => t('export.col.supplier'), 'width' => 28], ['label' => t('export.col.paid_on'), 'type' => Xlsx::DATE],
            ['label' => t('export.col.method')], ['label' => t('export.col.bank_reference')], ['label' => t('export.col.currency')],
            ['label' => t('export.col.amount'), 'type' => Xlsx::MONEY, 'total' => false], ['label' => t('export.col.rate'), 'type' => Xlsx::RATE], ['label' => t('export.col.amount_usd'), 'type' => Xlsx::MONEY],
            ['label' => t('export.col.allocated'), 'type' => Xlsx::MONEY, 'total' => false], ['label' => t('export.col.unallocated'), 'type' => Xlsx::MONEY, 'total' => false], ['label' => t('export.col.invoices'), 'width' => 28],
        ];
    }

    /** @param array<string, mixed> $p @return list<mixed> */
    public static function exportRow(array $p): array
    {
        return [
            $p['reference'], $p['supplier_name'], $p['paid_on'], t('payment_method.' . $p['method']), $p['bank_reference'], $p['currency_code'],
            $p['amount_minor'], $p['fx_units_per_usd_micro'], $p['amount_usd'], $p['allocated_minor'], $p['unallocated'], $p['invoice_references'],
        ];
    }
}
