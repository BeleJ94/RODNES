<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config;
use App\Core\Database;
use App\Core\Session;
use SensitiveParameter;

/**
 * Authentification par session.
 * - Mots de passe Argon2id, rehash transparent.
 * - Temps de réponse identique que le compte existe ou non (pas d'énumération).
 * - Expiration sur inactivité et durée absolue ; invalidation globale via users.session_version.
 */
final class Auth
{
    /** Empreinte factice vérifiée quand l'e-mail est inconnu, pour un temps de réponse constant. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$MXJKNGtzRlVGbWJpTGFWYw$LJrcnqVnM6WLF4XLq61CNnusZ3FhXDsdEKmZnBIh/Js';

    private ?CurrentUser $user = null;

    private bool $resolved = false;

    public function __construct(
        private Database $db,
        private Session $session,
        private Config $config,
        private LoginThrottle $throttle,
        private Permissions $permissions,
    ) {
    }

    public function user(): ?CurrentUser
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;
        $id = $this->session->get('auth.user_id');
        if (!is_int($id)) {
            return null;
        }
        $now = time();
        $idle = (int) $this->config->get('session.idle_minutes', 30) * 60;
        $absolute = (int) $this->config->get('session.absolute_hours', 12) * 3600;
        if ($now - (int) $this->session->get('auth.last_seen', 0) > $idle
            || $now - (int) $this->session->get('auth.login_at', 0) > $absolute) {
            $this->clearAuth();
            $this->session->flash('info', 'auth.session_expired');
            return null;
        }
        $row = $this->db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if ($row === null || !$row['is_active']
            || (int) $row['session_version'] !== (int) $this->session->get('auth.session_version')) {
            $this->clearAuth();
            return null;
        }
        $this->session->set('auth.last_seen', $now);
        return $this->user = $this->hydrate($row);
    }

    public function attempt(string $email, #[SensitiveParameter] string $password, string $ip): LoginResult
    {
        $email = mb_strtolower(trim($email));
        if ($this->throttle->tooManyAttempts($email, $ip)) {
            return LoginResult::Throttled;
        }
        $row = $email === '' ? null : $this->db->fetchOne(
            'SELECT id, password_hash, session_version, is_active FROM users WHERE email = :email',
            ['email' => $email],
        );
        $hash = (string) ($row['password_hash'] ?? self::DUMMY_HASH);
        $valid = password_verify($password, $hash) && $row !== null && $row['is_active'];
        $this->throttle->record($email, $ip, $valid);
        if (!$valid) {
            return LoginResult::Invalid;
        }
        if (password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
            $this->db->run('UPDATE users SET password_hash = :h WHERE id = :id', [
                'h' => password_hash($password, PASSWORD_ARGON2ID),
                'id' => (int) $row['id'],
            ]);
        }
        $this->session->regenerate();
        $this->session->forget('_csrf');
        $this->session->set('auth.user_id', (int) $row['id']);
        $this->session->set('auth.session_version', (int) $row['session_version']);
        $this->session->set('auth.login_at', time());
        $this->session->set('auth.last_seen', time());
        $this->db->run('UPDATE users SET last_login_at = NOW() WHERE id = :id', ['id' => (int) $row['id']]);
        $this->resolved = false;
        $this->user = null;
        return LoginResult::Ok;
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->user = null;
        $this->resolved = true;
    }

    /** Sortie forcée (expiration, compte désactivé) : la session survit pour porter le message flash. */
    private function clearAuth(): void
    {
        foreach (['auth.user_id', 'auth.session_version', 'auth.login_at', 'auth.last_seen', '_csrf'] as $key) {
            $this->session->forget($key);
        }
        $this->session->regenerate();
        $this->user = null;
    }

    /** Déconnecte toutes les sessions ouvertes de l'utilisateur (changement de mot de passe, compromission). */
    public function revokeAllSessions(int $userId): void
    {
        $this->db->run('UPDATE users SET session_version = session_version + 1 WHERE id = :id', ['id' => $userId]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): CurrentUser
    {
        $role = Role::from((string) $row['role']);
        return new CurrentUser(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['email'],
            $role,
            (string) $row['locale'],
            $this->permissions->forRole($role),
            (bool) ($row['must_change_password'] ?? false),
        );
    }
}
