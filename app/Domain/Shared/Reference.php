<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/** Références lisibles, séquentielles par type et par année : RFQ-2026-0007, QT-2026-0012. */
final class Reference
{
    public static function format(string $prefix, int $year, int $sequence): string
    {
        return sprintf('%s-%04d-%04d', $prefix, $year, $sequence);
    }
}
