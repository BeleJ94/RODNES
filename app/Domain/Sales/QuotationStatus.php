<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Tone;

/**
 *   draft ─► (pending_approval ─► approved) ─► sent ─► accepted
 *     ▲            │                  │           ├─► rejected ─► superseded
 *     └────────────┴──────────────────┘           └─► superseded (nouvelle version)
 * Seul un brouillon se modifie. L'étape d'approbation n'existe que si la validation des marges est activée.
 */
enum QuotationStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Sent],
            self::PendingApproval => [self::Approved, self::Draft],
            self::Approved => [self::Sent, self::Draft],
            self::Sent => [self::Accepted, self::Rejected, self::Superseded],
            self::Rejected => [self::Superseded],
            self::Accepted, self::Superseded => [],
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

    /** Une révision (nouvelle version) peut partir de cette quotation. */
    public function canBeRevised(): bool
    {
        return $this === self::Sent || $this === self::Rejected;
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Draft, self::Superseded, self::Rejected => Tone::Neutral,
            self::PendingApproval => Tone::Warn,
            self::Approved, self::Sent => Tone::Progress,
            self::Accepted => Tone::Ok,
        };
    }

    public function labelKey(): string
    {
        return 'quotation_status.' . $this->value;
    }
}
