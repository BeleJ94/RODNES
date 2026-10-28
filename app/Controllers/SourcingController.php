<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\Shared\RuleViolation;
use App\Repositories\CurrencyRepository;
use App\Repositories\RfqRepository;
use App\Repositories\SupplierQuoteRepository;
use App\Services\SourcingService;

final class SourcingController extends Controller
{
    public const INCOTERMS = ['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];

    public function __construct(
        private SourcingService $service,
        private SupplierQuoteRepository $quotes,
        private RfqRepository $rfqs,
        private CurrencyRepository $currencies,
    ) {
    }

    public function request(Request $request, string $rfqId): Response
    {
        $raw = $request->body()['supplier_ids'] ?? [];
        $ids = is_array($raw) ? array_values(array_filter(array_map(
            static fn (mixed $v): int => is_scalar($v) && preg_match('/^\d{1,9}$/', (string) $v) ? (int) $v : 0,
            $raw,
        ))) : [];
        if ($ids === []) {
            return $this->redirect('/rfqs/' . $rfqId . '#sourcing', 'sourcing.error_no_supplier', 'error');
        }
        // Lignes cochées et quantités (saisie au format local).
        $lines = [];
        foreach ($request->rows('lines') as $lineId => $row) {
            if (empty($row['selected'])) {
                continue;
            }
            try {
                $qty = \App\Domain\Shared\Milli::parse((string) ($row['quantity'] ?? ''));
            } catch (\InvalidArgumentException) {
                $qty = 0;
            }
            $lines[(int) $lineId] = $qty;
        }
        if ($request->rows('lines') !== [] && $lines === []) {
            return $this->redirect('/rfqs/' . $rfqId . '#sourcing', 'sourcing.error_no_line', 'error');
        }
        $due = $request->str('response_due');
        $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $due) !== false ? $due : null;
        try {
            $created = $this->service->request((int) $rfqId, $ids, $lines === [] ? null : $lines, $due, mb_substr(trim($request->str('message')), 0, 2000) ?: null);
        } catch (RuleViolation $e) {
            return $this->redirect('/rfqs/' . $rfqId, $e->key, 'error');
        }
        return $this->redirect('/rfqs/' . $rfqId . '#sourcing', $created > 0 ? 'sourcing.flash_requested' : 'sourcing.flash_already_requested', $created > 0 ? 'success' : 'info');
    }

    /** Saisie de l'offre : fragment pour la fenêtre de la fiche RFQ (?fragment=1), page complète sinon. */
    public function offerForm(Request $request, string $id): Response
    {
        $data = $this->offerData((int) $id);
        if ($request->str('fragment') === '1') {
            return $this->view('sourcing/_offer_form', $data, null)->withHeader('Cache-Control', 'no-store');
        }
        return $this->view('sourcing/offer', $data + ['title' => t('sourcing.offer_title', ['supplier' => (string) $data['quote']['supplier_name']])]);
    }

