<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Domain\Sales\Pricing;
use App\Services\MarginService;

/** Rapports : marge réelle par PO client facturé (prévue ↔ réelle, écart de change). */
final class ReportController extends Controller
{
    public function __construct(private MarginService $margins)
    {
    }

    public function index(Request $request): Response
    {
        $from = self::date($request->str('from'));
        $to = self::date($request->str('to'));
        $rows = $this->margins->report($from, $to);
        $revenue = array_sum(array_column($rows, 'revenue'));
        $cost = array_sum(array_column($rows, 'cost'));
        if ($request->str('format') === 'xlsx') {
            return $this->xlsx(t('reports.title'), [
                ['label' => t('export.col.client_order')], ['label' => t('export.col.client_po')], ['label' => t('export.col.client'), 'width' => 28],
                ['label' => t('export.col.first_invoice'), 'type' => Xlsx::DATE], ['label' => t('export.col.last_invoice'), 'type' => Xlsx::DATE],
                ['label' => t('export.col.revenue'), 'type' => Xlsx::MONEY], ['label' => t('export.col.cost'), 'type' => Xlsx::MONEY],
                ['label' => t('export.col.landed'), 'type' => Xlsx::MONEY], ['label' => t('export.col.margin'), 'type' => Xlsx::MONEY],
                ['label' => t('export.col.margin_pct'), 'type' => Xlsx::PERCENT], ['label' => t('export.col.planned_margin_pct'), 'type' => Xlsx::PERCENT],
                ['label' => t('export.col.fx_effect'), 'type' => Xlsx::MONEY], ['label' => t('export.col.provisional')],
            ], array_map(static fn (array $r): array => [
                $r['reference'], $r['client_po_number'], $r['client_name'], $r['first_invoice'], $r['last_invoice'], $r['revenue'], $r['cost'],
                $r['landed'] ?? 0, $r['margin'], $r['margin_bp'], $r['est_margin_bp'] ?? null, -(int) $r['fx_effect'], t($r['provisional'] ? 'common.yes' : 'common.no'),
            ], $rows));
        }
        return $this->view('reports/index', [
            'title' => t('reports.title'),
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'totals' => [
                'revenue' => $revenue,
                'cost' => $cost,
                'margin' => $revenue - $cost,
                'margin_bp' => Pricing::marginBp($cost, $revenue),
                'fx_effect' => array_sum(array_column($rows, 'fx_effect')),
                'landed' => array_sum(array_column($rows, 'landed')),
                'provisional' => count(array_filter(array_column($rows, 'provisional'))),
            ],
        ]);
    }

    private static function date(string $value): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }
}
