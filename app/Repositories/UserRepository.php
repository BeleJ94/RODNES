<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends Repository
{
    /** Colonnes exposées hors du module de sécurité (jamais l'empreinte du mot de passe ni le secret TOTP). */
    private const PUBLIC_COLUMNS = 'u.id, u.email, u.name, u.phone, u.role, u.locale, u.is_active, u.last_login_at, u.password_changed_at,
        u.must_change_password, u.created_by, u.created_at, u.updated_at';

    /** @return list<array{id: int, name: string}> */
    public function options(): array
    {
        return $this->db->fetchAll('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');
    }

    /** @return list<array{id: int, name: string}> tous les comptes, désactivés compris (filtres du journal) */
    public function allOptions(): array
    {
        return $this->db->fetchAll('SELECT id, name, is_active FROM users ORDER BY is_active DESC, name');
    }

    /** @return list<array<string, mixed>> comptes avec le nombre d'actions des 30 derniers jours */
    public function list(): array
    {
        return $this->db->fetchAll(
            'SELECT ' . self::PUBLIC_COLUMNS . ',
                    (SELECT COUNT(*) FROM activity_log a WHERE a.user_id = u.id AND a.created_at >= NOW() - INTERVAL 30 DAY) AS actions_30d
               FROM users u ORDER BY u.is_active DESC, u.name'
        );
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return self::orFail($this->db->fetchOne(
            'SELECT ' . self::PUBLIC_COLUMNS . ', c.name AS created_by_name FROM users u LEFT JOIN users c ON c.id = u.created_by WHERE u.id = :id',
            ['id' => $id],
        ));
    }

    public function emailTaken(string $email, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM users WHERE email = :e AND id <> :id',
            ['e' => $email, 'id' => $exceptId ?? 0],
        );
    }

    /** Administrateurs actifs, hors un compte donné (pour ne jamais retirer le dernier). */
    public function activeAdmins(?int $exceptId = null): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> :id",
            ['id' => $exceptId ?? 0],
        );
    }

    /** @return array{total: int, active: int, admins: int, never: int, pending: int} */
    public function kpis(): array
    {
        return $this->db->fetchOne(
            "SELECT COUNT(*) AS total, COALESCE(SUM(is_active), 0) AS active,
                    COALESCE(SUM(role = 'admin' AND is_active = 1), 0) AS admins,
                    COALESCE(SUM(last_login_at IS NULL AND is_active = 1), 0) AS never,
                    COALESCE(SUM(must_change_password = 1 AND is_active = 1), 0) AS pending
               FROM users"
        ) ?? ['total' => 0, 'active' => 0, 'admins' => 0, 'never' => 0, 'pending' => 0];
    }

    /** @return list<array<string, mixed>> dernières connexions et tentatives d'un compte */
    public function logins(string $email, int $limit = 15): array
    {
        return $this->db->fetchAll(
            'SELECT success, ip, created_at FROM login_attempts WHERE email = :e ORDER BY id DESC LIMIT ' . max(1, $limit),
            ['e' => $email],
        );
    }
}