    /** Consultation de l'offre (fenêtre) : conditions, prix, totaux, document du fournisseur. */
    public function offerView(Request $request, string $id): Response
    {
        return $this->view('sourcing/_offer_view', $this->offerData((int) $id), null)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Données de l'offre, partagées par la saisie, la consultation et la fiche RFQ (fenêtre rouverte après erreur).
     *
     * @return array<string, mixed>
     */
    public function offerData(int $id): array
    {
        $quote = $this->quotes->get($id);
        return [
            'quote' => $quote,
            // Seules les lignes demandées à ce fournisseur (toutes pour les demandes antérieures au document).
            'lines' => $this->quotes->requestedLines($id) ?: $this->rfqs->lines((int) $quote['rfq_id']),
            'offered' => $this->quotes->lines($id),
            'files' => $this->quotes->files($id),
            'currencies' => $this->currencies->activeCodes(),
            'rates' => $this->currencies->latestRates(),
            'incoterms' => self::INCOTERMS,
        ];
    }

    public function saveOffer(Request $request, string $id): Response
    {
        $quote = $this->quotes->get((int) $id);
        $back = '/rfqs/' . $quote['rfq_id'] . '?offer=' . (int) $id;
        $validator = Validator::make($request->body(), [
            'supplier_reference' => 'nullable|max:64',
            'received_on' => 'required|date',
            'currency_code' => 'required|currency',
            'incoterm' => 'nullable|in:' . implode(',', self::INCOTERMS),
            'lead_time_days' => 'nullable|int|between:0,999',
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|max:2000',
        ]);
        $header = $validator->validated();
        $errors = $validator->errors();
        if ($validator->passes() && !isset($this->currencies->latestRates()[$header['currency_code']])) {
            $errors['currency_code'] = t('sourcing.error_no_rate');
        }

        $lines = [];
        $priced = 0;
        $rows = $request->rows('lines');
        foreach ($this->rfqs->lines((int) $quote['rfq_id']) as $rfqLine) {
            $row = $rows[(int) $rfqLine['id']] ?? [];
            if (($row['price'] ?? '') === '') {
                $lines[(int) $rfqLine['id']] = null;
                continue;
            }
            $v = Validator::make($row, ['price' => 'required|money', 'lead_time_days' => 'nullable|int|between:0,999', 'note' => 'nullable|max:255']);
            if (!$v->passes()) {
                $errors['lines'] = t('rfqs.line_error', ['n' => $rfqLine['line_no'], 'error' => implode(' ', $v->errors())]);
                continue;
            }
            $d = $v->validated();
            $lines[(int) $rfqLine['id']] = ['unit_price_minor' => $d['price'], 'lead_time_days' => $d['lead_time_days'], 'note' => $d['note']];
            $priced++;
        }
        if ($priced === 0 && !isset($errors['lines'])) {
            $errors['lines'] = t('sourcing.error_no_price');
        }
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        try {
            $this->service->recordOffer((int) $id, $header, $lines, self::uploads($request));
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        return $this->redirect('/rfqs/' . $quote['rfq_id'] . '#comparison', 'sourcing.flash_offer_saved');
    }

    /**
     * Fichiers joints (champ multiple offer_files[]) remis à plat.
     *
     * @return list<array<string, mixed>>
     */
    private static function uploads(Request $request): array
    {
        $raw = $request->file('offer_files');
        if ($raw === null || !is_array($raw['name'] ?? null)) {
            return $raw === null ? [] : [$raw];
        }
        $out = [];
        foreach (array_keys($raw['name']) as $i) {
            if (($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $out[] = ['name' => $raw['name'][$i], 'type' => $raw['type'][$i] ?? '', 'tmp_name' => $raw['tmp_name'][$i], 'error' => $raw['error'][$i], 'size' => $raw['size'][$i] ?? 0];
            }
        }
        return array_slice($out, 0, 10);
    }

    /** Document « Demande de prix » remis au fournisseur : lignes et quantités demandées, sans le nom du client final. */
    public function print(Request $request, string $id): Response
    {
        $quote = $this->quotes->get((int) $id);
        $rfq = $this->rfqs->get((int) $quote['rfq_id']);
        return \App\Security\SecurityHeaders::framableBySelf($this->view('sourcing/print', [
            'title' => (string) ($quote['reference'] ?? $quote['rfq_reference']),
            'quote' => $quote,
            'rfq' => $rfq,
            'supplier' => app(\App\Repositories\SupplierRepository::class)->get((int) $quote['supplier_id']),
            'lines' => $this->quotes->requestedLines((int) $id),
            's' => app(\App\Services\Settings::class)->all(),
            'back' => url('/rfqs/' . $quote['rfq_id'] . '#sourcing'),
            'embed' => $request->str('embed') === '1',
        ], 'print'));
    }

    public function decline(Request $request, string $id): Response
    {
        $quote = $this->quotes->get((int) $id);
        try {
            $this->service->decline((int) $id);
        } catch (RuleViolation $e) {
            return $this->redirect('/rfqs/' . $quote['rfq_id'], $e->key, 'error');
        }
        return $this->redirect('/rfqs/' . $quote['rfq_id'] . '#sourcing', 'sourcing.flash_declined');
    }
}
