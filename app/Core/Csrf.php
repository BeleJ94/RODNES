<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Jeton de synchronisation par session (champ _csrf ou en-tête X-CSRF-Token)
 * + contrôle de l'en-tête Origin quand le navigateur l'envoie (défense en profondeur).
 */
final class Csrf
{
    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_csrf', $token);
        }
        return $token;
    }

    public function verify(Request $request): bool
    {
        $sent = $request->input('_csrf') ?? $request->header('X-CSRF-Token');
        $expected = $this->session->get('_csrf');
        if (!is_string($sent) || !is_string($expected) || !hash_equals($expected, $sent)) {
            return false;
        }
        return self::sameOrigin($request->header('Origin'), $request->header('Host'));
    }

    public static function sameOrigin(?string $origin, ?string $host): bool
    {
        if ($origin === null || $origin === '') {
            return true;
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['host']) || $host === null) {
            return false;
        }
        $originHost = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        return hash_equals(strtolower($host), $originHost);
    }
}
