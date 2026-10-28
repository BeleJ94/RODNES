<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Logistics\ShipmentStatus as S;
use App\Domain\Orders\ClientOrderStatus;
use App\Domain\Orders\Coverage;
use PHPUnit\Framework\TestCase;

final class ShipmentTest extends TestCase
{
    public function testMilestonesOnlyMoveForwardAndCanBeSkipped(): void
    {
        self::assertSame([S::PickedUp, S::InTransit, S::AtBorder, S::Cleared], S::Planned->nextMilestones());
        self::assertTrue(S::PickedUp->canAdvanceTo(S::Cleared), 'Livraison locale : pas de frontière');
        self::assertFalse(S::AtBorder->canAdvanceTo(S::InTransit), 'Jamais en arrière');
        self::assertFalse(S::Cleared->canAdvanceTo(S::Delivered), 'La livraison passe par la POD');
        self::assertSame([], S::Delivered->nextMilestones());
    }

    public function testPodRequiresCollectedGoodsAndCancellationOnlyBeforePickup(): void
    {
        self::assertFalse(S::Planned->canReceivePod());
        self::assertTrue(S::InTransit->canReceivePod());
        self::assertFalse(S::Delivered->canReceivePod());
        self::assertTrue(S::Planned->canBeCancelled());
        self::assertFalse(S::PickedUp->canBeCancelled());
    }

    public function testDeliveryOverridesPurchasingCoverageForTheClientOrderStatus(): void
    {
        self::assertSame(ClientOrderStatus::Ordered, ClientOrderStatus::derive(Coverage::FULL, Coverage::NONE));
        self::assertSame(ClientOrderStatus::PartiallyDelivered, ClientOrderStatus::derive(Coverage::FULL, Coverage::PARTIAL));
        self::assertSame(ClientOrderStatus::Delivered, ClientOrderStatus::derive(Coverage::FULL, Coverage::FULL));
        self::assertSame(ClientOrderStatus::Ordering, ClientOrderStatus::derive(Coverage::PARTIAL, Coverage::NONE));
        self::assertTrue(ClientOrderStatus::Delivered->followsCoverage(), 'Une livraison peut encore être complétée');
        self::assertFalse(ClientOrderStatus::Closed->followsCoverage());
    }
}
