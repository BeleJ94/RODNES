<?php

declare(strict_types=1);

namespace App\Domain\Sourcing;

/** Meilleure offre par ligne de RFQ, à coût unitaire converti en USD. À égalité : délai le plus court, puis première reçue. */
final class OfferComparison
{
    /**
     * @param list<array{offer_id: int, rfq_line_id: int, unit_usd_minor: int, lead_time_days: ?int}> $offers
     * @return array<int, int> rfq_line_id → offer_id
     */
    public static function best(array $offers): array
    {
        $best = [];
        foreach ($offers as $offer) {
            $line = $offer['rfq_line_id'];
            $current = $best[$line] ?? null;
            if ($current === null || self::better($offer, $current)) {
                $best[$line] = $offer;
            }
        }
        return array_map(static fn (array $o): int => $o['offer_id'], $best);
    }

    /**
     * @param array{unit_usd_minor: int, lead_time_days: ?int} $a
     * @param array{unit_usd_minor: int, lead_time_days: ?int} $b
     */
    private static function better(array $a, array $b): bool
    {
        if ($a['unit_usd_minor'] !== $b['unit_usd_minor']) {
            return $a['unit_usd_minor'] < $b['unit_usd_minor'];
        }
        return ($a['lead_time_days'] ?? PHP_INT_MAX) < ($b['lead_time_days'] ?? PHP_INT_MAX);
    }
}
