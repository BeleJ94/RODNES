<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Sales\MarginApproval;
use App\Domain\Sales\Pricing;
use App\Domain\Sales\QuotationStatus;
use App\Domain\Sales\RfqStatus;
use App\Domain\Shared\Fx;
use App\Domain\Shared\RuleViolation;
use App\Domain\Sourcing\OfferComparison;
use App\Repositories\ClientRepository;
use App\Repositories\CurrencyRepository;
use App\Repositories\QuotationRepository;
use App\Repositories\RfqRepository;
use App\Repositories\SupplierQuoteRepository;
use App\Security\Auth;

final class QuotationService
{
    public const ACTIONS = ['send', 'approve', 'reject_approval', 'reopen', 'accept', 'decline'];

    /** Motifs d'un devis perdu ou sans suite ; « other » exige un commentaire. */
    public const LOST_REASONS = ['price', 'lead_time', 'competitor', 'specs', 'project_cancelled', 'no_response', 'other'];

    public function __construct(
        private Database $db,
        private QuotationRepository $quotations,
        private RfqRepository $rfqs,
        private ClientRepository $clients,
        private SupplierQuoteRepository $offers,
        private CurrencyRepository $currencies,
        private RfqService $rfqService,
        private Sequences $sequences,
        private Settings $settings,
        private ActivityLogger $logger,
        private Auth $auth,
        private DeadlinePolicy $policy,
    ) {
    }

