<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Sales\QuotationStatus as Q;
use App\Domain\Sales\RfqStatus as R;
use App\Domain\Shared\Tone;
use App\Domain\Sourcing\OfferComparison;
use PHPUnit\Framework\TestCase;

final class StatusesTest extends TestCase
{
    /** @param list<\BackedEnum> $cases */
    private static function reachable(\BackedEnum $start): array
    {
        $seen = [$start->value => true];
        $queue = [$start];
        while ($queue !== []) {
            foreach (array_shift($queue)->transitions() as $next) {
                if (!isset($seen[$next->value])) {
                    $seen[$next->value] = true;
                    $queue[] = $next;
                }
            }
        }
        return array_keys($seen);
    }

    public function testEveryRfqAndQuotationStatusIsReachable(): void
    {
        self::assertCount(count(R::cases()), self::reachable(R::Received));
        self::assertCount(count(Q::cases()), self::reachable(Q::Draft));
    }

    public function testRfqOpenAndClosedStates(): void
    {
        self::assertTrue(R::Received->isOpen());
        self::assertTrue(R::Sourcing->isOpen());
        self::assertFalse(R::Quoted->isOpen());
        foreach ([R::Won, R::Lost, R::Cancelled] as $closed) {
            self::assertTrue($closed->isClosed());
        }
        self::assertFalse(R::Won->canTransitionTo(R::Lost));
    }

    public function testOnlyDraftsAreEditableAndOnlySentOrRejectedCanBeRevised(): void
    {
        foreach (Q::cases() as $status) {
            self::assertSame($status === Q::Draft, $status->isEditable(), $status->value);
            self::assertSame(in_array($status, [Q::Sent, Q::Rejected], true), $status->canBeRevised(), $status->value);
        }
    }

    public function testAQuotationCannotBeAcceptedBeforeBeingSent(): void
    {
        foreach (Q::cases() as $status) {
            if ($status->canTransitionTo(Q::Accepted)) {
                self::assertSame(Q::Sent, $status);
            }
        }
        self::assertFalse(Q::PendingApproval->canTransitionTo(Q::Sent), 'Une quotation à approuver ne part pas sans approbation');
    }

    public function testStatusesNeedingActionAreNotShownAsOk(): void
    {
        self::assertSame(Tone::Warn, R::Received->tone());
        self::assertSame(Tone::Warn, Q::PendingApproval->tone());
        self::assertSame(Tone::Ok, Q::Accepted->tone());
    }

    public function testBestOfferIsCheapestInUsdThenFastest(): void
    {
        $best = OfferComparison::best([
            ['offer_id' => 1, 'rfq_line_id' => 10, 'unit_usd_minor' => 5000, 'lead_time_days' => 30],
            ['offer_id' => 2, 'rfq_line_id' => 10, 'unit_usd_minor' => 4800, 'lead_time_days' => 45],
            ['offer_id' => 3, 'rfq_line_id' => 11, 'unit_usd_minor' => 900, 'lead_time_days' => 20],
            ['offer_id' => 4, 'rfq_line_id' => 11, 'unit_usd_minor' => 900, 'lead_time_days' => 10],
            ['offer_id' => 5, 'rfq_line_id' => 12, 'unit_usd_minor' => 100, 'lead_time_days' => null],
        ]);
        self::assertSame([10 => 2, 11 => 4, 12 => 5], $best);
    }
}
