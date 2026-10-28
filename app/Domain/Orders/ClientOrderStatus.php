<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Shared\Tone;

/**
 *   received ─► ordering ─► ordered ─► partially_delivered ─► delivered ─► closed
 *        └──────────┴──► cancelled (aucun PO fournisseur actif)
 * Tant que le PO n'est ni clos ni annulé, son statut se calcule : quantités reçues (POD), sinon couverture d'achat.
 */
enum ClientOrderStatus: string
{
    case Received = 'received';
    case Ordering = 'ordering';
    case Ordered = 'ordered';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public static function fromCoverage(string $coverage): self
    {
        return match ($coverage) {
            Coverage::FULL => self::Ordered,
            Coverage::PARTIAL => self::Ordering,
            default => self::Received,
        };
    }

    /**
     * Statut calculé : la livraison (quantités reçues sur POD) prime sur la couverture d'achat.
     *
     * @param string $coverage Coverage::NONE|PARTIAL|FULL (commandé aux fournisseurs)
     * @param string $delivery Coverage::NONE|PARTIAL|FULL (reçu par le client)
     */
    public static function derive(string $coverage, string $delivery): self
    {
        return match ($delivery) {
            Coverage::FULL => self::Delivered,
            Coverage::PARTIAL => self::PartiallyDelivered,
            default => self::fromCoverage($coverage),
        };
    }

    /** Statut piloté par les quantités (pas encore clos ni annulé). */
    public function followsCoverage(): bool
    {
        return !$this->isFinal();
    }

    public function isFinal(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Received => Tone::Warn,
            self::Ordering => Tone::Progress,
            self::Ordered, self::PartiallyDelivered => Tone::Progress,
            self::Delivered => Tone::Ok,
            self::Closed, self::Cancelled => Tone::Neutral,
        };
    }

    public function labelKey(): string
    {
        return 'client_order_status.' . $this->value;
    }
}
