<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Domain\Sales\Pricing;
use App\Domain\Shared\Money;
use App\Services\FinanceDashboardService;

final class FinanceDashboardController extends Controller
{
    public function __construct(private FinanceDashboardService $finance)
    {
    }

    public function index(Request $request): Response
    {
        // Trésorerie disponible saisie par l'utilisateur (aucun module bancaire) : point de départ du solde cumulé.
        $cashInput = trim($request->str('cash'));
        $opening = 0;
        if ($cashInput !== '') {
            try {
                $opening = Money::parse($cashInput);
            } catch (\InvalidArgumentException) {
                $cashInput = '';
            }
        }
        $data = [
            'forecast' => $this->finance->forecast($opening),
            'monthly' => $this->finance->monthly(),
            'suppliers' => $this->finance->topSuppliers(),
        ];
        if ($request->str('format') === 'xlsx') {
            return $this->export($data);
        }
        return $this->view('finance/index', $data + [
            'title' => t('finance.title'),
            'cashInput' => $cashInput,
            'kpi' => $this->finance->indicators(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function export(array $data): Response
    {
        $usd = static fn (string $key): array => ['label' => t($key), 'type' => Xlsx::MONEY];
        $meta = static fn (string $sheet, string $sub = ''): array => self::exportMeta(t('finance.title') . ' — ' . $sheet, $sub);
        $book = (new Xlsx())
            ->sheet(t('export.sheet.cash'), [
                ['label' => t('finance.col.week'), 'width' => 16], ['label' => t('export.col.from'), 'type' => Xlsx::DATE], ['label' => t('export.col.to'), 'type' => Xlsx::DATE],
                $usd('finance.series.in'), $usd('finance.series.out'), $usd('finance.col.net'), $usd('finance.series.cumulative'),
            ], array_map(static fn (array $w): array => [
                $w['key'] === 'overdue' ? t('finance.week_overdue') : t('finance.week_label', ['n' => date('W', strtotime($w['start'])), 'date' => date('d/m', strtotime($w['start']))]),
                $w['start'], $w['end'], $w['in'], $w['out'], $w['net'], $w['cumulative'],
            ], $data['forecast']['weeks']), $meta(t('export.sheet.cash'), t('finance.cash_on_hand') . ' : ' . format_money((int) $data['forecast']['opening'], 'USD')))
            ->sheet(t('export.sheet.months'), [
                ['label' => t('finance.col.month')], $usd('finance.series.revenue'), $usd('finance.series.margin'), ['label' => t('export.col.margin_pct'), 'type' => Xlsx::PERCENT],
            ], array_map(static fn (array $m): array => [
                $m['month'], $m['revenue'], $m['margin'], $m['revenue'] > 0 ? Pricing::marginBp($m['revenue'] - $m['margin'], $m['revenue']) : null,
            ], $data['monthly']['months']), $meta(t('export.sheet.months')))
            ->sheet(t('finance.top_clients'), [
                ['label' => t('export.col.client'), 'width' => 28], $usd('finance.series.revenue'), $usd('finance.series.margin'), ['label' => t('export.col.margin_pct'), 'type' => Xlsx::PERCENT],
            ], array_map(static fn (array $c): array => [$c['name'], $c['revenue'], $c['margin'], $c['margin_bp']], $data['monthly']['clients']), $meta(t('finance.top_clients')))
            ->sheet(t('finance.top_suppliers'), [
                ['label' => t('export.col.supplier'), 'width' => 28], $usd('logistics.kind_goods'), $usd('logistics.kind'), $usd('export.col.total'),
            ], array_map(static fn (array $s): array => [$s['name'], $s['goods'], $s['logistics'], $s['total']], $data['suppliers']), $meta(t('finance.top_suppliers')));
        return self::workbook(t('finance.title'), $book);
    }
}
