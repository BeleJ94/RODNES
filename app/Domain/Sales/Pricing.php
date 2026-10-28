<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Money;
use InvalidArgumentException;

/**
 * Prix et marges d'une quotation (USD, centimes).
 * Coût unitaire = coût fournisseur converti + frais unitaires (transport, transit, douane…).
 * Marge exprimée sur le prix de vente : (prix − coût) / prix, en points de base.
 */
final class Pricing
{
    public static function marginBp(int $costMinor, int $priceMinor): ?int
    {
        return $priceMinor > 0 ? Money::divRound(($priceMinor - $costMinor) * 10000, $priceMinor) : null;
    }

    /** Prix de vente atteignant au moins la marge visée : coût / (1 − marge), arrondi au centime supérieur. */
    public static function priceForMargin(int $costMinor, int $marginBp): int
    {
        if ($marginBp >= 10000) {
            throw new InvalidArgumentException('Une marge doit être inférieure à 100 %.');
        }
        $denominator = 10000 - $marginBp;
        return intdiv($costMinor * 10000 + $denominator - 1, $denominator);
    }

    /**
     * @param list<array{quantity_milli: int, unit_cost_minor: int, unit_price_minor: int}> $lines
     * @return array{cost: int, subtotal: int, vat: int, total: int, margin: int, margin_bp: ?int}
     */
    public static function totals(array $lines, bool $vatApplicable, int $vatRateBp): array
    {
        $cost = 0;
        $subtotal = 0;
        foreach ($lines as $line) {
            $cost += Money::lineAmount($line['quantity_milli'], $line['unit_cost_minor']);
            $subtotal += Money::lineAmount($line['quantity_milli'], $line['unit_price_minor']);
        }
        $vat = $vatApplicable ? Money::percentOf($subtotal, $vatRateBp) : 0;
        return [
            'cost' => $cost,
            'subtotal' => $subtotal,
            'vat' => $vat,
            'total' => $subtotal + $vat,
            'margin' => $subtotal - $cost,
            'margin_bp' => self::marginBp($cost, $subtotal),
        ];
    }
}
