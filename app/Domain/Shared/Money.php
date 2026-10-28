<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * Montants en centimes (entiers) : 1 250,50 → 125050. Aucun flottant dans les prix, marges et taxes.
 * Sert aussi aux pourcentages en points de base : « 16 » % → 1600 bp, « 15,5 » % → 1550 bp.
 */
final class Money
{
    public static function parse(string $value): int
    {
        $clean = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($value));
        $clean = str_replace(',', '.', $clean);
        if (!preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $clean, $m)) {
            throw new InvalidArgumentException("Montant invalide : {$value}");
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function format(int $minor, string $decimalSep = ',', string $thousandsSep = "\u{202F}"): string
    {
        $abs = abs($minor);
        $out = number_format(intdiv($abs, 100), 0, '', $thousandsSep) . $decimalSep . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
        return ($minor < 0 ? '-' : '') . $out;
    }

    /** Montant d'une ligne : quantité (millièmes) × prix unitaire (centimes), arrondi au centime. */
    public static function lineAmount(int $quantityMilli, int $unitMinor): int
    {
        return self::divRound($quantityMilli * $unitMinor, 1000);
    }

    /** Pourcentage d'un montant : 1600 bp de 10 000,00 → 1 600,00. */
    public static function percentOf(int $minor, int $basisPoints): int
    {
        return self::divRound($minor * $basisPoints, 10000);
    }

    /** Division entière arrondie au plus proche (moitié : loin de zéro). */
    public static function divRound(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Diviseur strictement positif attendu.');
        }
        $half = intdiv($denominator, 2);
        return $numerator >= 0 ? intdiv($numerator + $half, $denominator) : -intdiv(-$numerator + $half, $denominator);
    }
}
