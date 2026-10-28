<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Sales\MarginApproval;
use App\Domain\Sales\Pricing;
use App\Domain\Shared\Fx;
use App\Domain\Shared\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $vectors;

    public static function setUpBeforeClass(): void
    {
        self::$vectors = json_decode((string) file_get_contents(BASE_PATH . '/tests/pricing-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testSharedVectorsLineAmountMarginAndPrice(): void
    {
        foreach (self::$vectors['lineAmount'] as $v) {
            self::assertSame($v['expected'], Money::lineAmount($v['qtyMilli'], $v['unitMinor']));
        }
        foreach (self::$vectors['marginBp'] as $v) {
            self::assertSame($v['expected'], Pricing::marginBp($v['cost'], $v['price']));
        }
        foreach (self::$vectors['priceForMargin'] as $v) {
            $price = Pricing::priceForMargin($v['cost'], $v['marginBp']);
            self::assertSame($v['expected'], $price);
            if ($v['cost'] > 0) {
                self::assertGreaterThanOrEqual($v['marginBp'], Pricing::marginBp($v['cost'], $price), 'Le prix arrondi doit atteindre la marge visée');
            }
        }
    }

    public function testSharedVectorsTotals(): void
    {
        $t = self::$vectors['totals'];
        $lines = array_map(static fn (array $l): array => [
            'quantity_milli' => $l['quantityMilli'], 'unit_cost_minor' => $l['unitCostMinor'], 'unit_price_minor' => $l['unitPriceMinor'],
        ], $t['lines']);
        $with = Pricing::totals($lines, true, $t['vatRateBp']);
        self::assertSame($t['withVat'], ['cost' => $with['cost'], 'subtotal' => $with['subtotal'], 'vat' => $with['vat'], 'total' => $with['total'], 'margin' => $with['margin'], 'marginBp' => $with['margin_bp']]);
        $without = Pricing::totals($lines, false, $t['vatRateBp']);
        self::assertSame($t['withoutVat'], ['vat' => $without['vat'], 'total' => $without['total']]);
    }

    public function testMarginOfOneHundredPercentIsImpossible(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Pricing::priceForMargin(1000, 10000);
    }

    public function testMoneyParsingAndFormatting(): void
    {
        self::assertSame(125050, Money::parse('1 250,50'));
        self::assertSame(125050, Money::parse('1250.5'));
        self::assertSame(1600, Money::parse('16'));
        self::assertSame("1\u{202F}250,50", Money::format(125050));
        self::assertSame('1,250.50', Money::format(125050, '.', ','));
        self::assertSame('-0,05', Money::format(-5));
        foreach (['', '-1', '1,234', 'abc', '1e3'] as $invalid) {
            try {
                Money::parse($invalid);
                self::fail("« {$invalid} » aurait dû être refusé");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCurrencyConversionToUsd(): void
    {
        $zar = Fx::parseRate('18,25');
        self::assertSame(18_250_000, $zar);
        self::assertSame(54795, Fx::toUsd(1_000_000, $zar), '10 000,00 ZAR ≈ 547,95 USD');
        self::assertSame(12345, Fx::toUsd(12345, Fx::ONE), 'USD → USD inchangé');
        self::assertSame('18,25', Fx::formatRate($zar));
        self::assertSame('2850', Fx::formatRate(Fx::parseRate('2850')));
        $this->expectException(InvalidArgumentException::class);
        Fx::parseRate('0');
    }

    public function testMarginApprovalIsOffByDefaultAndUsesTheThreshold(): void
    {
        self::assertFalse(MarginApproval::required(false, 1500, 500));
        self::assertTrue(MarginApproval::required(true, 1500, 1499));
        self::assertFalse(MarginApproval::required(true, 1500, 1500));
        self::assertTrue(MarginApproval::required(true, 1500, null), 'Marge non calculable (prix nul) : approbation exigée');
    }
}
