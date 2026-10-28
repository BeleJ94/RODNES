<?php

declare(strict_types=1);

namespace App\Security;

/** Matrice rôle → permissions (config/permissions.php). */
final class Permissions
{
    /** @var array<string, list<Role>> */
    private array $map;

    public function __construct()
    {
        $this->map = require BASE_PATH . '/config/permissions.php';
    }

    /** @return list<string> */
    public function forRole(Role $role): array
    {
        return array_keys(array_filter($this->map, static fn (array $roles): bool => in_array($role, $roles, true)));
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->map);
    }
}
