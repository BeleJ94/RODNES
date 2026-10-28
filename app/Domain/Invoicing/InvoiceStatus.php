<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Shared\Tone;

/**
 *   draft ─► issued ─► partially_paid ─► paid
 *     │        └──► cancelled (aucun encaissement affecté)
 *     └──► (supprimé : un brouillon n'a pas de numéro)
 * Après émission, le statut suit les encaissements affectés.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /** Statut d'une facture émise selon ce qui a été encaissé. */
    public static function fromPayments(int $totalMinor, int $paidMinor): self
    {
        return match (true) {
            $paidMinor >= $totalMinor => self::Paid,
            $paidMinor > 0 => self::PartiallyPaid,
            default => self::Issued,
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Émise et pas entièrement réglée : compte dans les créances. */
    public function isOpen(): bool
    {
        return $this === self::Issued || $this === self::PartiallyPaid;
    }

    /** Ses quantités comptent comme facturées (brouillons compris, pour éviter une double facturation). */
    public function holdsQuantities(): bool
    {
        return $this !== self::Cancelled;
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Draft, self::Cancelled => Tone::Neutral,
            self::Issued => Tone::Progress,
            self::PartiallyPaid => Tone::Warn,
            self::Paid => Tone::Ok,
        };
    }

    public function labelKey(): string
    {
        return 'invoice_status.' . $this->value;
    }
}
