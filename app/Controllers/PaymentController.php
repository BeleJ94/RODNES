<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Core\Validator;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ClientRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentRepository;
use App\Services\ActivityLogger;
use App\Services\PaymentService;

final class PaymentController extends Controller
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentService $service,
        private InvoiceRepository $invoices,
        private ClientRepository $clients,
        private ActivityLogger $logger,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($request->str('format') === 'xlsx') {
            return $this->xlsx(t('nav.payments'), self::exportColumns(), array_map(self::exportRow(...), $this->payments->list(['unallocated' => $request->str('view') === 'unallocated'])));
        }
        $view = $request->str('view');
        return $this->view('payments/index', [
            'title' => t('payments.title'),
            'rows' => $this->payments->list(['unallocated' => $view === 'unallocated']),
            'viewFilter' => $view,
        ]);
    }

    /** Choix du client (rechargement), puis montant et répartition sur ses factures ouvertes. */
    public function create(Request $request): Response
    {
        $clientId = $request->int('client_id');
        $client = $clientId !== null ? $this->clients->find($clientId) : null;
        return $this->view('payments/form', [
            'title' => t('payments.new'),
            'clients' => $this->clients->options(),
            'client' => $client,
            'open' => $client !== null ? $this->invoices->openForClient((int) $client['id']) : [],
            'focusInvoice' => $request->int('invoice_id'),
            'methods' => PaymentService::METHODS,
        ]);
    }

    public function store(Request $request): Response
    {
        $clientId = (int) $request->int('client_id', 0);
        $back = '/payments/new?client_id=' . $clientId;
        $validator = Validator::make($request->body(), [
            'received_on' => 'required|date',
            'amount' => 'required|money',
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
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        try {
            $id = $this->service->record($clientId, [
                'received_on' => $d['received_on'], 'amount_minor' => $d['amount'], 'method' => $d['method'],
                'bank_reference' => $d['bank_reference'], 'notes' => $d['notes'],
            ], $allocations);
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        return $this->redirect('/payments/' . $id, 'payments.flash_created');
    }

    public function show(Request $request, string $id): Response
    {
        $payment = $this->payments->get((int) $id);
        $remaining = (int) $payment['amount_minor'] - (int) $payment['allocated_minor'];
        $allocated = array_map('intval', array_column($this->payments->allocations((int) $id), 'invoice_id'));
        return $this->view('payments/show', [
            'title' => (string) $payment['reference'],
            'payment' => $payment,
            'remaining' => $remaining,
            'allocations' => $this->payments->allocations((int) $id),
            'open' => $remaining > 0 ? array_values(array_filter(
                $this->invoices->openForClient((int) $payment['client_id']),
                static fn (array $i): bool => !in_array((int) $i['id'], $allocated, true),
            )) : [],
            'history' => $this->logger->history('payment', (int) $id),
        ]);
    }

    public function allocate(Request $request, string $id): Response
    {
        [$allocations, $errors] = $this->allocations($request);
        if ($errors !== []) {
            return $this->invalid('/payments/' . $id, $request, $errors);
        }
        try {
            $this->service->allocateRemaining((int) $id, $allocations);
        } catch (RuleViolation $e) {
            return $this->violated('/payments/' . $id, $request, $e);
        }
        return $this->redirect('/payments/' . $id, 'payments.flash_allocated');
    }

    /** @return array{0: array<int, int>, 1: array<string, string>} */
    private function allocations(Request $request): array
    {
        $out = [];
        $errors = [];
        foreach ($request->rows('alloc') as $invoiceId => $row) {
            $value = $row['amount'] ?? '';
            if ($value === '') {
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
            ['label' => t('export.col.reference')], ['label' => t('export.col.client'), 'width' => 28], ['label' => t('export.col.received_on'), 'type' => Xlsx::DATE],
            ['label' => t('export.col.method')], ['label' => t('export.col.bank_reference')], ['label' => t('export.col.currency')],
            ['label' => t('export.col.amount'), 'type' => Xlsx::MONEY], ['label' => t('export.col.allocated'), 'type' => Xlsx::MONEY],
            ['label' => t('export.col.unallocated'), 'type' => Xlsx::MONEY], ['label' => t('export.col.invoices'), 'width' => 28],
        ];
    }

    /** @param array<string, mixed> $p @return list<mixed> */
    public static function exportRow(array $p): array
    {
        return [
            $p['reference'], $p['client_name'], $p['received_on'], t('payment_method.' . $p['method']), $p['bank_reference'], 'USD',
            $p['amount_minor'], $p['allocated_minor'], (int) $p['amount_minor'] - (int) $p['allocated_minor'], $p['invoice_references'],
        ];
    }
}
