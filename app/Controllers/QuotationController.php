<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Sales\Pricing;
use App\Core\Xlsx;
use App\Core\Validator;
use App\Domain\Sales\QuotationStatus;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ClientOrderRepository;
use App\Repositories\ClientRepository;
use App\Repositories\QuotationRepository;
use App\Repositories\RfqRepository;
use App\Security\SecurityHeaders;
use App\Services\ActivityLogger;
use App\Services\FollowupService;
use App\Services\QuotationService;
use App\Services\Settings;

final class QuotationController extends Controller
{
    public const TRANSPORT_MODES = ['road', 'air', 'sea', 'rail', 'multimodal', 'courier'];

    public function __construct(
        private QuotationRepository $quotations,
        private QuotationService $service,
        private RfqRepository $rfqs,
        private ClientRepository $clients,
        private Settings $settings,
        private ActivityLogger $logger,
        private ClientOrderRepository $clientOrders,
        private FollowupService $followups,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($request->str('format') === 'xlsx') {
            return $this->export($this->quotations->list(['status' => $request->str('status')]));
        }
        $status = $request->str('status');
        return $this->view('quotations/index', [
            'title' => t('quotations.title'),
            'rows' => $this->quotations->list(['status' => $status]),
            'status' => $status,
            'kpis' => $this->quotations->kpis(),
        ]);
    }

    public function store(Request $request, string $rfqId): Response
    {
        try {
            $id = $this->service->createFromRfq((int) $rfqId);
        } catch (RuleViolation $e) {
            return $this->redirect('/rfqs/' . $rfqId, $e->key, 'error');
        }
        return $this->redirect('/quotations/' . $id . '/edit', 'quotations.flash_created');
    }

    public function show(Request $request, string $id): Response
    {
        $quotation = $this->quotations->get((int) $id);
        $status = QuotationStatus::from((string) $quotation['status']);
        return $this->view('quotations/show', [
            'title' => $quotation['reference'] . ' v' . $quotation['version'],
            'q' => $quotation,
            'status' => $status,
            'lines' => $this->quotations->lines((int) $id),
            'totals' => $this->service->totals((int) $id),
            'approvalRequired' => $status === QuotationStatus::Draft && $this->service->approvalRequired((int) $id),
            'thresholdBp' => $this->settings->int('margin_approval_threshold_bp'),
            'versions' => $this->quotations->versions((string) $quotation['reference']),
            'clientOrders' => $this->clientOrders->forQuotation((int) $id),
            'history' => $this->logger->history('quotation', (int) $id),
            'followupCards' => $this->followupCards($status, (int) $id),
        ]);
    }

    /** @return list<array<string, mixed>> encarts de relance : devis sans réponse, puis PO client attendu */
    private function followupCards(QuotationStatus $status, int $id): array
    {
        $cards = [];
        foreach (['quote' => QuotationStatus::Sent, 'client_po' => QuotationStatus::Accepted] as $kind => $active) {
            $history = $this->followups->history($kind, $id);
            $next = $status === $active ? $this->followups->nextFor($kind, $id) : null;
            // Devis envoyé : l'encart reste visible même sans rythme (il le signale) ; PO attendu : seulement s'il y a une relance.
            if ($next !== null || $history !== [] || ($status === $active && $kind === 'quote')) {
                $cards[] = ['kind' => $kind, 'subjectId' => $id, 'next' => $next, 'history' => $history, 'canManage' => can('quotations.manage')];
            }
        }
        return $cards;
    }

