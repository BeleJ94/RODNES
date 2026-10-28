<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Tone;

/**
 *   received → sourcing → quoted → won
 *        ↘         ↘         ↘→ lost / cancelled
 * « sourcing », « quoted » et « won » sont posés automatiquement (demande de prix, envoi, acceptation).
 */
enum RfqStatus: string
{
    case Received = 'received';
    case Sourcing = 'sourcing';
    case Quoted = 'quoted';
    case Won = 'won';
    case Lost = 'lost';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Received => [self::Sourcing, self::Quoted, self::Lost, self::Cancelled],
            self::Sourcing => [self::Quoted, self::Lost, self::Cancelled],
            self::Quoted => [self::Sourcing, self::Won, self::Lost, self::Cancelled],
            self::Won, self::Lost, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    /** Lignes et en-tête encore modifiables, réponse attendue de notre part. */
    public function isOpen(): bool
    {
        return $this === self::Received || $this === self::Sourcing;
    }

    public function isClosed(): bool
    {
        return $this->transitions() === [];
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Received => Tone::Warn,
            self::Sourcing, self::Quoted => Tone::Progress,
            self::Won => Tone::Ok,
            self::Lost, self::Cancelled => Tone::Neutral,
        };
    }

    public function labelKey(): string
    {
        return 'rfq_status.' . $this->value;
    }
}
