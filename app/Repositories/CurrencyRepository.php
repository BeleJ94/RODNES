<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Shared\Fx;

final class CurrencyRepository extends Repository
{
    /** @return list<array{code: string, name: string, is_active: int}> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM currencies ORDER BY code = :usd DESC, code', ['usd' => Fx::USD]);
    }

    /** @return list<string> */
    public function activeCodes(): array
    {
        return array_column($this->db->fetchAll('SELECT code FROM currencies WHERE is_active = 1 ORDER BY code = :usd DESC, code', ['usd' => Fx::USD]), 'code');
    }

    /** @return array<string, array{rate: int, date: ?string}> dernier taux connu par devise (USD = 1) */
    public function latestRates(): array
    {
        $rates = [Fx::USD => ['rate' => Fx::ONE, 'date' => null]];
        $rows = $this->db->fetchAll(
            'SELECT r.currency_code, r.units_per_usd_micro, r.rate_date
               FROM exchange_rates r
               JOIN (SELECT currency_code, MAX(rate_date) AS d FROM exchange_rates GROUP BY currency_code) last
                 ON last.currency_code = r.currency_code AND last.d = r.rate_date'
        );
        foreach ($rows as $row) {
            $rates[(string) $row['currency_code']] = ['rate' => (int) $row['units_per_usd_micro'], 'date' => (string) $row['rate_date']];
        }
        return $rates;
    }

    public function rateFor(string $currency): ?int
    {
        return $this->latestRates()[$currency]['rate'] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function history(int $limit = 60): array
    {
        return $this->db->fetchAll(
            'SELECT r.*, u.name AS created_by_name FROM exchange_rates r LEFT JOIN users u ON u.id = r.created_by
              ORDER BY r.rate_date DESC, r.currency_code LIMIT :lim',
            ['lim' => $limit],
        );
    }

    public function saveRate(string $currency, string $date, int $unitsPerUsdMicro, ?int $userId): void
    {
        $this->db->run(
            'INSERT INTO exchange_rates (currency_code, rate_date, units_per_usd_micro, created_by)
             VALUES (:c, :d, :r, :u)
             ON DUPLICATE KEY UPDATE units_per_usd_micro = VALUES(units_per_usd_micro), created_by = VALUES(created_by)',
            ['c' => $currency, 'd' => $date, 'r' => $unitsPerUsdMicro, 'u' => $userId],
        );
    }
}
