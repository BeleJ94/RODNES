<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Response;

final class SecurityHeaders
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; frame-src 'self' blob:; font-src 'self'; connect-src 'self'; object-src 'none'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    /**
     * Document ou fichier affichable dans la fenêtre d'aperçu d'ETHAN : cadre autorisé pour la même origine
     * seulement (frame-ancestors 'self'), toutes les autres pages restant non encadrables.
     */
    public static function framableBySelf(Response $response, ?string $csp = null): Response
    {
        $response->headers['Content-Security-Policy'] = str_replace("frame-ancestors 'none'", "frame-ancestors 'self'", $csp ?? self::CSP);
        $response->headers['X-Frame-Options'] = 'SAMEORIGIN';
        return $response;
    }

    public static function apply(Response $response, bool $https): Response
    {
        $response->headers += [
            'Content-Security-Policy' => self::CSP,
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            // Caméra et géolocalisation : preuves de livraison (photo + position) en mode terrain.
            'Permissions-Policy' => 'camera=(self), geolocation=(self), microphone=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            // Pages dynamiques jamais mises en cache : postes partagés sur les bases et sites miniers.
            'Cache-Control' => 'no-store',
        ];
        if ($https) {
            $response->headers += ['Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'];
        }
        return $response;
    }
}
