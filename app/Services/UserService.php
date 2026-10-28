<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Shared\RuleViolation;
use App\Repositories\UserRepository;
use App\Security\Auth;
use App\Security\Role;
use SensitiveParameter;

/**
 * Comptes utilisateurs : création avec mot de passe provisoire, modification, désactivation, réinitialisation.
 * Garde-fous : jamais sans administrateur actif, pas d'auto-désactivation ni d'auto-rétrogradation.
 * Chaque opération est journalisée dans la même transaction (sans jamais écrire de mot de passe au journal).
 */
final class UserService
{
    public const MIN_PASSWORD = 12;

    public function __construct(
        private Database $db,
        private UserRepository $users,
        private ActivityLogger $logger,
        private Auth $auth,
    ) {
    }

    /**
     * @param array{email: string, name: string, phone: ?string, role: string, locale: string} $data
     * @return array{0: int, 1: string} identifiant et mot de passe provisoire (affiché une seule fois)
     */
    public function create(array $data): array
    {
        $data = self::normalize($data);
        if ($this->users->emailTaken($data['email'])) {
            throw new RuleViolation('users.error_email_taken');
        }
        $password = self::temporaryPassword();
        $id = $this->db->transaction(function () use ($data, $password): int {
            $id = $this->db->insert(
                'INSERT INTO users (email, name, phone, role, locale, password_hash, password_changed_at, must_change_password, created_by)
                 VALUES (:email, :name, :phone, :role, :locale, :hash, NOW(), 1, :by)',
                $data + ['hash' => password_hash($password, PASSWORD_ARGON2ID), 'by' => $this->auth->user()?->id],
            );
            $this->logger->log('user', $id, 'created', [], $data);
            return $id;
        });
        return [$id, $password];
    }

    /** @param array{email: string, name: string, phone: ?string, role: string, locale: string} $data */
    public function update(int $id, array $data): void
    {
        $data = self::normalize($data);
        $before = $this->users->get($id);
        if ($this->users->emailTaken($data['email'], $id)) {
            throw new RuleViolation('users.error_email_taken');
        }
        if ($before['role'] === Role::Admin->value && $data['role'] !== Role::Admin->value) {
            if ($id === $this->auth->user()?->id) {
                throw new RuleViolation('users.error_self_demote');
            }
            if ($before['is_active'] && $this->users->activeAdmins($id) === 0) {
                throw new RuleViolation('users.error_last_admin');
            }
        }
        $this->db->transaction(function () use ($id, $data, $before): void {
            $this->db->run(
                'UPDATE users SET email = :email, name = :name, phone = :phone, role = :role, locale = :locale WHERE id = :id',
                $data + ['id' => $id],
            );
            // Changement de rôle ou d'e-mail : les sessions ouvertes repartent de zéro avec les nouveaux droits.
            if ($before['role'] !== $data['role'] || $before['email'] !== $data['email']) {
                $this->auth->revokeAllSessions($id);
            }
            $this->logger->log('user', $id, 'updated', array_intersect_key($before, $data), $data);
        });
    }

    public function setActive(int $id, bool $active): void
    {
        $user = $this->users->get($id);
        if (!$active) {
            if ($id === $this->auth->user()?->id) {
                throw new RuleViolation('users.error_self_deactivate');
            }
            if ($user['role'] === Role::Admin->value && $this->users->activeAdmins($id) === 0) {
                throw new RuleViolation('users.error_last_admin');
            }
        }
        $this->db->transaction(function () use ($id, $active, $user): void {
            $this->db->run('UPDATE users SET is_active = :a WHERE id = :id', ['a' => (int) $active, 'id' => $id]);
            if (!$active) {
                $this->auth->revokeAllSessions($id);
            }
            $this->logger->log('user', $id, $active ? 'activated' : 'deactivated', ['is_active' => (int) $user['is_active']], ['is_active' => (int) $active]);
        });
    }

    /** Nouveau mot de passe provisoire, sessions fermées ; renvoie le mot de passe (affiché une seule fois). */
    public function resetPassword(int $id): string
    {
        $this->users->get($id);
        $password = self::temporaryPassword();
        $this->db->transaction(function () use ($id, $password): void {
            $this->db->run(
                'UPDATE users SET password_hash = :h, password_changed_at = NOW(), must_change_password = 1, session_version = session_version + 1 WHERE id = :id',
                ['h' => password_hash($password, PASSWORD_ARGON2ID), 'id' => $id],
            );
            $this->logger->log('user', $id, 'password_reset');
        });
        return $password;
    }

    public function revokeSessions(int $id): void
    {
        $this->users->get($id);
        $this->db->transaction(function () use ($id): void {
            $this->auth->revokeAllSessions($id);
            $this->logger->log('user', $id, 'sessions_revoked');
        });
    }

    /**
     * Changement de son propre mot de passe : vérifie l'actuel, impose la longueur et un mot de passe différent.
     * Les autres sessions sont fermées ; la session courante est conservée (version resynchronisée par l'appelant).
     */
    public function changeOwnPassword(int $id, #[SensitiveParameter] string $current, #[SensitiveParameter] string $new): int
    {
        $hash = (string) $this->db->fetchValue('SELECT password_hash FROM users WHERE id = :id', ['id' => $id]);
        if (!password_verify($current, $hash)) {
            throw new RuleViolation('account.error_current');
        }
        if (mb_strlen($new) < self::MIN_PASSWORD) {
            throw new RuleViolation('account.error_short', ['n' => self::MIN_PASSWORD]);
        }
        if (password_verify($new, $hash)) {
            throw new RuleViolation('account.error_same');
        }
        return $this->db->transaction(function () use ($id, $new): int {
            $this->db->run(
                'UPDATE users SET password_hash = :h, password_changed_at = NOW(), must_change_password = 0, session_version = session_version + 1 WHERE id = :id',
                ['h' => password_hash($new, PASSWORD_ARGON2ID), 'id' => $id],
            );
            $this->logger->log('user', $id, 'password_changed');
            return (int) $this->db->fetchValue('SELECT session_version FROM users WHERE id = :id', ['id' => $id]);
        });
    }

    /** @param array{name: string, phone: ?string, locale: string} $data */
    public function updateProfile(int $id, array $data): void
    {
        $before = $this->users->get($id);
        $data = ['name' => trim($data['name']), 'phone' => trim((string) $data['phone']) ?: null, 'locale' => $data['locale']];
        $this->db->transaction(function () use ($id, $data, $before): void {
            $this->db->run('UPDATE users SET name = :name, phone = :phone, locale = :locale WHERE id = :id', $data + ['id' => $id]);
            $this->logger->log('user', $id, 'profile', array_intersect_key($before, $data), $data);
        });
    }

    /** 16 caractères lisibles (sans 0/O, 1/l/I), groupés par 4 pour la dictée : « Kp7d-xQ2m-… ». */
    public static function temporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= ($i > 0 && $i % 4 === 0 ? '-' : '') . $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{email: string, name: string, phone: ?string, role: string, locale: string}
     */
    private static function normalize(array $data): array
    {
        return [
            'email' => mb_strtolower(trim((string) $data['email'])),
            'name' => trim((string) $data['name']),
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'role' => Role::from((string) $data['role'])->value,
            'locale' => in_array($data['locale'] ?? 'fr', ['fr', 'en'], true) ? (string) $data['locale'] : 'fr',
        ];
    }
}
