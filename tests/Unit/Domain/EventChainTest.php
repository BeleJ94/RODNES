<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Delivery\EventChain;
use PHPUnit\Framework\TestCase;

final class EventChainTest extends TestCase
{
    private const KEY = 'clé-de-test-de-32-octets-minimum!!';

    /** @return list<array{prev_hash: string, hash: string, data: array<string, mixed>}> */
    private static function chain(array ...$events): array
    {
        $out = [];
        $previous = EventChain::genesis();
        foreach ($events as $data) {
            $hash = EventChain::hash($previous, $data, self::KEY);
            $out[] = ['prev_hash' => $previous, 'hash' => $hash, 'data' => $data];
            $previous = $hash;
        }
        return $out;
    }

    public function testIntactChainIsValid(): void
    {
        $chain = self::chain(['type' => 'created'], ['type' => 'weighing', 'payload' => ['kg' => 32140]], ['type' => 'pod']);
        self::assertNull(EventChain::firstBrokenLink($chain, self::KEY));
    }

    public function testEditingAPastEventIsDetected(): void
    {
        $chain = self::chain(['type' => 'created'], ['type' => 'weighing', 'payload' => ['kg' => 32140]], ['type' => 'pod']);
        $chain[1]['data']['payload']['kg'] = 32000;
        self::assertSame(1, EventChain::firstBrokenLink($chain, self::KEY));
    }

    public function testDeletingOrReorderingEventsIsDetected(): void
    {
        $chain = self::chain(['type' => 'a'], ['type' => 'b'], ['type' => 'c']);
        self::assertSame(1, EventChain::firstBrokenLink([$chain[0], $chain[2]], self::KEY));
        self::assertSame(0, EventChain::firstBrokenLink([$chain[1], $chain[0], $chain[2]], self::KEY));
    }

    public function testRecomputingWithoutTheKeyFails(): void
    {
        $chain = self::chain(['type' => 'created']);
        self::assertSame(0, EventChain::firstBrokenLink($chain, 'une-autre-clé-de-32-octets-minimum'));
    }

    public function testCanonicalFormIgnoresKeyOrderButKeepsListOrder(): void
    {
        self::assertSame(EventChain::canonical(['b' => 1, 'a' => ['y' => 2, 'x' => 3]]), EventChain::canonical(['a' => ['x' => 3, 'y' => 2], 'b' => 1]));
        self::assertNotSame(EventChain::canonical(['seals' => ['S1', 'S2']]), EventChain::canonical(['seals' => ['S2', 'S1']]));
    }
}
