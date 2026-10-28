<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends Repository
{
    /** @return list<array{id: int, name: string}> */
    public function options(): array
    {
        return $this->db->fetchAll('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');
    }
}
