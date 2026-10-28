<?php

declare(strict_types=1);

namespace App\Domain\Finance;

/**
 * Répartition des frais logistiques d'une expédition sur ses lignes, au prorata de leur valeur d'achat (USD).
 * Méthode du plus fort reste : la somme des parts est exactement le montant réparti, sans centime perdu.
 */
final class LandedCost
{
    public const TYPES = ['transport', 'customs_duty', 'clearing', 'border_fees', 'insurance', 'other'];

    /**
     * @param array<int, int> $weights ligne → valeur (≥ 0) ; toutes nulles → parts égales
     * @return array<int, int> ligne → part du montant
     */
    public static function allocate(int $amount, array $weights): array
    {
        if ($weights === []) {
            return [];
        }
        $weights = array_map(static fn (int $w): int => max(0, $w), $weights);
        $total = array_sum($weights);
        if ($total === 0) {
            $weights = array_fill_keys(array_keys($weights), 1);
            $total = count($weights);
        }
        $parts = [];
        $remainders = [];
        foreach ($weights as $key => $w) {
            $parts[$key] = intdiv($amount * $w, $total);
            $remainders[$key] = ($amount * $w) % $total;
        }
        $left = $amount - array_sum($parts);
        arsort($remainders);
        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }
            $parts[$key]++;
            $left--;
        }
        return $parts;
    }
}
