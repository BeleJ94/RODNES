<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Repositories\ClientRepository;
use App\Services\DeadlinePolicy;
use App\Services\SalesTrackingService;

/** Suivi commercial : RFQ → réponse (délai) → devis (sort, motif) → PO client, par client et par période. */
final class TrackingController extends Controller
{
    public function __construct(private SalesTrackingService $tracking, private ClientRepository $clients, private DeadlinePolicy $policy)
    {
    }

    public function index(Request $request): Response
    {
        $filters = [
            'client_id' => $request->int('client_id'),
            'from' => self::date($request->str('from')),
            'to' => self::date($request->str('to')),
            'stage' => in_array($request->str('stage'), SalesTrackingService::STAGES, true) ? $request->str('stage') : '',
        ];
        $rows = $this->tracking->rows($filters);
        if ($request->str('format') === 'xlsx') {
            return $this->xlsx(t('tracking.title'), self::exportColumns(), array_map(self::exportRow(...), $rows));
        }
        return $this->view('tracking/index', [
            'title' => t('tracking.title'),
            'rows' => $rows,
            // Indicateurs sur toute la sélection client / période, quel que soit le filtre d'étape.
            'summary' => $this->tracking->summary($filters['stage'] === '' ? $rows : $this->tracking->rows(['stage' => ''] + $filters)),
            'filters' => $filters,
            'clients' => $this->clients->options(),
            'mode' => $this->policy->mode(),
        ]);
    }

    /** @return list<array{label: string, type?: string, width?: int}> */
    public static function exportColumns(): array
    {
        return [
            ['label' => t('export.col.rfq')], ['label' => t('export.col.client'), 'width' => 28], ['label' => t('export.col.client_reference')],
            ['label' => t('export.col.received_on'), 'type' => Xlsx::DATE], ['label' => t('tracking.col.due'), 'type' => Xlsx::DATE],
            ['label' => t('tracking.col.answered_on'), 'type' => Xlsx::DATE], ['label' => t('tracking.col.response_days'), 'type' => Xlsx::INT],
            ['label' => t('tracking.col.on_time')], ['label' => t('tracking.col.late_days'), 'type' => Xlsx::INT],
            ['label' => t('export.col.quotation')], ['label' => t('export.col.status')], ['label' => t('tracking.col.amount'), 'type' => Xlsx::MONEY],
            ['label' => t('tracking.col.followups'), 'type' => Xlsx::INT], ['label' => t('tracking.col.stage')], ['label' => t('tracking.col.lost_reason')],
            ['label' => t('export.col.note'), 'width' => 30], ['label' => t('export.col.client_order')], ['label' => t('export.col.client_po')],
            ['label' => t('tracking.col.po_received'), 'type' => Xlsx::DATE], ['label' => t('export.col.owner')],
        ];
    }

    /** @param array<string, mixed> $r @return list<mixed> */
    public static function exportRow(array $r): array
    {
        return [
            $r['reference'], $r['client_name'], $r['client_reference'], $r['received_on'], $r['due_at'], $r['first_sent_at'], $r['response_days'],
            $r['on_time'] === null ? null : t($r['on_time'] ? 'common.yes' : 'common.no'), $r['late_days'] ?: null,
            $r['quotation_reference'] !== null ? $r['quotation_reference'] . ' v' . $r['version'] : null,
            $r['quotation_status'] !== null ? t('quotation_status.' . $r['quotation_status']) : null, $r['quotation_id'] !== null ? $r['quotation_amount'] : null,
            $r['followups'] ?: null, t('tracking.stage.' . $r['stage']), $r['lost_reason'] !== null ? t('lost_reason.' . $r['lost_reason']) : null,
            $r['lost_comment'] ?? $r['closed_reason'], $r['order_reference'], $r['client_po_number'], $r['order_received_on'], $r['owner_name'],
        ];
    }

    private static function date(string $value): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }
}
