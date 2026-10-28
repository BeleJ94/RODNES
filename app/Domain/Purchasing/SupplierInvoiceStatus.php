<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Shared\Tone;

/**
 *   on_hold ─► approved ─► partially_paid ─► paid
 *      └──────────┴──► cancelled (rien de payé)
 * Saisie conforme au rapprochement → approved d'office ; écart → on_hold jusqu'à validation motivée.
 */
enum SupplierInvoiceStatus: string
{
    case OnHold = 'on_hold';
    case Approved = 'approved';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /** Statut d'une facture bon à payer selon ce qui a été payé. */
    public static function fromPayments(int $totalMinor, int $paidMinor): self
    {
        return match (true) {
            $paidMinor >= $totalMinor => self::Paid,
            $paidMinor > 0 => self::PartiallyPaid,
            default => self::Approved,
        };
    }

    /** Bon à payer. */
    public function isPayable(): bool
    {
        return $this === self::Approved || $this === self::PartiallyPaid;
    }

    /** Dette fournisseur en cours (en attente comprise : la facture est reçue). */
    public function isOpen(): bool
    {
        return $this === self::OnHold || $this->isPayable();
    }

    public function canCancel(): bool
    {
        return $this === self::OnHold || $this === self::Approved;
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::OnHold => Tone::Warn,
            self::Approved => Tone::Progress,
            self::PartiallyPaid => Tone::Warn,
            self::Paid => Tone::Ok,
            self::Cancelled => Tone::Neutral,
        };
    }

    public function labelKey(): string
    {
        return 'supplier_invoice_status.' . $this->value;
    }
}
