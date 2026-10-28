<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Invoicing\InvoiceStatus as S;
use App\Domain\Invoicing\Receivables;
use PHPUnit\Framework\TestCase;

final class InvoicingTest extends TestCase
{
    public function testStatusFollowsPayments(): void
    {
        self::assertSame(S::Issued, S::fromPayments(10000, 0));
        self::assertSame(S::PartiallyPaid, S::fromPayments(10000, 1));
        self::assertSame(S::Paid, S::fromPayments(10000, 10000));
        self::assertTrue(S::Draft->isEditable());
        self::assertFalse(S::Issued->isEditable(), 'Une facture émise ne se modifie plus');
        self::assertTrue(S::Draft->holdsQuantities(), 'Un brouillon réserve ses quantités (pas de double facturation)');
        self::assertFalse(S::Cancelled->holdsQuantities());
        self::assertFalse(S::Paid->isOpen());
    }

    public function testAgingBuckets(): void
    {
        self::assertSame('not_due', Receivables::bucket('2026-10-26', '2026-10-26'));
        self::assertSame('d1_30', Receivables::bucket('2026-10-25', '2026-10-26'));
        self::assertSame('d31_60', Receivables::bucket('2026-09-25', '2026-10-26'));
        self::assertSame('d90_plus', Receivables::bucket('2026-06-01', '2026-10-26'));
        self::assertSame(
            ['not_due' => 500, 'd1_30' => 0, 'd31_60' => 300, 'd61_90' => 0, 'd90_plus' => 200],
            Receivables::aging([
                ['due_date' => '2026-11-30', 'balance' => 500],
                ['due_date' => '2026-09-10', 'balance' => 300],
                ['due_date' => '2026-01-10', 'balance' => 200],
            ], '2026-10-26'),
        );
    }

    public function testPaymentIsAllocatedToOldestDueInvoicesFirst(): void
    {
        $invoices = [
            ['id' => 3, 'due_date' => '2026-11-30', 'balance' => 5000],
            ['id' => 1, 'due_date' => '2026-09-30', 'balance' => 3000],
            ['id' => 2, 'due_date' => '2026-10-15', 'balance' => 4000],
        ];
        self::assertSame([1 => 3000, 2 => 2500], Receivables::allocateOldestFirst(5500, $invoices));
        self::assertSame([1 => 3000, 2 => 4000, 3 => 5000], Receivables::allocateOldestFirst(20000, $invoices), 'Le surplus reste non affecté');
    }

    public function testManualAllocationIsChecked(): void
    {
        $balances = [1 => 3000, 2 => 4000];
        self::assertNull(Receivables::checkAllocation(5000, [1 => 3000, 2 => 2000], $balances));
        self::assertSame('payments.error_over_balance', Receivables::checkAllocation(5000, [1 => 3500], $balances));
        self::assertSame('payments.error_over_amount', Receivables::checkAllocation(5000, [1 => 3000, 2 => 2500], $balances));
        self::assertSame('payments.error_invoice', Receivables::checkAllocation(5000, [9 => 100], $balances));
    }
}
