<?php

declare(strict_types=1);

namespace App\Domain\Finance;

/**
 * Trésorerie prévisionnelle par semaine (lundi → dimanche) : entrées attendues, sorties à payer, solde cumulé.
 * Ce qui est déjà échu est regroupé en tête (« en retard ») ; au-delà de l'horizon, en pied (« plus tard »).
 */
final class CashForecast
{
    /**
     * @param list<array{date: string, amount: int}> $inflows
     * @param list<array{date: string, amount: int}> $outflows
     * @return array{weeks: list<array{start: ?string, end: ?string, key: string, in: int, out: int, net: int, cumulative: int}>, later: array{in: int, out: int}}
     */
    public static function build(string $today, int $weeks, int $opening, array $inflows, array $outflows): array
    {
        $monday = new \DateTimeImmutable($today . ' ' . (date('N', strtotime($today)) === '1' ? 'today' : 'last monday'));
        $buckets = [['key' => 'overdue', 'start' => null, 'end' => $monday->modify('-1 day')->format('Y-m-d'), 'in' => 0, 'out' => 0]];
        for ($w = 0; $w < $weeks; $w++) {
            $start = $monday->modify("+{$w} week");
            $buckets[] = ['key' => 'w' . $w, 'start' => $start->format('Y-m-d'), 'end' => $start->modify('+6 days')->format('Y-m-d'), 'in' => 0, 'out' => 0];
        }
        $later = ['in' => 0, 'out' => 0];
        $place = static function (string $date, int $amount, string $side) use (&$buckets, &$later): void {
            foreach ($buckets as $i => $b) {
                if ($date <= $b['end']) {
                    $buckets[$i][$side] += $amount;
                    return;
                }
            }
            $later[$side] += $amount;
        };
        foreach ($inflows as $f) {
            $place($f['date'], $f['amount'], 'in');
        }
        foreach ($outflows as $f) {
            $place($f['date'], $f['amount'], 'out');
        }
        $running = $opening;
        foreach ($buckets as $i => $b) {
            $buckets[$i]['net'] = $b['in'] - $b['out'];
            $running += $buckets[$i]['net'];
            $buckets[$i]['cumulative'] = $running;
        }
        return ['weeks' => $buckets, 'later' => $later];
    }

    /** Délai moyen (jours) = encours ÷ flux de la période × nombre de jours (DSO, DPO). */
    public static function days(int $outstanding, int $periodFlow, int $periodDays): ?int
    {
        return $periodFlow > 0 ? (int) round($outstanding * $periodDays / $periodFlow) : null;
    }
}
