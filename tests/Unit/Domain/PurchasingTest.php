<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Purchasing\SupplierInvoiceStatus as S;
use App\Domain\Purchasing\ThreeWayMatch;
use PHPUnit\Framework\TestCase;

final class PurchasingTest extends TestCase
{
    /** @return array{line_no: int, ordered_milli: int, received_milli: int, invoiced_before_milli: int, quantity_milli: int, po_price_minor: int, price_minor: int} */
    private static function line(int $qty, int $price = 1000, int $received = 10_000, int $before = 0): array
    {
        return ['line_no' => 1, 'ordered_milli' => 10_000, 'received_milli' => $received, 'invoiced_before_milli' => $before,
            'quantity_milli' => $qty, 'po_price_minor' => 1000, 'price_minor' => $price];
    }

    public function testConformingInvoiceHasNoVariance(): void
    {
        self::assertSame([], ThreeWayMatch::check([self::line(10_000)], 0));
        self::assertSame([], ThreeWayMatch::check([self::line(4_000, 900, 4_000)], 0), 'prix inférieur au PO : pas un écart');
    }

    public function testQuantityBeyondReceiptOrOrder(): void
    {
        $v = ThreeWayMatch::check([self::line(5_000, 1000, 8_000, 4_000)], 0);
        self::assertSame([['type' => 'over_received', 'line_no' => 1, 'expected' => 8_000, 'actual' => 9_000]], $v);
        $v = ThreeWayMatch::check([self::line(12_000, 1000, 12_000)], 0);
        self::assertSame('over_ordered', $v[0]['type']);
        self::assertCount(1, $v, 'un dépassement de commande ne double pas avec le dépassement de réception');
    }

    public function testPriceToleranceIsInclusiveAndRoundsDown(): void
    {
        self::assertSame(1010, ThreeWayMatch::maxPrice(1000, 100));
        self::assertSame(1100, ThreeWayMatch::maxPrice(1099, 10), '10,99 + 0,1 % = 11,00099 → tronqué à 11,00');
        self::assertSame([], ThreeWayMatch::check([self::line(10_000, 1010)], 100));
        self::assertSame('price_above', ThreeWayMatch::check([self::line(10_000, 1011)], 100)[0]['type']);
    }

    public function testStatusRules(): void
    {
        self::assertTrue(S::OnHold->isOpen());
        self::assertFalse(S::OnHold->isPayable());
        self::assertTrue(S::Approved->isPayable());
        self::assertFalse(S::PartiallyPaid->canCancel());
        self::assertFalse(S::Paid->isOpen());
        self::assertSame('supplier_invoice_status.on_hold', S::OnHold->labelKey());
    }
}
