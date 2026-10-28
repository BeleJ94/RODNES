<?php

declare(strict_types=1);

namespace App\Security;

/** Utilisateur authentifié, rechargé depuis la base à chaque requête (désactivation et changement de rôle immédiats). */
final class CurrentUser
{
    /** @param list<string> $permissions */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly Role $role,
        public readonly string $locale,
        private readonly array $permissions,
        /** Mot de passe provisoire (création, réinitialisation) : à changer avant tout autre écran. */
        public readonly bool $mustChangePassword = false,
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name)) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        return $initials !== '' ? $initials : '?';
    }
}
