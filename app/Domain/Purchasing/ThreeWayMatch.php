<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

/**
 * Rapprochement PO ↔ réception ↔ facture, ligne par ligne (cumul des factures non annulées) :
 *  - over_ordered  : facturé au total > commandé ;
 *  - over_received : facturé au total > reçu (POD) — on ne paie pas ce qui n'est pas livré ;
 *  - price_above   : prix facturé > prix du PO + tolérance (un prix inférieur n'est pas un écart).
 */
final class ThreeWayMatch
{
    public const TYPES = ['over_ordered', 'over_received', 'price_above'];

    /**
     * @param list<array{line_no: int, ordered_milli: int, received_milli: int, invoiced_before_milli: int, quantity_milli: int, po_price_minor: int, price_minor: int}> $lines
     * @return list<array{type: string, line_no: int, expected: int, actual: int}>
     */
    public static function check(array $lines, int $priceToleranceBp): array
    {
        $variances = [];
        foreach ($lines as $l) {
            $total = $l['invoiced_before_milli'] + $l['quantity_milli'];
            if ($total > $l['ordered_milli']) {
                $variances[] = ['type' => 'over_ordered', 'line_no' => $l['line_no'], 'expected' => $l['ordered_milli'], 'actual' => $total];
            } elseif ($total > $l['received_milli']) {
                $variances[] = ['type' => 'over_received', 'line_no' => $l['line_no'], 'expected' => $l['received_milli'], 'actual' => $total];
            }
            if ($l['price_minor'] > self::maxPrice($l['po_price_minor'], $priceToleranceBp)) {
                $variances[] = ['type' => 'price_above', 'line_no' => $l['line_no'], 'expected' => $l['po_price_minor'], 'actual' => $l['price_minor']];
            }
        }
        return $variances;
    }

    /** Prix maximal accepté sans validation (arrondi au centime inférieur : la tolérance ne s'arrondit pas en faveur du fournisseur). */
    public static function maxPrice(int $poPriceMinor, int $toleranceBp): int
    {
        return $poPriceMinor + intdiv($poPriceMinor * max(0, $toleranceBp), 10_000);
    }
}
