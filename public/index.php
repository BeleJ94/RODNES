<?php

declare(strict_types=1);

use App\Core\App;

// Serveur intégré (composer serve) : les fichiers statiques existants sont servis tels quels.
if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

$container = require dirname(__DIR__) . '/app/bootstrap.php';
(new App($container))->run();
