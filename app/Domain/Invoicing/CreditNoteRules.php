<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Shared\Money;

/**
 * Plafonds d'un avoir, ligne de facture par ligne de facture (cumul de tous les avoirs) :
 * quantité créditée ≤ quantité facturée, prix crédité ≤ prix facturé, montant crédité cumulé ≤ montant de la ligne.
 */
final class CreditNoteRules
{
    /** @return string|null clé d'erreur, null si la ligne est acceptable */
    public static function lineError(int $invoicedMilli, int $invoicedPrice, int $alreadyCredited, int $qtyMilli, int $price): ?string
    {
        if ($qtyMilli <= 0 || $price <= 0) {
            return 'credit_notes.error_line';
        }
        if ($qtyMilli > $invoicedMilli) {
            return 'credit_notes.error_qty';
        }
        if ($price > $invoicedPrice) {
            return 'credit_notes.error_price';
        }
        if ($alreadyCredited + Money::lineAmount($qtyMilli, $price) > Money::lineAmount($invoicedMilli, $invoicedPrice)) {
            return 'credit_notes.error_over_credited';
        }
        return null;
    }

    /** Montant restant créditable d'une ligne de facture. */
    public static function creditable(int $invoicedMilli, int $invoicedPrice, int $alreadyCredited): int
    {
        return max(0, Money::lineAmount($invoicedMilli, $invoicedPrice) - $alreadyCredited);
    }
}
