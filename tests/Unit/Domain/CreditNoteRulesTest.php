<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Invoicing\CreditNoteRules as R;
use PHPUnit\Framework\TestCase;

final class CreditNoteRulesTest extends TestCase
{
    // Ligne facturée : 10 u. à 25,00 = 250,00.
    public function testReturnAndDiscountWithinLimits(): void
    {
        self::assertNull(R::lineError(10_000, 2_500, 0, 2_000, 2_500), 'retour de 2 u.');
        self::assertNull(R::lineError(10_000, 2_500, 0, 10_000, 250), 'remise de 2,50 sur toute la quantité');
        self::assertNull(R::lineError(10_000, 2_500, 20_000, 2_000, 2_500), 'cumul pile au montant de la ligne');
    }

    public function testRefusals(): void
    {
        self::assertSame('credit_notes.error_qty', R::lineError(10_000, 2_500, 0, 11_000, 2_500));
        self::assertSame('credit_notes.error_price', R::lineError(10_000, 2_500, 0, 1_000, 2_600));
        self::assertSame('credit_notes.error_over_credited', R::lineError(10_000, 2_500, 20_001, 2_000, 2_500));
        self::assertSame('credit_notes.error_line', R::lineError(10_000, 2_500, 0, 0, 2_500));
    }

    public function testCreditable(): void
    {
        self::assertSame(5_000, R::creditable(10_000, 2_500, 20_000));
        self::assertSame(0, R::creditable(10_000, 2_500, 30_000));
    }
}
