<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use RuntimeException;

/** Règle métier non respectée : le message est une clé de traduction, affichée telle quelle à l'utilisateur. */
final class RuleViolation extends RuntimeException
{
    /** @param array<string, scalar> $params */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}
