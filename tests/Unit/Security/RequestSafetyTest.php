<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Core\Csrf;
use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestSafetyTest extends TestCase
{
    public function testOriginCheck(): void
    {
        self::assertTrue(Csrf::sameOrigin(null, 'ethan.example'));
        self::assertTrue(Csrf::sameOrigin('https://ethan.example', 'ethan.example'));
        self::assertTrue(Csrf::sameOrigin('http://127.0.0.1:8080', '127.0.0.1:8080'));
        self::assertFalse(Csrf::sameOrigin('https://evil.example', 'ethan.example'));
        self::assertFalse(Csrf::sameOrigin('https://ethan.example.evil.example', 'ethan.example'));
        self::assertFalse(Csrf::sameOrigin('null', 'ethan.example'));
    }

    public function testSafeRedirectPathBlocksOpenRedirects(): void
    {
        self::assertSame('/deliveries?status=late', safe_path('/deliveries?status=late'));
        self::assertSame('/', safe_path('//evil.example'));
        self::assertSame('/', safe_path('/\\evil.example'));
        self::assertSame('/', safe_path('https://evil.example'));
        self::assertSame('/', safe_path(['/array']));
        self::assertSame('/field', safe_path(null, '/field'));
    }

    public function testInjectedArraysNeverBecomeStringsOrInts(): void
    {
        $request = new Request('GET', '/', ['status' => ['x'], 'id' => ['1'], 'n' => '12', 'bad' => '12abc']);
        self::assertSame('', $request->str('status'));
        self::assertNull($request->int('id'));
        self::assertSame(12, $request->int('n'));
        self::assertNull($request->int('bad'));
    }

    public function testSecretOnlyReadsTheBodyAndIsNotTrimmed(): void
    {
        $request = new Request('POST', '/login', ['password' => 'from-query'], ['password' => ' p@ss ']);
        self::assertSame(' p@ss ', $request->secret('password'));
        self::assertSame('', (new Request('POST', '/login', ['password' => 'x']))->secret('password'));
    }
}
