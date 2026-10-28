<?php

declare(strict_types=1);

namespace App\Domain\Sales;

/**
 * Validation des quotations à marge faible : désactivée par défaut (paramètres société).
 * Activée, toute quotation dont la marge globale est sous le seuil (ou non calculable) doit être approuvée avant envoi.
 */
final class MarginApproval
{
    public static function required(bool $enabled, int $thresholdBp, ?int $marginBp): bool
    {
        return $enabled && ($marginBp === null || $marginBp < $thresholdBp);
    }
}
