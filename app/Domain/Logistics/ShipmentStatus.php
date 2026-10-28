<?php

declare(strict_types=1);

namespace App\Domain\Logistics;

use App\Domain\Shared\Tone;

/**
 *   planned ─► picked_up ─► in_transit ─► at_border ─► cleared ─► delivered (POD obligatoire)
 *      └──► cancelled
 * Les jalons avancent toujours (on peut en sauter : livraison locale sans frontière), jamais en arrière.
 */
enum ShipmentStatus: string
{
    case Planned = 'planned';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case AtBorder = 'at_border';
    case Cleared = 'cleared';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    private function rank(): int
    {
        return match ($this) {
            self::Planned => 0,
            self::PickedUp => 1,
            self::InTransit => 2,
            self::AtBorder => 3,
            self::Cleared => 4,
            self::Delivered => 5,
            self::Cancelled => -1,
        };
    }

    /**
     * Jalons saisissables à la main depuis ce statut (la livraison passe par la POD).
     *
     * @return list<self>
     */
    public function nextMilestones(): array
    {
        if ($this->isFinal()) {
            return [];
        }
        return array_values(array_filter(
            [self::PickedUp, self::InTransit, self::AtBorder, self::Cleared],
            fn (self $s): bool => $s->rank() > $this->rank(),
        ));
    }

    public function canAdvanceTo(self $to): bool
    {
        return in_array($to, $this->nextMilestones(), true);
    }

    /** La POD n'est possible qu'une fois la marchandise enlevée. */
    public function canReceivePod(): bool
    {
        return !$this->isFinal() && $this->rank() >= self::PickedUp->rank();
    }

    public function canBeCancelled(): bool
    {
        return $this === self::Planned;
    }

    public function isFinal(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled;
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Planned => Tone::Neutral,
            self::PickedUp, self::InTransit, self::Cleared => Tone::Progress,
            self::AtBorder => Tone::Warn,
            self::Delivered => Tone::Ok,
            self::Cancelled => Tone::Neutral,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Planned => 'calendar',
            self::PickedUp => 'package-check',
            self::InTransit => 'truck',
            self::AtBorder => 'flag',
            self::Cleared => 'stamp',
            self::Delivered => 'circle-check-big',
            self::Cancelled => 'ban',
        };
    }

    public function labelKey(): string
    {
        return 'shipment_status.' . $this->value;
    }
}
