<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session PHP native durcie : cookie HttpOnly / SameSite=Lax / Secure en HTTPS, mode strict,
 * stockage dans storage/sessions. Les messages flash ne vivent que jusqu'à la requête suivante.
 */
final class Session
{
    private bool $started = false;

    /** @var array<string, mixed> */
    private array $flashNow = [];

    public function __construct(private Config $config)
    {
    }

    public function start(bool $secure): void
    {
        if ($this->started) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
            ini_set('session.gc_maxlifetime', (string) ((int) $this->config->get('session.absolute_hours', 12) * 3600));
            $dir = BASE_PATH . '/storage/sessions';
            if (is_dir($dir) && is_writable($dir)) {
                session_save_path($dir);
            }
            session_name((string) $this->config->get('session.name', 'ethan_sid'));
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => rtrim((string) $this->config->get('app.base_path', ''), '/') . '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        $this->started = true;
        $this->flashNow = is_array($_SESSION['_flash'] ?? null) ? $_SESSION['_flash'] : [];
        unset($_SESSION['_flash']);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    /** Valeur disponible à la requête suivante (après redirection). */
    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->flashNow[$key] ?? $default;
    }

    public function hasOld(): bool
    {
        return is_array($this->flashNow['_old'] ?? null);
    }

    public function old(string $field, string $default = ''): string
    {
        $old = $this->flashNow['_old'] ?? [];
        return is_array($old) && is_scalar($old[$field] ?? null) ? (string) $old[$field] : $default;
    }

    /** Nouvel identifiant à la connexion : empêche la fixation de session. */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_destroy();
        }
        $this->started = false;
    }
}
