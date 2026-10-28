<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Shared\Milli;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MilliTest extends TestCase
{
    public static function validInputs(): array
    {
        return [
            ['30', 30000],
            ['30,5', 30500],
            ['30.05', 30050],
            ['1 250,125', 1250125],
            ["1\u{202F}250,1", 1250100],
            ['0,001', 1],
        ];
    }

    #[DataProvider('validInputs')]
    public function testParse(string $input, int $expected): void
    {
        self::assertSame($expected, Milli::parse($input));
    }

    public static function invalidInputs(): array
    {
        return [['-1'], ['1,2345'], ['abc'], [''], ['1e3'], ['1.2.3']];
    }

    #[DataProvider('invalidInputs')]
    public function testParseRejects(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Milli::parse($input);
    }

    public function testFormat(): void
    {
        self::assertSame("1\u{202F}250,125", Milli::format(1250125));
        self::assertSame('38,000', Milli::format(38000000, 0, '.', ','));
        self::assertSame('30,50', Milli::format(30495, 2));
        self::assertSame('-0,150', Milli::format(-150));
    }
}
