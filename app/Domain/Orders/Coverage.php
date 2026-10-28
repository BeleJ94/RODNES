<?php

declare(strict_types=1);

namespace App\Domain\Orders;

/**
 * Couverture d'un PO client par les PO fournisseurs (hors PO annulés) : quantité commandée par le client
 * face à la quantité déjà commandée aux fournisseurs, ligne par ligne. Jamais plus que ce que le client a commandé.
 */
final class Coverage
{
    public const NONE = 'none';
    public const PARTIAL = 'partial';
    public const FULL = 'full';

    /**
     * @param array<int, int> $ordered   ligne client → quantité (millièmes)
     * @param array<int, int> $allocated ligne client → quantité déjà commandée aux fournisseurs
     * @return array<int, array{ordered: int, allocated: int, remaining: int}>
     */
    public static function lines(array $ordered, array $allocated): array
    {
        $out = [];
        foreach ($ordered as $lineId => $qty) {
            $done = $allocated[$lineId] ?? 0;
            $out[$lineId] = ['ordered' => $qty, 'allocated' => $done, 'remaining' => max(0, $qty - $done)];
        }
        return $out;
    }

    /** @param array<int, array{ordered: int, allocated: int, remaining: int}> $lines */
    public static function status(array $lines): string
    {
        if ($lines === []) {
            return self::NONE;
        }
        $remaining = array_sum(array_column($lines, 'remaining'));
        $allocated = array_sum(array_column($lines, 'allocated'));
        return match (true) {
            $remaining === 0 => self::FULL,
            $allocated === 0 => self::NONE,
            default => self::PARTIAL,
        };
    }

    /** Pourcentage couvert (points de base), pondéré par les quantités. */
    public static function ratioBp(array $lines): int
    {
        $ordered = array_sum(array_column($lines, 'ordered'));
        if ($ordered === 0) {
            return 0;
        }
        $covered = 0;
        foreach ($lines as $line) {
            $covered += min($line['allocated'], $line['ordered']);
        }
        return intdiv($covered * 10000, $ordered);
    }

    /** Une allocation supplémentaire reste-t-elle dans la quantité commandée par le client ? */
    public static function fits(int $ordered, int $alreadyAllocated, int $adding): bool
    {
        return $adding > 0 && $alreadyAllocated + $adding <= $ordered;
    }
}
