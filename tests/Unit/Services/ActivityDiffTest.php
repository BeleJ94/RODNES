<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ActivityLogger;
use PHPUnit\Framework\TestCase;

final class ActivityDiffTest extends TestCase
{
    public function testOnlyChangedFieldsAreKept(): void
    {
        $diff = ActivityLogger::diff(
            ['status' => 'planned', 'driver_id' => 4, 'qty' => '30000', 'updated_at' => '2026-10-25 10:00:00'],
            ['status' => 'loading', 'driver_id' => 4, 'qty' => 30000, 'updated_at' => '2026-10-26 08:00:00'],
        );
        self::assertSame(['status' => ['from' => 'planned', 'to' => 'loading']], $diff);
    }

    public function testSecretsAreNeverLogged(): void
    {
        self::assertSame([], ActivityLogger::diff(['password_hash' => 'a', 'totp_secret' => 'x'], ['password_hash' => 'b', 'totp_secret' => 'y']));
    }

    public function testNullAndEmptyStringAreDifferent(): void
    {
        self::assertSame(['note' => ['from' => null, 'to' => '']], ActivityLogger::diff(['note' => null], ['note' => '']));
        self::assertSame(['vehicle_id' => ['from' => null, 'to' => 3]], ActivityLogger::diff([], ['vehicle_id' => 3]));
    }
}
