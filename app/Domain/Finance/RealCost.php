<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Shared\Fx;
use App\Domain\Shared\Money;

/**
 * Coût réel en USD d'une facture fournisseur, hors TVA (récupérable) :
 * la part payée au taux de chaque paiement, le reste au taux de la facture.
 * Les paiements couvrent le TTC : on en retient la quote-part HT (subtotal / total).
 */
final class RealCost
{
    /** @param list<array{amount: int, fx: int}> $payments imputations sur cette facture, dans sa devise */
    public static function invoiceUsd(int $subtotal, int $total, int $invoiceFx, array $payments): int
    {
        if ($total <= 0) {
            return 0;
        }
        $usd = 0;
        $paid = 0;
        foreach ($payments as $p) {
            $amount = min($p['amount'], $total - $paid);
            if ($amount <= 0) {
                break;
            }
            $paid += $amount;
            $usd += Fx::toUsd(Money::divRound($amount * $subtotal, $total), $p['fx']);
        }
        $rest = $subtotal - Money::divRound($paid * $subtotal, $total);
        return $usd + ($rest > 0 ? Fx::toUsd($rest, $invoiceFx) : 0);
    }

    /** Part d'un montant de ligne (devise) dans le coût USD de sa facture, au prorata du HT. */
    public static function shareUsd(int $lineAmount, int $subtotal, int $invoiceUsd): int
    {
        return $subtotal > 0 ? Money::divRound($lineAmount * $invoiceUsd, $subtotal) : 0;
    }
}
