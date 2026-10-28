<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Sales\Deadlines;
use PHPUnit\Framework\TestCase;

final class DeadlinesTest extends TestCase
{
    public function testBusinessDaysSkipWeekends(): void
    {
        // Jeudi 29/10/2026 + 3 jours ouvrés = mardi 03/11.
        self::assertSame('2026-11-03', Deadlines::add('2026-10-29', 3, Deadlines::BUSINESS));
        self::assertSame('2026-11-01', Deadlines::add('2026-10-29', 3, Deadlines::CALENDAR));
        // Reçue un samedi : + 1 jour ouvré = lundi.
        self::assertSame('2026-11-02', Deadlines::add('2026-10-31', 1, Deadlines::BUSINESS));
        self::assertSame('2026-10-29', Deadlines::add('2026-10-29', 0, Deadlines::BUSINESS));
    }

    public function testElapsedDays(): void
    {
        self::assertSame(3, Deadlines::between('2026-10-29', '2026-11-03', Deadlines::BUSINESS));
        self::assertSame(5, Deadlines::between('2026-10-29', '2026-11-03', Deadlines::CALENDAR));
        self::assertSame(0, Deadlines::between('2026-10-29', '2026-10-29 16:00:00', Deadlines::BUSINESS));
        self::assertSame(-3, Deadlines::between('2026-11-03', '2026-10-29', Deadlines::BUSINESS));
        self::assertSame(0, Deadlines::between('2026-10-31', '2026-11-01', Deadlines::BUSINESS)); // samedi → dimanche
    }

    public function testNextFollowupFollowsTheScheduleThenStops(): void
    {
        $sent = '2026-10-26 10:15:00'; // lundi
        self::assertSame('2026-11-04', Deadlines::nextFollowup($sent, [7, 14], 0, Deadlines::BUSINESS));
        self::assertSame('2026-11-13', Deadlines::nextFollowup($sent, [7, 14], 1, Deadlines::BUSINESS));
        self::assertNull(Deadlines::nextFollowup($sent, [7, 14], 2, Deadlines::BUSINESS));
        self::assertNull(Deadlines::nextFollowup($sent, [], 0, Deadlines::BUSINESS));
    }

    public function testScheduleParsing(): void
    {
        self::assertSame([7, 14], Deadlines::parseSchedule(' 7, 14 '));
        self::assertSame([3, 7, 10], Deadlines::parseSchedule('3;7 10'));
        self::assertSame([], Deadlines::parseSchedule(''));
        self::assertNull(Deadlines::parseSchedule('14, 7'));
        self::assertNull(Deadlines::parseSchedule('7, 7'));
        self::assertNull(Deadlines::parseSchedule('0'));
        self::assertNull(Deadlines::parseSchedule('a'));
        self::assertNull(Deadlines::parseSchedule('1,2,3,4,5,6'));
    }
}
