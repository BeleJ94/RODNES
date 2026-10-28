<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Core\Validator;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ClientOrderRepository;
use App\Repositories\ClientRepository;
use App\Repositories\CreditNoteRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentRepository;
use App\Security\SecurityHeaders;
use App\Services\ActivityLogger;
use App\Services\InvoiceService;
use App\Services\Settings;

final class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceRepository $invoices,
        private InvoiceService $service,
        private ClientOrderRepository $orders,
        private ClientRepository $clients,
        private PaymentRepository $payments,
        private Settings $settings,
        private ActivityLogger $logger,
        private CreditNoteRepository $creditNotes,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($request->str('format') === 'xlsx') {
            return $this->xlsx(t('nav.invoices'), self::exportColumns(), array_map(self::exportRow(...), $this->invoices->list(['status' => $request->str('status')])));
        }
        $status = $request->str('status');
        return $this->view('invoices/index', [
            'title' => t('invoices.title'),
            'rows' => $this->invoices->list(['status' => $status]),
            'status' => $status,
            'kpis' => $this->invoices->kpis(),
        ]);
    }

    public function create(Request $request, string $orderId): Response
    {
        $data = $this->createData((int) $orderId);
        if ($request->str('fragment') === '1') {
            return $this->view('invoices/_create', $data + ['modal' => true], null)->withHeader('Cache-Control', 'no-store');
        }
        return $this->view('invoices/create', $data + ['title' => t('invoices.new_title', ['ref' => $data['order']['reference']])]);
    }

    /**
     * Données de la facturation, partagées par la page, la fenêtre et la fiche PO client (fenêtre rouverte après erreur).
     *
     * @return array{order: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public function createData(int $orderId): array
    {
        return ['order' => $this->orders->get($orderId), 'lines' => $this->service->invoiceableLines($orderId)];
    }

    public function store(Request $request, string $orderId): Response
    {
        // Erreur : retour à la fiche PO client, fenêtre de facturation rouverte avec les valeurs saisies.
        $back = '/client-orders/' . $orderId . '?invoice=1';
        [$lines, $errors] = $this->quantities($request);
        $issue = $request->str('mode') === 'issue';
        $issueDate = $request->str('issue_date');
        if ($issue && !Validator::make(['d' => $issueDate], ['d' => 'required|date'])->passes()) {
            $errors['issue_date'] = t('validation.date');
        }
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        try {
            // Brouillon puis, si demandé, émission immédiate : une seule transaction (pas de brouillon orphelin).
            [$id, $reference] = app(\App\Core\Database::class)->transaction(function () use ($orderId, $lines, $request, $issue, $issueDate): array {
                $id = $this->service->createDraft((int) $orderId, $lines, mb_substr($request->str('notes'), 0, 2000) ?: null);
                return [$id, $issue ? $this->service->issue($id, $issueDate) : null];
            });
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        if ($reference !== null) {
            app(\App\Core\Session::class)->flash('success', ['key' => 'invoices.flash_issued', 'params' => ['ref' => $reference]]);
            return Response::redirect(url('/invoices/' . $id));
        }
        return $this->redirect('/invoices/' . $id, 'invoices.flash_draft');
    }

    public function show(Request $request, string $id): Response
    {
        $invoice = $this->invoices->get((int) $id);
        return $this->view('invoices/show', [
            'title' => $invoice['reference'] ?? t('invoices.draft_title'),
            'invoice' => $invoice,
            'status' => InvoiceStatus::from((string) $invoice['status']),
            'lines' => $this->invoices->lines((int) $id),
            'payments' => $this->payments->forInvoice((int) $id),
            'creditNotes' => $this->creditNotes->forInvoice((int) $id),
            'history' => $this->logger->history('invoice', (int) $id),
        ]);
    }

    public function edit(Request $request, string $id): Response
    {
        $invoice = $this->invoices->get((int) $id);
        if (!InvoiceStatus::from((string) $invoice['status'])->isEditable()) {
            return $this->redirect('/invoices/' . $id, 'invoices.error_not_draft', 'error');
        }
        $available = array_column($this->service->invoiceableLines((int) $invoice['client_order_id'], (int) $id), null, 'id');
        $lines = array_map(static fn (array $l): array => $l + ['invoiceable_milli' => (int) ($available[(int) $l['client_order_line_id']]['invoiceable_milli'] ?? 0)], $this->invoices->lines((int) $id));
        return $this->view('invoices/form', [
            'title' => t('invoices.edit_title'),
            'order' => $this->orders->get((int) $invoice['client_order_id']),
            'invoice' => $invoice,
            'lines' => $lines,
            'action' => url('/invoices/' . $id),
            'cancel' => url('/invoices/' . $id),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $this->invoices->get((int) $id);
        $back = '/invoices/' . $id . '/edit';
        [$lines, $errors] = $this->quantities($request);
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        try {
            $this->service->updateDraft((int) $id, $lines, $request->str('vat_applicable') === '1', mb_substr($request->str('notes'), 0, 2000) ?: null);
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        return $this->redirect('/invoices/' . $id, 'common.flash_saved');
    }

    public function issue(Request $request, string $id): Response
    {
        $v = Validator::make($request->body(), ['issue_date' => 'required|date']);
        if (!$v->passes()) {
            return $this->redirect('/invoices/' . $id, 'validation.date', 'error');
        }
        try {
            $reference = $this->service->issue((int) $id, $v->validated()['issue_date']);
        } catch (RuleViolation $e) {
            return $this->refused('/invoices/' . $id, $e);
        }
        app(\App\Core\Session::class)->flash('success', ['key' => 'invoices.flash_issued', 'params' => ['ref' => $reference]]);
        return Response::redirect(url('/invoices/' . $id));
    }

    public function delete(Request $request, string $id): Response
    {
        try {
            $orderId = $this->service->deleteDraft((int) $id);
        } catch (RuleViolation $e) {
            return $this->refused('/invoices/' . $id, $e);
        }
        return $this->redirect('/client-orders/' . $orderId . '#invoices', 'invoices.flash_deleted');
    }

    public function cancel(Request $request, string $id): Response
    {
        $reason = mb_substr($request->str('reason'), 0, 255);
        if ($reason === '') {
            return $this->redirect('/invoices/' . $id, 'invoices.error_reason', 'error');
        }
        try {
            $this->service->cancel((int) $id, $reason);
        } catch (RuleViolation $e) {
            return $this->refused('/invoices/' . $id, $e);
        }
        return $this->redirect('/invoices/' . $id, 'invoices.flash_cancelled');
    }

    public function print(Request $request, string $id): Response
    {
        $invoice = $this->invoices->get((int) $id);
        return SecurityHeaders::framableBySelf($this->view('invoices/print', [
            'title' => $invoice['reference'] ?? t('invoices.draft_title'),
            'invoice' => $invoice,
            'client' => $this->clients->get((int) $invoice['client_id']),
            'lines' => $this->invoices->lines((int) $id),
            's' => $this->settings->all(),
            'back' => url('/invoices/' . $id),
            'embed' => $request->str('embed') === '1',
        ], 'print'));
    }

    /** @return array{0: array<int, int>, 1: array<string, string>} */
    private function quantities(Request $request): array
    {
        $lines = [];
        $errors = [];
        foreach ($request->rows('lines') as $lineId => $row) {
            $value = $row['quantity'] ?? '';
            if ($value === '' || $value === '0') {
                $lines[(int) $lineId] = 0;
                continue;
            }
            $v = Validator::make($row, ['quantity' => 'required|qty']);
            if (!$v->passes()) {
                $errors['lines'] = t('client_orders.line_error', ['error' => implode(' ', $v->errors())]);
                continue;
            }
            $lines[(int) $lineId] = $v->validated()['quantity'];
        }
        return [$lines, $errors];
    }

    /** @return list<array{label: string, type?: string, width?: int}> */
    public static function exportColumns(): array
    {
        return [
            ['label' => t('export.col.reference')], ['label' => t('export.col.client'), 'width' => 28], ['label' => t('export.col.client_order')],
            ['label' => t('export.col.client_po')], ['label' => t('export.col.status')], ['label' => t('export.col.issue_date'), 'type' => Xlsx::DATE],
            ['label' => t('export.col.due_date'), 'type' => Xlsx::DATE], ['label' => t('export.col.currency')],
            ['label' => t('export.col.subtotal'), 'type' => Xlsx::MONEY], ['label' => t('export.col.vat'), 'type' => Xlsx::MONEY], ['label' => t('export.col.total'), 'type' => Xlsx::MONEY],
            ['label' => t('export.col.paid'), 'type' => Xlsx::MONEY], ['label' => t('export.col.credited'), 'type' => Xlsx::MONEY], ['label' => t('export.col.balance'), 'type' => Xlsx::MONEY],
        ];
    }

    /** @param array<string, mixed> $i @return list<mixed> */
    public static function exportRow(array $i): array
    {
        return [
            $i['reference'] ?? t('invoices.draft_title'), $i['client_name'], $i['order_reference'], $i['client_po_number'],
            t(InvoiceStatus::from($i['status'])->labelKey()), $i['issue_date'], $i['due_date'], $i['currency_code'],
            $i['subtotal'], $i['vat'], $i['total'], $i['paid'], $i['credited'], $i['balance'],
        ];
    }
}
