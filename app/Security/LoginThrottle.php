<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config;
use App\Core\Database;

/**
 * Limite le bourrage d'identifiants : N échecs par e-mail et M par adresse IP sur une fenêtre glissante.
 * Le blocage par e-mail est volontairement temporaire (fenêtre) pour qu'un attaquant ne puisse pas
 * bloquer durablement le compte d'un tiers.
 */
final class LoginThrottle
{
    public function __construct(private Database $db, private Config $config)
    {
    }

    public function tooManyAttempts(string $email, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - (int) $this->config->get('security.login_window_minutes', 15) * 60);
        $byEmail = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE email = :email AND success = 0 AND created_at >= :since',
            ['email' => $email, 'since' => $since],
        );
        if ($byEmail >= (int) $this->config->get('security.login_max_per_email', 5)) {
            return true;
        }
        $byIp = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND success = 0 AND created_at >= :since',
            ['ip' => self::packIp($ip), 'since' => $since],
        );
        return $byIp >= (int) $this->config->get('security.login_max_per_ip', 20);
    }

    public function record(string $email, string $ip, bool $success): void
    {
        $this->db->run(
            'INSERT INTO login_attempts (email, ip, success) VALUES (:email, :ip, :success)',
            ['email' => mb_substr($email, 0, 190), 'ip' => self::packIp($ip), 'success' => $success],
        );
        if (random_int(1, 100) === 1) {
            $this->db->run('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 30 DAY');
        }
    }

    public static function packIp(string $ip): ?string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? null : (string) inet_pton($ip);
    }
}
