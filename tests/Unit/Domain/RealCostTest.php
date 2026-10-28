<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Finance\RealCost;
use PHPUnit\Framework\TestCase;

final class RealCostTest extends TestCase
{
    // Facture ZAR : HT 1 000,00, TVA 160,00, TTC 1 160,00, taux facture 18 ZAR/USD.
    private const SUB = 100_000;
    private const TOTAL = 116_000;
    private const FX_INVOICE = 18_000_000;

    public function testUnpaidInvoiceUsesInvoiceRateExcludingVat(): void
    {
        self::assertSame(5_556, RealCost::invoiceUsd(self::SUB, self::TOTAL, self::FX_INVOICE, []));
    }

    public function testPaidPartUsesPaymentRate(): void
    {
        $half = RealCost::invoiceUsd(self::SUB, self::TOTAL, self::FX_INVOICE, [['amount' => 58_000, 'fx' => 20_000_000]]);
        self::assertSame(2_500 + 2_778, $half, 'moitié payée à 20 (25,00 USD), reste à 18 (27,78 USD)');
        $full = RealCost::invoiceUsd(self::SUB, self::TOTAL, self::FX_INVOICE, [['amount' => 58_000, 'fx' => 20_000_000], ['amount' => 58_000, 'fx' => 20_000_000]]);
        self::assertSame(5_000, $full, 'rand plus faible au paiement : coût USD en baisse (gain de change)');
    }

    public function testOverpaymentIsCappedAndShareIsProrated(): void
    {
        self::assertSame(5_000, RealCost::invoiceUsd(self::SUB, self::TOTAL, self::FX_INVOICE, [['amount' => 200_000, 'fx' => 20_000_000]]));
        self::assertSame(1_250, RealCost::shareUsd(25_000, self::SUB, 5_000));
        self::assertSame(0, RealCost::shareUsd(25_000, 0, 5_000));
    }
}
