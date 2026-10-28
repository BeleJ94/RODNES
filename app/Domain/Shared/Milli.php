<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * Quantités en millièmes d'unité stockées en entier (BIGINT) : 30,5 t → 30500.
 * Aucun flottant dans les calculs de tonnage, d'écarts ou de facturation.
 */
final class Milli
{
    /** Accepte « 30,5 », « 30.5 », « 1 250,125 » ; refuse le négatif et plus de 3 décimales. */
    public static function parse(string $value): int
    {
        $clean = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($value));
        $clean = str_replace(',', '.', $clean);
        if (!preg_match('/^(\d{1,12})(?:\.(\d{1,3}))?$/', $clean, $m)) {
            throw new InvalidArgumentException("Quantité invalide : {$value}");
        }
        return (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
    }

    public static function format(int $milli, int $decimals = 3, string $decimalSep = ',', string $thousandsSep = "\u{202F}"): string
    {
        if ($decimals < 0 || $decimals > 3) {
            throw new InvalidArgumentException('Décimales entre 0 et 3.');
        }
        $factor = 10 ** (3 - $decimals);
        $rounded = intdiv(abs($milli) + intdiv($factor, 2), $factor);
        $unit = 10 ** $decimals;
        $out = number_format(intdiv($rounded, $unit), 0, '', $thousandsSep);
        if ($decimals > 0) {
            $out .= $decimalSep . str_pad((string) ($rounded % $unit), $decimals, '0', STR_PAD_LEFT);
        }
        return ($milli < 0 && $rounded > 0 ? '-' : '') . $out;
    }
}
