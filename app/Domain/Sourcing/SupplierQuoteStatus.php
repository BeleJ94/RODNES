<?php

declare(strict_types=1);

namespace App\Domain\Sourcing;

use App\Domain\Shared\Tone;

enum SupplierQuoteStatus: string
{
    case Requested = 'requested';
    case Received = 'received';
    case Declined = 'declined';

    public function tone(): Tone
    {
        return match ($this) {
            self::Requested => Tone::Warn,
            self::Received => Tone::Ok,
            self::Declined => Tone::Neutral,
        };
    }

    public function labelKey(): string
    {
        return 'supplier_quote_status.' . $this->value;
    }
}
