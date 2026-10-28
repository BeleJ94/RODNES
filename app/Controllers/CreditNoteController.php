<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Core\Validator;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ClientRepository;
use App\Repositories\CreditNoteRepository;
use App\Repositories\InvoiceRepository;
use App\Security\SecurityHeaders;
use App\Services\ActivityLogger;
use App\Services\CreditNoteService;
use App\Services\Settings;

final class CreditNoteController extends Controller
{
    public function __construct(
        private CreditNoteRepository $notes,
        private CreditNoteService $service,
        private InvoiceRepository $invoices,
        private ClientRepository $clients,
        private Settings $settings,
        private ActivityLogger $logger,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($request->str('format') === 'xlsx') {
            return $this->xlsx(t('nav.credit_notes'), self::exportColumns(), array_map(self::exportRow(...), $this->notes->list(['unallocated' => $request->str('view') === 'unallocated'])));
        }
        $view = $request->str('view');
        return $this->view('credit_notes/index', [
            'title' => t('credit_notes.title'),
            'rows' => $this->notes->list(['unallocated' => $view === 'unallocated']),
            'viewFilter' => $view,
        ]);
    }

    public function create(Request $request, string $invoiceId): Response
    {
        $invoice = $this->invoices->get((int) $invoiceId);
        return $this->view('credit_notes/form', [
            'title' => t('credit_notes.new_title', ['ref' => $invoice['reference']]),
            'invoice' => $invoice,
            'lines' => $this->notes->creditableLines((int) $invoiceId),
        ]);
    }

    public function store(Request $request, string $invoiceId): Response
    {
        $back = '/invoices/' . $invoiceId . '/credit-notes/new';
        $validator = Validator::make($request->body(), [
            'issue_date' => 'required|date',
            'reason' => 'required|max:255',
            'is_return' => 'bool',
            'notes' => 'nullable|max:2000',
        ]);
        $errors = $validator->errors();
        $lines = [];
        foreach ($request->rows('lines') as $lineId => $row) {
            if (($row['quantity'] ?? '') === '' || ($row['quantity'] ?? '') === '0') {
                continue;
            }
            $v = Validator::make($row, ['quantity' => 'required|qty', 'price' => 'required|money']);
            if (!$v->passes()) {
                $errors['lines'] = t('client_orders.line_error', ['error' => implode(' ', $v->errors())]);
                continue;
            }
            $lines[(int) $lineId] = ['quantity' => $v->validated()['quantity'], 'price' => $v->validated()['price']];
        }
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        $d = $validator->validated();
        try {
            $id = $this->service->issue((int) $invoiceId, [
                'issue_date' => $d['issue_date'], 'reason' => trim($d['reason']), 'is_return' => (bool) $d['is_return'], 'notes' => $d['notes'],
            ], $lines);
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        return $this->redirect('/credit-notes/' . $id, 'credit_notes.flash_created');
    }

    public function show(Request $request, string $id): Response
    {
        $note = $this->notes->get((int) $id);
        $allocations = $this->notes->allocations((int) $id);
        $allocated = array_map('intval', array_column($allocations, 'invoice_id'));
        return $this->view('credit_notes/show', [
            'title' => (string) $note['reference'],
            'note' => $note,
            'lines' => $this->notes->lines((int) $id),
            'allocations' => $allocations,
            'open' => $note['unallocated'] > 0 ? array_values(array_filter(
                $this->invoices->openForClient((int) $note['client_id']),
                static fn (array $i): bool => !in_array((int) $i['id'], $allocated, true),
            )) : [],
            'history' => $this->logger->history('credit_note', (int) $id),
        ]);
    }

    public function allocate(Request $request, string $id): Response
    {
        $allocations = [];
        foreach ($request->rows('alloc') as $invoiceId => $row) {
            if (($row['amount'] ?? '') === '') {
                continue;
            }
            $v = Validator::make($row, ['amount' => 'required|money']);
            if (!$v->passes()) {
                return $this->invalid('/credit-notes/' . $id, $request, ['alloc' => t('validation.money')]);
            }
            $allocations[(int) $invoiceId] = $v->validated()['amount'];
        }
        try {
            $this->service->allocateRemaining((int) $id, $allocations);
        } catch (RuleViolation $e) {
            return $this->violated('/credit-notes/' . $id, $request, $e);
        }
        return $this->redirect('/credit-notes/' . $id, 'payments.flash_allocated');
    }

    public function print(Request $request, string $id): Response
    {
        $note = $this->notes->get((int) $id);
        return SecurityHeaders::framableBySelf($this->view('credit_notes/print', [
            'title' => (string) $note['reference'],
            'note' => $note,
            'client' => $this->clients->get((int) $note['client_id']),
            'lines' => $this->notes->lines((int) $id),
            's' => $this->settings->all(),
            'back' => url('/credit-notes/' . $id),
            'embed' => $request->str('embed') === '1',
        ], 'print'));
    }

    /** @return list<array{label: string, type?: string, width?: int}> */
    public static function exportColumns(): array
    {
        return [
            ['label' => t('export.col.reference')], ['label' => t('export.col.client'), 'width' => 28], ['label' => t('export.col.invoice')],
            ['label' => t('export.col.issue_date'), 'type' => Xlsx::DATE], ['label' => t('export.col.reason'), 'width' => 32], ['label' => t('export.col.return')],
            ['label' => t('export.col.currency')], ['label' => t('export.col.subtotal'), 'type' => Xlsx::MONEY], ['label' => t('export.col.vat'), 'type' => Xlsx::MONEY],
            ['label' => t('export.col.total'), 'type' => Xlsx::MONEY], ['label' => t('export.col.allocated'), 'type' => Xlsx::MONEY], ['label' => t('export.col.unallocated'), 'type' => Xlsx::MONEY],
        ];
    }

    /** @param array<string, mixed> $n @return list<mixed> */
    public static function exportRow(array $n): array
    {
        return [
            $n['reference'], $n['client_name'], $n['invoice_reference'], $n['issue_date'], $n['reason'], t($n['is_return'] ? 'common.yes' : 'common.no'),
            'USD', $n['subtotal_minor'], $n['vat_minor'], $n['total_minor'], $n['allocated_minor'], $n['unallocated'],
        ];
    }
}
