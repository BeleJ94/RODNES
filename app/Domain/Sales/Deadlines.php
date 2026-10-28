<?php

declare(strict_types=1);

namespace App\Domain\Sales;

/**
 * Délais commerciaux, en jours ouvrés (lundi → vendredi) ou calendaires selon le paramètre « delay_mode ».
 * Dates au format Y-m-d. Les jours fériés ne sont pas déduits.
 */
final class Deadlines
{
    public const BUSINESS = 'business';
    public const CALENDAR = 'calendar';

    /** Date + n jours. En jours ouvrés, un départ le week-end compte à partir du lundi. */
    public static function add(string $date, int $days, string $mode): string
    {
        $d = new \DateTimeImmutable($date);
        if ($mode !== self::BUSINESS) {
            return $d->modify("+{$days} days")->format('Y-m-d');
        }
        while ($days > 0) {
            $d = $d->modify('+1 day');
            if ((int) $d->format('N') <= 5) {
                $days--;
            }
        }
        return $d->format('Y-m-d');
    }

    /** Jours écoulés entre deux dates (négatif si $to précède $from). */
    public static function between(string $from, string $to, string $mode): int
    {
        if ($to < $from) {
            return -self::between($to, $from, $mode);
        }
        $a = new \DateTimeImmutable(substr($from, 0, 10));
        $b = new \DateTimeImmutable(substr($to, 0, 10));
        if ($mode !== self::BUSINESS) {
            return (int) $a->diff($b)->days;
        }
        $n = 0;
        for ($d = $a; $d < $b; $d = $d->modify('+1 day')) {
            if ((int) $d->modify('+1 day')->format('N') <= 5) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Prochaine relance due : le rythme « 7,14 » compte depuis le point de départ (envoi, acceptation…).
     * Renvoie null quand toutes les relances prévues ont été faites.
     *
     * @param list<int> $schedule jours après le départ, croissants
     */
    public static function nextFollowup(string $start, array $schedule, int $done, string $mode): ?string
    {
        return isset($schedule[$done]) ? self::add(substr($start, 0, 10), $schedule[$done], $mode) : null;
    }

    /** « 7, 14 » → [7, 14] ; null si la saisie est invalide (entiers 1–365, croissants, 5 au plus). */
    public static function parseSchedule(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        $days = [];
        foreach (preg_split('/[\s,;]+/', $value) ?: [] as $part) {
            if (!preg_match('/^\d{1,3}$/', $part) || (int) $part < 1 || (int) $part > 365) {
                return null;
            }
            $days[] = (int) $part;
        }
        $sorted = $days;
        sort($sorted);
        return $sorted === $days && count(array_unique($days)) === count($days) && count($days) <= 5 ? $days : null;
    }
}
