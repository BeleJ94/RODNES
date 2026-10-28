<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testValidatedDataOnlyContainsDeclaredFieldsWithTypedValues(): void
    {
        $v = Validator::make(
            ['email' => ' ops@demo.local ', 'qty' => '2,5', 'price' => '1 250,5', 'margin' => '15,5', 'rate' => '18,25', 'days' => '30', 'vat' => '1', 'role' => 'admin'],
            [
                'email' => 'required|email|max:190', 'qty' => 'required|qty', 'price' => 'required|money', 'margin' => 'required|percent',
                'rate' => 'required|rate', 'days' => 'required|int|between:0,365', 'vat' => 'bool', 'active' => 'bool', 'note' => 'nullable|max:255',
            ],
        );
        self::assertTrue($v->passes(), implode(', ', $v->errors()));
        self::assertSame(
            ['email' => 'ops@demo.local', 'qty' => 2500, 'price' => 125050, 'margin' => 1550, 'rate' => 18_250_000, 'days' => 30, 'vat' => true, 'active' => false, 'note' => null],
            $v->validated(),
        );
    }

    public function testErrorsAreReportedPerField(): void
    {
        $v = Validator::make(
            ['email' => 'pas-un-email', 'status' => 'hacked', 'date' => '2026-02-30', 'tags' => ['a'], 'qty' => '0', 'margin' => '100', 'days' => '400', 'currency' => 'usd'],
            [
                'email' => 'required|email', 'status' => 'required|in:planned,sent', 'date' => 'required|date', 'tags' => 'nullable|max:10',
                'name' => 'required', 'qty' => 'required|qty', 'margin' => 'required|percent', 'days' => 'required|int|between:0,365', 'currency' => 'required|currency',
            ],
        );
        self::assertFalse($v->passes());
        self::assertSame(['email', 'status', 'date', 'tags', 'name', 'qty', 'margin', 'days', 'currency'], array_keys($v->errors()));
    }
}
