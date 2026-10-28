<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/** Sonde de déploiement et de supervision : aucune information de version ni de configuration. */
final class HealthController
{
    public function __construct(private Database $db)
    {
    }

    public function show(Request $request): Response
    {
        try {
            $this->db->fetchValue('SELECT 1');
            return Response::json(['status' => 'ok']);
        } catch (Throwable) {
            return Response::json(['status' => 'degraded'], 503);
        }
    }
}
