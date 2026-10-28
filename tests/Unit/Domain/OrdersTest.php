<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Orders\ClientOrderStatus;
use App\Domain\Orders\Coverage;
use App\Domain\Orders\SupplierOrderStatus;
use PHPUnit\Framework\TestCase;

final class OrdersTest extends TestCase
{
    public function testCoverageByLine(): void
    {
        $lines = Coverage::lines([1 => 10000, 2 => 5000, 3 => 2000], [1 => 10000, 2 => 2000]);
        self::assertSame(['ordered' => 5000, 'allocated' => 2000, 'remaining' => 3000], $lines[2]);
        self::assertSame(0, $lines[1]['remaining']);
        self::assertSame(2000, $lines[3]['remaining']);
        self::assertSame(Coverage::PARTIAL, Coverage::status($lines));
        self::assertSame(7058, Coverage::ratioBp($lines), '12 000 sur 17 000 millièmes');
    }

    public function testCoverageStatusDrivesTheClientOrderStatus(): void
    {
        self::assertSame(Coverage::NONE, Coverage::status(Coverage::lines([1 => 1000], [])));
        self::assertSame(Coverage::FULL, Coverage::status(Coverage::lines([1 => 1000, 2 => 500], [1 => 1000, 2 => 500])));
        self::assertSame(ClientOrderStatus::Received, ClientOrderStatus::fromCoverage(Coverage::NONE));
        self::assertSame(ClientOrderStatus::Ordering, ClientOrderStatus::fromCoverage(Coverage::PARTIAL));
        self::assertSame(ClientOrderStatus::Ordered, ClientOrderStatus::fromCoverage(Coverage::FULL));
        self::assertFalse(ClientOrderStatus::Closed->followsCoverage());
    }

    public function testNeverOrderMoreFromSuppliersThanTheClientOrdered(): void
    {
        self::assertTrue(Coverage::fits(10000, 4000, 6000));
        self::assertFalse(Coverage::fits(10000, 4000, 6001));
        self::assertFalse(Coverage::fits(10000, 0, 0), 'Une quantité nulle n\'est pas une commande');
    }

    public function testSupplierOrderLifecycle(): void
    {
        self::assertTrue(SupplierOrderStatus::Draft->isEditable());
        self::assertFalse(SupplierOrderStatus::Sent->isEditable());
        self::assertFalse(SupplierOrderStatus::Draft->canTransitionTo(SupplierOrderStatus::Confirmed), 'Pas de confirmation sans envoi');
        self::assertTrue(SupplierOrderStatus::Confirmed->canTransitionTo(SupplierOrderStatus::Cancelled));
        self::assertFalse(SupplierOrderStatus::Cancelled->counts(), 'Un PO annulé libère la quantité');
        self::assertSame([], SupplierOrderStatus::Cancelled->transitions());
    }
}
