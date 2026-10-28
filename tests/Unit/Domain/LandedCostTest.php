<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Finance\LandedCost;
use App\Domain\Finance\RealMargin;
use PHPUnit\Framework\TestCase;

final class LandedCostTest extends TestCase
{
    public function testProratedByValueWithoutLosingACent(): void
    {
        self::assertSame([1 => 25_000, 2 => 75_000], LandedCost::allocate(100_000, [1 => 1_000, 2 => 3_000]));
        $parts = LandedCost::allocate(100, [1 => 1, 2 => 1, 3 => 1]);
        self::assertSame(100, array_sum($parts));
        self::assertSame([34, 33, 33], array_values($parts));
    }

    public function testZeroWeightsSplitEquallyAndEmptyIsEmpty(): void
    {
        self::assertSame([7 => 50, 8 => 50], LandedCost::allocate(100, [7 => 0, 8 => 0]));
        self::assertSame([], LandedCost::allocate(100, []));
    }

    public function testLandedCostEntersMarginOnInvoicedShareOnly(): void
    {
        $l = RealMargin::line(['invoiced_milli' => 5_000, 'revenue' => 30_000, 'supplier_milli' => 5_000, 'supplier_usd' => 15_000,
            'supplier_usd_at_po' => 15_000, 'po_unit_usd' => 3_000, 'est_unit_usd' => 3_000, 'landed_usd' => 4_000, 'landed_milli' => 10_000]);
        self::assertSame(2_000, $l['landed'], '5 u. facturées sur 10 expédiées : la moitié des frais');
        self::assertSame(17_000, $l['cost']);
        self::assertSame(13_000, $l['margin']);
    }
}