    /**
     * Brouillon prérempli : pour chaque ligne, l'offre la moins chère (convertie en USD au dernier taux)
     * et un prix de vente à la marge par défaut.
     */
    public function createFromRfq(int $rfqId): int
    {
        return $this->db->transaction(function () use ($rfqId): int {
            $rfq = $this->rfqs->get($rfqId);
            if (RfqStatus::from((string) $rfq['status'])->isClosed()) {
                throw new RuleViolation('rfqs.error_closed');
            }
            $active = (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM quotations WHERE rfq_id = :id AND status NOT IN ('rejected', 'superseded')",
                ['id' => $rfqId],
            );
            if ($active > 0) {
                throw new RuleViolation('quotations.error_exists');
            }
            $client = $this->clients->get((int) $rfq['client_id']);
            $offers = $this->convertedOffers($rfqId);
            $best = OfferComparison::best(array_values(array_map(static fn (array $o): array => [
                'offer_id' => (int) $o['id'], 'rfq_line_id' => (int) $o['rfq_line_id'],
                'unit_usd_minor' => $o['unit_usd_minor'], 'lead_time_days' => $o['lead_time_days'] !== null ? (int) $o['lead_time_days'] : null,
            ], $offers)));

            $reference = $this->sequences->next('quotation', 'QT');
            $id = $this->db->insert(
                'INSERT INTO quotations (reference, version, rfq_id, client_id, valid_until, incoterm, payment_terms_days, payment_terms_basis, vat_applicable, vat_rate_bp,
                        ship_to_name, ship_to_address, ship_to_phone, created_by)
                 VALUES (:ref, 1, :rfq, :client, CURDATE() + INTERVAL :validity DAY, :incoterm, :terms, :basis, :vat, :vat_bp, :ship_name, :ship_address, :ship_phone, :user)',
                [
                    'ref' => $reference, 'rfq' => $rfqId, 'client' => (int) $client['id'],
                    'validity' => $this->policy->quoteValidityDays((int) $client['id']), 'incoterm' => 'DAP',
                    'terms' => (int) $client['payment_terms_days'], 'vat' => (bool) $client['vat_applicable'],
                    'vat_bp' => $this->settings->int('vat_rate_bp'), 'user' => $this->auth->user()?->id,
                    'basis' => $this->settings->get('quote_payment_basis') === 'invoice' ? 'invoice' : 'delivery',
                    // Livraison : site de la RFQ, sinon adresse du client.
                    'ship_name' => $client['name'],
                    'ship_address' => ($rfq['delivery_site'] ?? '') !== '' ? $rfq['delivery_site'] : (trim(implode(', ', array_filter([$client['address'], $client['city']]))) ?: null),
                    'ship_phone' => $client['contact_phone'] ?: null,
                ],
            );
            $margin = $this->settings->int('default_margin_bp');
            foreach ($this->rfqs->lines($rfqId) as $i => $line) {
                $offer = isset($best[(int) $line['id']]) ? $offers[$best[(int) $line['id']]] : null;
                $cost = $offer['unit_usd_minor'] ?? 0;
                $this->insertLine($id, $i + 1, [
                    'rfq_line_id' => (int) $line['id'],
                    'supplier_quote_line_id' => $offer !== null ? (int) $offer['id'] : null,
                    'description' => $line['description'],
                    'quantity_milli' => (int) $line['quantity_milli'],
                    'unit' => $line['unit'],
                    'availability' => $offer !== null && $offer['lead_time_days'] !== null ? self::availability((int) $offer['lead_time_days']) : null,
                    'cost_currency' => $offer['currency_code'] ?? Fx::USD,
                    'cost_unit_minor' => $offer !== null ? (int) $offer['unit_price_minor'] : 0,
                    'fx_units_per_usd_micro' => $offer['rate'] ?? Fx::ONE,
                    'cost_unit_usd_minor' => $cost,
                    'extra_unit_usd_minor' => 0,
                    'unit_price_minor' => $cost > 0 ? Pricing::priceForMargin($cost, $margin) : 0,
                ]);
            }
            $this->logger->log('quotation', $id, 'created', [], ['reference' => $reference, 'version' => 1, 'rfq_id' => $rfqId]);
            return $id;
        });
    }

    /**
     * Brouillon uniquement. Le coût d'une ligne liée à une offre est recalculé côté serveur depuis l'offre et le dernier taux ;
     * sans offre, le coût saisi (USD) fait foi.
     *
     * @param array{valid_until: string, incoterm: ?string, delivery_terms: ?string, payment_terms_days: int, vat_applicable: bool, notes: ?string} $header
     * @param array<int, array{description: string, quantity_milli: int, supplier_quote_line_id: ?int, cost_unit_usd_minor: int, extra_unit_usd_minor: int, unit_price_minor: int}> $lines id de ligne → valeurs
     */
    public function update(int $id, array $header, array $lines): void
    {
        $this->db->transaction(function () use ($id, $header, $lines): void {
            $quotation = $this->quotations->get($id);
            if (!QuotationStatus::from((string) $quotation['status'])->isEditable()) {
                throw new RuleViolation('quotations.error_not_draft');
            }
            $this->db->run(
                'UPDATE quotations SET valid_until = :valid, incoterm = :incoterm, delivery_terms = :delivery, payment_terms_days = :terms,
                        payment_terms_basis = :basis, vat_applicable = :vat, notes = :notes,
                        ship_to_name = :ship_name, ship_to_address = :ship_address, ship_to_phone = :ship_phone, country_of_origin = :origin,
                        estimated_ship_date = :ship_date, transport_mode = :transport, carrier = :carrier, packages_count = :packages,
                        gross_weight_milli = :gross, net_weight_milli = :net
                  WHERE id = :id',
                [
                    'valid' => $header['valid_until'], 'incoterm' => $header['incoterm'], 'delivery' => $header['delivery_terms'],
                    'terms' => $header['payment_terms_days'], 'basis' => $header['payment_terms_basis'] ?? 'delivery', 'vat' => $header['vat_applicable'], 'notes' => $header['notes'],
                    'ship_name' => $header['ship_to_name'] ?? null, 'ship_address' => $header['ship_to_address'] ?? null, 'ship_phone' => $header['ship_to_phone'] ?? null,
                    'origin' => $header['country_of_origin'] ?? null, 'ship_date' => $header['estimated_ship_date'] ?? null, 'transport' => $header['transport_mode'] ?? null,
                    'carrier' => $header['carrier'] ?? null, 'packages' => $header['packages_count'] ?? null,
                    'gross' => $header['gross_weight_milli'] ?? null, 'net' => $header['net_weight_milli'] ?? null, 'id' => $id,
                ],
            );
            $offers = $this->convertedOffers((int) $quotation['rfq_id']);
            $before = [];
            foreach ($this->quotations->lines($id) as $current) {
                $lineId = (int) $current['id'];
                $before["line_{$current['line_no']}"] = $current['unit_price_minor'];
                if (!isset($lines[$lineId])) {
                    continue;
                }
                $input = $lines[$lineId];
                $offer = $input['supplier_quote_line_id'] !== null ? ($offers[$input['supplier_quote_line_id']] ?? null) : null;
                if ($offer !== null && $current['rfq_line_id'] !== null && (int) $offer['rfq_line_id'] !== (int) $current['rfq_line_id']) {
                    $offer = null;
                }
                $this->db->run(
                    'UPDATE quotation_lines SET description = :description, quantity_milli = :qty, availability = :availability, supplier_quote_line_id = :sq,
                            cost_currency = :currency, cost_unit_minor = :cost_orig, fx_units_per_usd_micro = :fx, cost_unit_usd_minor = :cost,
                            extra_unit_usd_minor = :extra, unit_price_minor = :price
                      WHERE id = :id',
                    [
                        'description' => $input['description'], 'qty' => $input['quantity_milli'], 'availability' => $input['availability'] ?? null,
                        'sq' => $offer !== null ? (int) $offer['id'] : null,
                        'currency' => $offer['currency_code'] ?? Fx::USD,
                        'cost_orig' => $offer !== null ? (int) $offer['unit_price_minor'] : $input['cost_unit_usd_minor'],
                        'fx' => $offer['rate'] ?? Fx::ONE,
                        'cost' => $offer['unit_usd_minor'] ?? $input['cost_unit_usd_minor'],
                        'extra' => $input['extra_unit_usd_minor'], 'price' => $input['unit_price_minor'], 'id' => $lineId,
                    ],
                );
            }
            $this->logger->log('quotation', $id, 'updated', array_intersect_key($quotation, $header), $header);
        });
    }

    /** @return string clé du message de confirmation */
    /** @param array{reason?: string, comment?: ?string} $lost motif obligatoire pour « decline » */
    public function transition(int $id, string $action, array $lost = []): string
    {
        return $this->db->transaction(function () use ($id, $action, $lost): string {
            $quotation = $this->quotations->get($id);
            $from = QuotationStatus::from((string) $quotation['status']);
            $user = $this->auth->user();
            [$to, $message] = match ($action) {
                'send' => $from === QuotationStatus::Draft && $this->approvalRequired($id)
                    ? [QuotationStatus::PendingApproval, 'quotations.flash_approval_requested']
                    : [QuotationStatus::Sent, 'quotations.flash_sent'],
                'approve' => [QuotationStatus::Approved, 'quotations.flash_approved'],
                'reject_approval', 'reopen' => [QuotationStatus::Draft, 'quotations.flash_reopened'],
                'accept' => [QuotationStatus::Accepted, 'quotations.flash_accepted'],
                'decline' => [QuotationStatus::Rejected, 'quotations.flash_declined'],
                default => throw new RuleViolation('common.error_transition'),
            };
            if (!$from->canTransitionTo($to)) {
                throw new RuleViolation('common.error_transition');
            }
            if ($action === 'approve' && !($user?->can('quotations.approve') ?? false)) {
                throw new RuleViolation('quotations.error_cannot_approve');
            }
            $fields = ['status' => $to->value];
            if ($to === QuotationStatus::Rejected) {
                $reason = $lost['reason'] ?? '';
                $comment = trim((string) ($lost['comment'] ?? '')) ?: null;
                if (!in_array($reason, self::LOST_REASONS, true)) {
                    throw new RuleViolation('quotations.error_lost_reason');
                }
                if ($reason === 'other' && $comment === null) {
                    throw new RuleViolation('quotations.error_lost_comment');
                }
                $fields += ['lost_reason' => $reason, 'lost_comment' => $comment !== null ? mb_substr($comment, 0, 255) : null];
            }
            match ($to) {
                QuotationStatus::Approved => $fields += ['approved_by' => $user?->id, 'approved_at' => date('Y-m-d H:i:s')],
                QuotationStatus::Sent => $fields += ['sent_at' => date('Y-m-d H:i:s')],
                QuotationStatus::Accepted, QuotationStatus::Rejected => $fields += ['decided_at' => date('Y-m-d H:i:s')],
                QuotationStatus::Draft => $fields += ['approved_by' => null, 'approved_at' => null],
                default => null,
            };
            $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($fields)));
            $this->db->run("UPDATE quotations SET {$set} WHERE id = :id", $fields + ['id' => $id]);
            $this->logger->log('quotation', $id, $action, ['status' => $from->value], $fields);

            if ($to === QuotationStatus::Sent) {
                $this->rfqService->advance((int) $quotation['rfq_id'], RfqStatus::Quoted);
            } elseif ($to === QuotationStatus::Accepted) {
                $this->rfqService->advance((int) $quotation['rfq_id'], RfqStatus::Won);
            }
            return $message;
        });
    }

    /** Nouvelle version (brouillon) d'une quotation envoyée ou refusée ; l'ancienne passe en « remplacée ». */
    public function revise(int $id): int
    {
        return $this->db->transaction(function () use ($id): int {
            $old = $this->quotations->get($id);
            $from = QuotationStatus::from((string) $old['status']);
            if (!$from->canBeRevised()) {
                throw new RuleViolation('common.error_transition');
            }
            $version = (int) $this->db->fetchValue('SELECT MAX(version) FROM quotations WHERE reference = :r', ['r' => $old['reference']]) + 1;
            $newId = $this->db->insert(
                'INSERT INTO quotations (reference, version, rfq_id, client_id, valid_until, incoterm, delivery_terms, payment_terms_days, payment_terms_basis, vat_applicable, vat_rate_bp, notes,
                        ship_to_name, ship_to_address, ship_to_phone, country_of_origin, estimated_ship_date, transport_mode, carrier, packages_count, gross_weight_milli, net_weight_milli, created_by)
                 SELECT reference, :version, rfq_id, client_id, GREATEST(valid_until, CURDATE() + INTERVAL :validity DAY), incoterm, delivery_terms,
                        payment_terms_days, payment_terms_basis, vat_applicable, vat_rate_bp, notes,
                        ship_to_name, ship_to_address, ship_to_phone, country_of_origin, estimated_ship_date, transport_mode, carrier, packages_count, gross_weight_milli, net_weight_milli, :user
                   FROM quotations WHERE id = :id',
                ['version' => $version, 'validity' => $this->policy->quoteValidityDays((int) $old['client_id']), 'user' => $this->auth->user()?->id, 'id' => $id],
            );
            $this->db->run(
                'INSERT INTO quotation_lines (quotation_id, line_no, rfq_line_id, supplier_quote_line_id, description, quantity_milli, unit, availability,
                        cost_currency, cost_unit_minor, fx_units_per_usd_micro, cost_unit_usd_minor, extra_unit_usd_minor, unit_price_minor)
                 SELECT :new, line_no, rfq_line_id, supplier_quote_line_id, description, quantity_milli, unit, availability,
                        cost_currency, cost_unit_minor, fx_units_per_usd_micro, cost_unit_usd_minor, extra_unit_usd_minor, unit_price_minor
                   FROM quotation_lines WHERE quotation_id = :old ORDER BY line_no',
                ['new' => $newId, 'old' => $id],
            );
            $this->db->run("UPDATE quotations SET status = 'superseded' WHERE id = :id", ['id' => $id]);
            $this->logger->log('quotation', $id, 'superseded', ['status' => $from->value], ['status' => 'superseded']);
            $this->logger->log('quotation', $newId, 'created', [], ['reference' => $old['reference'], 'version' => $version]);
            return $newId;
        });
    }

    /** @return array{cost: int, subtotal: int, vat: int, total: int, margin: int, margin_bp: ?int} */
    public function totals(int $id): array
    {
        $quotation = $this->quotations->get($id);
        return Pricing::totals(
            array_map(static fn (array $l): array => [
                'quantity_milli' => (int) $l['quantity_milli'],
                'unit_cost_minor' => (int) $l['cost_unit_usd_minor'] + (int) $l['extra_unit_usd_minor'],
                'unit_price_minor' => (int) $l['unit_price_minor'],
            ], $this->quotations->lines($id)),
            (bool) $quotation['vat_applicable'],
            (int) $quotation['vat_rate_bp'],
        );
    }

    public function approvalRequired(int $id): bool
    {
        return MarginApproval::required(
            $this->settings->bool('margin_approval_enabled'),
            $this->settings->int('margin_approval_threshold_bp'),
            $this->totals($id)['margin_bp'],
        );
    }

    /**
     * Offres reçues pour la RFQ, converties en USD au dernier taux connu (offres sans taux écartées).
     *
     * @return array<int, array<string, mixed>> id de ligne d'offre → offre + rate + unit_usd_minor
     */
    public function convertedOffers(int $rfqId): array
    {
        $rates = $this->currencies->latestRates();
        $out = [];
        foreach ($this->offers->offersForRfq($rfqId) as $offer) {
            $rate = $rates[(string) $offer['currency_code']]['rate'] ?? null;
            if ($rate === null) {
                continue;
            }
            $out[(int) $offer['id']] = $offer + ['rate' => $rate, 'unit_usd_minor' => Fx::toUsd((int) $offer['unit_price_minor'], $rate)];
        }
        return $out;
    }

    /** @param array<string, mixed> $line */
    /** Disponibilité annoncée au client d'après le délai du fournisseur : « 2 semaines », « 5 jours ». */
    public static function availability(int $days): string
    {
        if ($days >= 7 && $days % 7 === 0) {
            $weeks = intdiv($days, 7);
            return t($weeks === 1 ? 'quotations.availability_week' : 'quotations.availability_weeks', ['n' => $weeks]);
        }
        return t($days === 1 ? 'quotations.availability_day' : 'quotations.availability_days', ['n' => $days]);
    }

    private function insertLine(int $quotationId, int $lineNo, array $line): void
    {
        $columns = array_keys($line);
        $this->db->run(
            'INSERT INTO quotation_lines (quotation_id, line_no, ' . implode(', ', $columns) . ')
             VALUES (:quotation_id, :line_no, :' . implode(', :', $columns) . ')',
            $line + ['quotation_id' => $quotationId, 'line_no' => $lineNo],
        );
    }
}
