<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Finance\RealMargin;
use PHPUnit\Framework\TestCase;

final class RealMarginTest extends TestCase
{
    /** 10 u. facturées au client 300,00 USD HT ; coût estimé 15,00 USD l'unité. */
    private static function line(int $supMilli, int $supUsd, int $supAtPo, ?int $poUnit): array
    {
        return ['invoiced_milli' => 10_000, 'revenue' => 30_000, 'supplier_milli' => $supMilli, 'supplier_usd' => $supUsd,
            'supplier_usd_at_po' => $supAtPo, 'po_unit_usd' => $poUnit, 'est_unit_usd' => 1_500];
    }

    public function testFullyInvoicedBySupplierUsesRealCostAndShowsFxGain(): void
    {
        $l = RealMargin::line(self::line(10_000, 18_000, 20_000, 2_000));
        self::assertSame(['revenue' => 30_000, 'cost' => 18_000, 'margin' => 12_000, 'fx_effect' => -2_000, 'est_cost' => 15_000, 'landed' => 0, 'provisional' => false], $l);
    }

    public function testUncoveredQuantityFallsBackToPoThenEstimateAndIsProvisional(): void
    {
        $partial = RealMargin::line(self::line(4_000, 8_000, 8_000, 2_000));
        self::assertSame(8_000 + 12_000, $partial['cost'], '4 u. au réel + 6 u. au coût du PO');
        self::assertTrue($partial['provisional']);
        $none = RealMargin::line(self::line(0, 0, 0, null));
        self::assertSame(15_000, $none['cost'], 'sans PO : coût estimé de la quotation');
    }

    public function testSupplierInvoicedMoreThanClientIsProrated(): void
    {
        $l = RealMargin::line(self::line(20_000, 40_000, 36_000, 2_000));
        self::assertSame(20_000, $l['cost'], 'seule la moitié du facturé fournisseur concerne les 10 u. facturées');
        self::assertSame(2_000, $l['fx_effect']);
    }

    public function testTotals(): void
    {
        $t = RealMargin::total([RealMargin::line(self::line(10_000, 18_000, 20_000, 2_000)), RealMargin::line(self::line(0, 0, 0, 2_000))]);
        self::assertSame(60_000, $t['revenue']);
        self::assertSame(38_000, $t['cost']);
        self::assertSame(3_667, $t['margin_bp']);
        self::assertSame(5_000, $t['est_margin_bp']);
        self::assertSame(-2_000, $t['fx_effect']);
        self::assertTrue($t['provisional']);
        self::assertNull(RealMargin::total([])['margin_bp']);
    }
}
