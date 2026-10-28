<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Core\Validator;
use App\Domain\Sales\RfqStatus;
use App\Domain\Shared\RuleViolation;
use App\Domain\Sourcing\OfferComparison;
use App\Repositories\ClientOrderRepository;
use App\Repositories\ClientRepository;
use App\Repositories\ItemRepository;
use App\Repositories\QuotationRepository;
use App\Repositories\RfqRepository;
use App\Repositories\SupplierQuoteRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\UserRepository;
use App\Security\Auth;
use App\Services\ActivityLogger;
use App\Services\QuotationService;
use App\Services\RfqService;

final class RfqController extends Controller
{
    public function __construct(
        private RfqRepository $rfqs,
        private RfqService $service,
        private ClientRepository $clients,
        private ItemRepository $items,
        private UserRepository $users,
        private SupplierRepository $suppliers,
        private SupplierQuoteRepository $offers,
        private QuotationRepository $quotations,
        private QuotationService $quotationService,
        private ActivityLogger $logger,
        private Auth $auth,
        private ClientOrderRepository $clientOrders,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($request->str('format') === 'xlsx') {
            return $this->export($this->rfqs->list(['status' => $request->str('status'), 'client_id' => $request->int('client_id')]));
        }
        $filters = ['status' => $request->str('status'), 'client_id' => $request->int('client_id')];
        return $this->view('rfqs/index', [
            'title' => t('rfqs.title'),
            'rows' => $this->rfqs->list($filters),
            'filters' => $filters,
            'clients' => $this->clients->options(),
            'kpis' => $this->rfqs->kpis(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, [], $request->int('client_id'));
    }

    public function edit(Request $request, string $id): Response
    {
        $rfq = $this->rfqs->get((int) $id);
        if (!RfqStatus::from((string) $rfq['status'])->isOpen()) {
            return $this->redirect('/rfqs/' . $id, 'rfqs.error_closed', 'error');
        }
        return $this->form($rfq, $this->rfqs->lines((int) $id));
    }

    public function store(Request $request): Response
    {
        [$header, $lines, $errors] = $this->parse($request);
        $uploads = self::uploads($request);
        if ($uploads === []) {
            $errors['attachments'] = t('rfqs.error_attachment_required');
        }
        if ($errors !== []) {
            return $this->invalid('/rfqs/new', $request, $errors);
        }
        try {
            $id = $this->service->create($header, $lines, $uploads);
        } catch (RuleViolation $e) {
            return $this->violated('/rfqs/new', $request, $e);
        }
        return $this->savedRedirect($id, 'rfqs.flash_created');
    }

    public function update(Request $request, string $id): Response
    {
        $this->rfqs->get((int) $id);
        [$header, $lines, $errors] = $this->parse($request);
        if ($errors !== []) {
            return $this->invalid('/rfqs/' . $id . '/edit', $request, $errors);
        }
        try {
            $this->service->update((int) $id, $header, $lines, self::uploads($request));
        } catch (RuleViolation $e) {
            return $this->violated('/rfqs/' . $id . '/edit', $request, $e);
        }
        return $this->savedRedirect((int) $id, 'common.flash_saved');
    }

    /** Retour à la fiche ; signale les articles ajoutés au référentiel depuis les lignes. */
    private function savedRedirect(int $id, string $flash): Response
    {
        $created = $this->service->createdItems();
        if ($created > 0) {
            app(\App\Core\Session::class)->flash('info', ['key' => 'rfqs.flash_items_created', 'params' => ['n' => $created]]);
        }
        return $this->redirect('/rfqs/' . $id, $flash);
    }

    /**
     * Fichiers joints (champ multiple attachments[]) remis à plat, sans les champs vides.
     *
     * @return list<array<string, mixed>>
     */
    private static function uploads(Request $request): array
    {
        $raw = $request->file('attachments');
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw['name'] ?? null)) {
            return [$raw];
        }
        $out = [];
        foreach (array_keys($raw['name']) as $i) {
            if (($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $raw['name'][$i], 'type' => $raw['type'][$i] ?? '', 'tmp_name' => $raw['tmp_name'][$i], 'error' => $raw['error'][$i], 'size' => $raw['size'][$i] ?? 0];
        }
        return array_slice($out, 0, 10);
    }

    public function show(Request $request, string $id): Response
    {
        $rfq = $this->rfqs->get((int) $id);
        $lines = $this->rfqs->lines((int) $id);
        $requests = $this->offers->forRfq((int) $id);
        $offers = $this->quotationService->convertedOffers((int) $id);
        $matrix = [];
        foreach ($offers as $offer) {
            $matrix[(int) $offer['rfq_line_id']][(int) $offer['supplier_quote_id']] = $offer;
        }
        $best = OfferComparison::best(array_values(array_map(static fn (array $o): array => [
            'offer_id' => (int) $o['id'], 'rfq_line_id' => (int) $o['rfq_line_id'],
            'unit_usd_minor' => $o['unit_usd_minor'], 'lead_time_days' => $o['lead_time_days'] !== null ? (int) $o['lead_time_days'] : null,
        ], $offers)));
        $requested = array_map('intval', array_column($requests, 'supplier_id'));
        $quotations = $this->quotations->forRfq((int) $id);
        $status = RfqStatus::from((string) $rfq['status']);
        return $this->view('rfqs/show', [
            'title' => (string) $rfq['reference'],
            'rfq' => $rfq,
            'status' => $status,
            'lines' => $lines,
            'requests' => $requests,
            'answered' => array_values(array_filter($requests, static fn (array $r): bool => $r['status'] === 'received')),
            'matrix' => $matrix,
            'best' => $best,
            'availableSuppliers' => array_values(array_filter($this->suppliers->all(true), static fn (array $s): bool => !in_array((int) $s['id'], $requested, true))),
            'quotations' => $quotations,
            'canQuote' => !$status->isClosed() && array_filter($quotations, static fn (array $q): bool => !in_array($q['status'], ['rejected', 'superseded'], true)) === [],
            'history' => $this->logger->history('rfq', (int) $id),
            'files' => $this->rfqs->files((int) $id),
            'clientOrders' => $this->clientOrders->forRfq((int) $id),
            'requestedCounts' => $this->offers->requestedCounts((int) $id),
            'reopenOffer' => $this->reopenOffer($request, (int) $id),
            // Réponse attendue des fournisseurs : délai de relance paramétré, sans dépasser la veille de l'échéance client.
            'defaultResponseDue' => self::responseDue((string) ($rfq['due_at'] ?? '')),
        ]);
    }

    /** @return array<string, mixed>|null données de la saisie d'offre à rouvrir dans la fenêtre */
    private function reopenOffer(Request $request, int $rfqId): ?array
    {
        $offerId = $request->int('offer');
        if ($offerId === null || !can('sourcing.manage')) {
            return null;
        }
        $quote = $this->offers->find($offerId);
        return $quote !== null && (int) $quote['rfq_id'] === $rfqId ? app(SourcingController::class)->offerData($offerId) : null;
    }

    private static function responseDue(string $clientDue): string
    {
        $due = date('Y-m-d', strtotime('+' . max(1, (int) setting('sourcing_reminder_days')) . ' days'));
        if ($clientDue !== '') {
            $before = date('Y-m-d', strtotime(substr($clientDue, 0, 10) . ' -1 day'));
            $due = max(date('Y-m-d'), min($due, $before));
        }
        return $due;
    }

    public function close(Request $request, string $id): Response
    {
        $to = RfqStatus::tryFrom($request->str('status'));
        try {
            $this->service->close((int) $id, $to ?? RfqStatus::Received, mb_substr($request->str('reason'), 0, 255) ?: null);
        } catch (RuleViolation $e) {
            return $this->redirect('/rfqs/' . $id, $e->key, 'error');
        }
        return $this->redirect('/rfqs/' . $id, 'rfqs.flash_closed');
    }

    /**
     * @param array<string, mixed>|null $rfq
     * @param list<array<string, mixed>> $lines
     */
    private function form(?array $rfq, array $lines, ?int $clientId = null): Response
    {
        return $this->view('rfqs/form', [
            'title' => $rfq === null ? t('rfqs.new') : t('rfqs.edit', ['ref' => (string) $rfq['reference']]),
            'rfq' => $rfq ?? ['received_on' => date('Y-m-d'), 'owner_id' => $this->auth->user()?->id, 'client_id' => $clientId],
            'lines' => $lines,
            'action' => $rfq === null ? url('/rfqs') : url('/rfqs/' . $rfq['id']),
            'cancel' => $rfq === null ? url('/rfqs') : url('/rfqs/' . $rfq['id']),
            'clients' => $this->clients->options(),
            'items' => $this->items->pickerOptions(),
            'files' => $rfq === null ? [] : $this->rfqs->files((int) $rfq['id']),
            'canCreateItems' => can('items.manage'),
            'clientInfo' => $this->clientInfo(),
            'users' => $this->users->options(),
            'units' => app(\App\Repositories\UnitRepository::class)->choices(),
        ]);
    }

    /**
     * Par client actif : acheteur et e-mail par défaut (contact de la fiche), délai de réponse en vigueur.
     *
     * @return array{mode: string, clients: array<int, array{buyer: string, email: string, days: int}>}
     */
    private function clientInfo(): array
    {
        $policy = app(\App\Services\DeadlinePolicy::class);
        $clients = [];
        foreach ($this->clients->all(true) as $c) {
            $clients[(int) $c['id']] = ['buyer' => (string) $c['contact_name'], 'email' => (string) $c['contact_email'], 'days' => $policy->rfqResponseDays((int) $c['id'])];
        }
        return ['mode' => $policy->mode(), 'clients' => $clients];
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function parse(Request $request): array
    {
        $validator = Validator::make($request->body(), [
            'client_id' => 'required|int',
            'client_reference' => 'nullable|max:64',
            'received_on' => 'required|date',
            'due_at' => 'nullable|datetime',
            'buyer_name' => 'nullable|max:120',
            'buyer_email' => 'nullable|email|max:190',
            'delivery_site' => 'nullable|max:160',
            'owner_id' => 'nullable|int',
            'notes' => 'nullable|max:2000',
        ]);
        $header = $validator->validated();
        $errors = $validator->errors();
        if (isset($header['client_id']) && ($this->clients->find($header['client_id'])['is_active'] ?? 0) != 1) {
            $errors['client_id'] = t('validation.in');
        }
        if (!empty($header['due_at'])) {
            $header['due_at'] = str_replace('T', ' ', $header['due_at']) . ':00';
        }

        $lines = [];
        $lineErrors = [];
        $n = 0;
        foreach ($request->rows('lines') as $row) {
            if (self::blankRow(array_intersect_key($row, array_flip(['item_id', 'item_name', 'description', 'quantity', 'required_by'])))) {
                continue;
            }
            $n++;
            $v = Validator::make($row, [
                'item_id' => 'nullable|int',
                'item_name' => 'nullable|max:190',
                'description' => 'nullable|max:500',
                'quantity' => 'required|qty',
                'unit' => 'required|in:' . implode(',', app(\App\Repositories\UnitRepository::class)->activeCodes()),
                'required_by' => 'nullable|date',
            ]);
            $line = $v->validated();
            $messages = $v->errors();
            if ($v->passes() && $line['item_id'] !== null) {
                $item = $this->items->find($line['item_id']);
                if ($item === null) {
                    $messages['item_id'] = t('validation.in');
                } elseif ($line['description'] === null) {
                    $line['description'] = (string) $item['name'];
                }
            }
            $newItem = $line['item_id'] === null ? trim((string) ($line['item_name'] ?? '')) : '';
            if ($v->passes() && $line['description'] === null && $newItem !== '') {
                $line['description'] = $newItem;
            }
            if ($v->passes() && $line['description'] === null) {
                $messages['description'] = t('rfqs.error_description');
            }
            if ($messages !== []) {
                $lineErrors[] = t('rfqs.line_error', ['n' => $n, 'error' => implode(' ', $messages)]);
                continue;
            }
            $lines[] = [
                'item_id' => $line['item_id'], 'description' => $line['description'], 'quantity_milli' => $line['quantity'],
                'unit' => $line['unit'], 'required_by' => $line['required_by'], 'new_item' => $newItem,
            ];
        }
        if ($n === 0) {
            $lineErrors[] = t('rfqs.error_no_lines');
        }
        if ($lineErrors !== []) {
            $errors['lines'] = implode(' ', $lineErrors);
        }
        return [$header, $lines, $errors];
    }

    /** @param list<array<string, mixed>> $rows */
    private function export(array $rows): Response
    {
        return $this->xlsx(t('nav.rfqs'), [
            ['label' => t('export.col.reference')], ['label' => t('export.col.client'), 'width' => 28], ['label' => t('export.col.client_reference')],
            ['label' => t('export.col.received_on'), 'type' => Xlsx::DATE], ['label' => t('export.col.due_at'), 'type' => Xlsx::DATE],
            ['label' => t('export.col.buyer')], ['label' => t('export.col.site')], ['label' => t('export.col.status')], ['label' => t('export.col.owner')],
            ['label' => t('export.col.lines'), 'type' => Xlsx::INT], ['label' => t('export.col.requests'), 'type' => Xlsx::INT], ['label' => t('export.col.offers'), 'type' => Xlsx::INT],
        ], array_map(static fn (array $r): array => [
            $r['reference'], $r['client_name'], $r['client_reference'], $r['received_on'], $r['due_at'], $r['buyer_name'], $r['delivery_site'],
            t(RfqStatus::from($r['status'])->labelKey()), $r['owner_name'], $r['line_count'], $r['requests'], $r['offers'],
        ], $rows));
    }
}
