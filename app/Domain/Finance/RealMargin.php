<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Sales\Pricing;
use App\Domain\Shared\Money;

/**
 * Marge réelle d'une ligne de PO client, sur ce qui a été facturé au client (HT, USD) :
 *  - coût des quantités couvertes par des factures fournisseurs = leur coût réel USD au prorata (RealCost) ;
 *  - le reste (pas encore facturé par le fournisseur) au coût engagé du PO, à défaut au coût estimé : marge « provisoire » ;
 *  - écart de change = coût réel − même coût au taux figé du PO (positif = perte) ;
 *  - frais logistiques (transport, douane…) des expéditions de la ligne, au prorata des quantités facturées.
 */
final class RealMargin
{
    /**
     * @param array{invoiced_milli: int, revenue: int, supplier_milli: int, supplier_usd: int, supplier_usd_at_po: int, po_unit_usd: ?int, est_unit_usd: int, landed_usd?: int, landed_milli?: int} $l
     * @return array{revenue: int, cost: int, margin: int, fx_effect: int, est_cost: int, landed: int, provisional: bool}
     */
    public static function line(array $l): array
    {
        $invoiced = max(0, $l['invoiced_milli']);
        $matched = min($invoiced, max(0, $l['supplier_milli']));
        $cost = 0;
        $fx = 0;
        if ($matched > 0) {
            $cost = Money::divRound($matched * $l['supplier_usd'], $l['supplier_milli']);
            $fx = Money::divRound($matched * ($l['supplier_usd'] - $l['supplier_usd_at_po']), $l['supplier_milli']);
        }
        $rest = $invoiced - $matched;
        $cost += Money::lineAmount($rest, $l['po_unit_usd'] ?? $l['est_unit_usd']);
        $landed = 0;
        if (($l['landed_milli'] ?? 0) > 0) {
            $landed = Money::divRound(min($invoiced, $l['landed_milli']) * ($l['landed_usd'] ?? 0), $l['landed_milli']);
        }
        $cost += $landed;
        return [
            'revenue' => $l['revenue'],
            'cost' => $cost,
            'margin' => $l['revenue'] - $cost,
            'fx_effect' => $fx,
            'est_cost' => Money::lineAmount($invoiced, $l['est_unit_usd']),
            'landed' => $landed,
            'provisional' => $rest > 0,
        ];
    }

    /**
     * @param list<array{revenue: int, cost: int, margin: int, fx_effect: int, est_cost: int, landed: int, provisional: bool}> $lines
     * @return array{revenue: int, cost: int, margin: int, margin_bp: ?int, est_margin_bp: ?int, fx_effect: int, landed: int, provisional: bool}
     */
    public static function total(array $lines): array
    {
        $revenue = array_sum(array_column($lines, 'revenue'));
        $cost = array_sum(array_column($lines, 'cost'));
        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'margin' => $revenue - $cost,
            'margin_bp' => Pricing::marginBp($cost, $revenue),
            'est_margin_bp' => Pricing::marginBp(array_sum(array_column($lines, 'est_cost')), $revenue),
            'fx_effect' => array_sum(array_column($lines, 'fx_effect')),
            'landed' => array_sum(array_column($lines, 'landed')),
            'provisional' => in_array(true, array_column($lines, 'provisional'), true),
        ];
    }
}
