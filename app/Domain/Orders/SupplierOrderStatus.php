<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Shared\Tone;

/**
 *   draft ─► sent ─► confirmed ─► (phase 3 : expédié, reçu)
 *     └───────┴──────────┴──► cancelled
 * Seul un brouillon se modifie ; le taux de change est figé à l'envoi.
 */
enum SupplierOrderStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::Sent, self::Cancelled],
            self::Sent => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Compte dans la couverture du PO client. */
    public function counts(): bool
    {
        return $this !== self::Cancelled;
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Draft => Tone::Neutral,
            self::Sent => Tone::Warn,
            self::Confirmed => Tone::Ok,
            self::Cancelled => Tone::Neutral,
        };
    }

    public function labelKey(): string
    {
        return 'supplier_order_status.' . $this->value;
    }
}