    public function edit(Request $request, string $id): Response
    {
        $quotation = $this->quotations->get((int) $id);
        if (!QuotationStatus::from((string) $quotation['status'])->isEditable()) {
            return $this->redirect('/quotations/' . $id, 'quotations.error_not_draft', 'error');
        }
        $offersByLine = [];
        foreach ($this->service->convertedOffers((int) $quotation['rfq_id']) as $offer) {
            $offersByLine[(int) $offer['rfq_line_id']][] = $offer;
        }
        return $this->view('quotations/form', [
            'title' => t('quotations.edit', ['ref' => $quotation['reference'] . ' v' . $quotation['version']]),
            'q' => $quotation,
            'lines' => $this->quotations->lines((int) $id),
            'offersByLine' => $offersByLine,
            'totals' => $this->service->totals((int) $id),
            'incoterms' => SourcingController::INCOTERMS,
            'transportModes' => self::TRANSPORT_MODES,
            'defaultMarginBp' => $this->settings->int('default_margin_bp'),
            'thresholdBp' => $this->settings->int('margin_approval_threshold_bp'),
            'approvalEnabled' => $this->settings->bool('margin_approval_enabled'),
            'rfq' => $this->rfqs->get((int) $quotation['rfq_id']),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $quotation = $this->quotations->get((int) $id);
        $back = '/quotations/' . $id . '/edit';
        $validator = Validator::make($request->body(), [
            'valid_until' => 'required|date',
            'incoterm' => 'nullable|in:' . implode(',', SourcingController::INCOTERMS),
            'delivery_terms' => 'nullable|max:255',
            'payment_terms_days' => 'required|int|between:0,365',
            'payment_terms_basis' => 'required|in:delivery,invoice',
            'vat_applicable' => 'bool',
            'notes' => 'nullable|max:2000',
            'ship_to_name' => 'nullable|max:160',
            'ship_to_address' => 'nullable|max:500',
            'ship_to_phone' => 'nullable|max:40',
            'country_of_origin' => 'nullable|max:80',
            'estimated_ship_date' => 'nullable|date',
            'transport_mode' => 'nullable|in:' . implode(',', self::TRANSPORT_MODES),
            'carrier' => 'nullable|max:120',
            'packages_count' => 'nullable|int|between:0,65000',
            'gross_weight' => 'nullable|qty',
            'net_weight' => 'nullable|qty',
        ]);
        $errors = $validator->errors();
        $header = $validator->validated();
        // Poids en kg, stockés en millièmes comme les quantités.
        $header['gross_weight_milli'] = $header['gross_weight'] ?? null;
        $header['net_weight_milli'] = $header['net_weight'] ?? null;
        unset($header['gross_weight'], $header['net_weight']);
        $lines = [];
        $rows = $request->rows('lines');
        foreach ($this->quotations->lines((int) $id) as $current) {
            $row = $rows[(int) $current['id']] ?? null;
            if ($row === null) {
                continue;
            }
            $v = Validator::make($row, [
                'description' => 'required|max:500',
                'quantity' => 'required|qty',
                'source' => 'nullable|int',
                'cost' => 'nullable|money',
                'extra' => 'nullable|money',
                'price' => 'required|money',
                'availability' => 'nullable|max:40',
            ]);
            if (!$v->passes()) {
                $errors['lines'] = t('rfqs.line_error', ['n' => $current['line_no'], 'error' => implode(' ', $v->errors())]);
                continue;
            }
            $d = $v->validated();
            $lines[(int) $current['id']] = [
                'description' => $d['description'],
                'quantity_milli' => $d['quantity'],
                'supplier_quote_line_id' => $d['source'],
                'cost_unit_usd_minor' => $d['cost'] ?? 0,
                'extra_unit_usd_minor' => $d['extra'] ?? 0,
                'unit_price_minor' => $d['price'],
                'availability' => $d['availability'],
            ];
        }
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        try {
            $this->service->update((int) $id, $header, $lines);
        } catch (RuleViolation $e) {
            return $this->violated('/quotations/' . $id, $request, $e);
        }
        return $this->redirect('/quotations/' . $id, 'common.flash_saved');
    }

    public function transition(Request $request, string $id): Response
    {
        $action = $request->str('action');
        try {
            $message = $this->service->transition((int) $id, in_array($action, QuotationService::ACTIONS, true) ? $action : '', [
                'reason' => $request->str('lost_reason'),
                'comment' => mb_substr($request->str('lost_comment'), 0, 255),
            ]);
        } catch (RuleViolation $e) {
            return $this->redirect('/quotations/' . $id, $e->key, 'error');
        }
        return $this->redirect('/quotations/' . $id, $message);
    }

    public function revise(Request $request, string $id): Response
    {
        try {
            $newId = $this->service->revise((int) $id);
        } catch (RuleViolation $e) {
            return $this->redirect('/quotations/' . $id, $e->key, 'error');
        }
        return $this->redirect('/quotations/' . $newId . '/edit', 'quotations.flash_revised');
    }

    public function print(Request $request, string $id): Response
    {
        $quotation = $this->quotations->get((int) $id);
        return SecurityHeaders::framableBySelf($this->view('quotations/print', [
            'title' => $quotation['reference'] . ' v' . $quotation['version'],
            'q' => $quotation,
            'client' => $this->clients->get((int) $quotation['client_id']),
            'rfq' => $this->rfqs->get((int) $quotation['rfq_id']),
            'lines' => $this->quotations->lines((int) $id),
            'totals' => $this->service->totals((int) $id),
            's' => $this->settings->all(),
            'logo' => company_logo_data_uri(),
            'back' => url('/quotations/' . $id),
            'embed' => $request->str('embed') === '1',
        ], 'print'));
    }

    /** @param list<array<string, mixed>> $rows */
    private function export(array $rows): Response
    {
        return $this->xlsx(t('nav.quotations'), [
            ['label' => t('export.col.reference')], ['label' => t('export.col.version'), 'type' => Xlsx::INT], ['label' => t('export.col.rfq')],
            ['label' => t('export.col.client_reference')], ['label' => t('export.col.client'), 'width' => 28], ['label' => t('export.col.status')],
            ['label' => t('export.col.currency')], ['label' => t('export.col.valid_until'), 'type' => Xlsx::DATE], ['label' => t('export.col.sent_at'), 'type' => Xlsx::DATE],
            ['label' => t('export.col.subtotal'), 'type' => Xlsx::MONEY], ['label' => t('export.col.cost'), 'type' => Xlsx::MONEY],
            ['label' => t('export.col.margin'), 'type' => Xlsx::MONEY], ['label' => t('export.col.margin_pct'), 'type' => Xlsx::PERCENT],
        ], array_map(static fn (array $q): array => [
            $q['reference'], $q['version'], $q['rfq_reference'], $q['client_reference'], $q['client_name'], t(QuotationStatus::from($q['status'])->labelKey()),
            $q['currency_code'], $q['valid_until'], $q['sent_at'], $q['subtotal'], $q['cost'], (int) $q['subtotal'] - (int) $q['cost'],
            Pricing::marginBp((int) $q['cost'], (int) $q['subtotal']),
        ], $rows));
    }
}
