<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\HttpException;

/** Base des repositories : SQL uniquement, requêtes préparées, aucune traduction ni règle métier. */
abstract class Repository
{
    public function __construct(protected Database $db)
    {
    }

    protected static function likePattern(string $search): string
    {
        return '%' . addcslashes($search, '%_\\') . '%';
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    protected static function orFail(?array $row): array
    {
        return $row ?? throw new HttpException(404);
    }
}
