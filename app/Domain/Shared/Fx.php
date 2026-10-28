<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * Change vers l'USD (monnaie de base). Taux = unités de devise pour 1 USD, en millionièmes :
 * ZAR 18,25 → 18 250 000 ; USD → 1 000 000.
 */
final class Fx
{
    public const USD = 'USD';
    public const ONE = 1_000_000;

    public static function toUsd(int $minor, int $unitsPerUsdMicro): int
    {
        if ($unitsPerUsdMicro <= 0) {
            throw new InvalidArgumentException('Taux de change invalide.');
        }
        return Money::divRound($minor * self::ONE, $unitsPerUsdMicro);
    }

    public static function parseRate(string $value): int
    {
        $clean = str_replace(',', '.', str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($value)));
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,6}))?$/', $clean, $m)) {
            throw new InvalidArgumentException("Taux invalide : {$value}");
        }
        $rate = (int) $m[1] * self::ONE + (int) str_pad($m[2] ?? '', 6, '0');
        if ($rate === 0) {
            throw new InvalidArgumentException('Le taux doit être positif.');
        }
        return $rate;
    }

    public static function formatRate(int $micro, string $decimalSep = ','): string
    {
        $text = intdiv($micro, self::ONE) . $decimalSep . str_pad((string) ($micro % self::ONE), 6, '0', STR_PAD_LEFT);
        return rtrim(rtrim($text, '0'), $decimalSep);
    }
}
