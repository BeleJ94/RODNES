<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Finance\CashForecast;
use PHPUnit\Framework\TestCase;

final class CashForecastTest extends TestCase
{
    public function testFlowsLandInTheirWeekWithOverdueFirstAndLaterAside(): void
    {
        // Mardi 27/10/2026 : semaine 0 = lundi 26/10 → dimanche 01/11.
        $f = CashForecast::build('2026-10-27', 2, 1_000, [
            ['date' => '2026-10-10', 'amount' => 500],  // échu
            ['date' => '2026-11-01', 'amount' => 300],  // dimanche, semaine 0
            ['date' => '2026-11-02', 'amount' => 200],  // lundi, semaine 1
            ['date' => '2026-12-31', 'amount' => 999],  // au-delà
        ], [
            ['date' => '2026-10-28', 'amount' => 700],
        ]);
        self::assertSame(['overdue', 'w0', 'w1'], array_column($f['weeks'], 'key'));
        self::assertSame('2026-10-26', $f['weeks'][1]['start']);
        self::assertSame('2026-11-01', $f['weeks'][1]['end']);
        self::assertSame([500, 300, 200], array_column($f['weeks'], 'in'));
        self::assertSame([0, 700, 0], array_column($f['weeks'], 'out'));
        self::assertSame([1_500, 1_100, 1_300], array_column($f['weeks'], 'cumulative'), 'trésorerie de départ 1 000 incluse');
        self::assertSame(['in' => 999, 'out' => 0], $f['later']);
    }

    public function testMondayStartsItsOwnWeek(): void
    {
        self::assertSame('2026-11-02', CashForecast::build('2026-11-02', 1, 0, [], [])['weeks'][1]['start']);
    }

    public function testDays(): void
    {
        self::assertSame(45, CashForecast::days(50_000, 100_000, 90));
        self::assertNull(CashForecast::days(50_000, 0, 90));
    }
}
