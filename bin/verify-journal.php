<?php

declare(strict_types=1);

// Vérifie l'intégrité des journaux scellés des expéditions (jalons et POD). À planifier chaque nuit.
// Code de sortie 1 si au moins un journal a été altéré.

use App\Core\Database;
use App\Services\ShipmentService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$container = require dirname(__DIR__) . '/app/bootstrap.php';
$db = $container->get(Database::class);
$service = $container->get(ShipmentService::class);

$broken = 0;
$checked = 0;
foreach ($db->fetchAll('SELECT id, reference FROM shipments ORDER BY id') as $shipment) {
    $checked++;
    $eventId = $service->verify((int) $shipment['id']);
    if ($eventId !== null) {
        $broken++;
        fwrite(STDERR, "ALTÉRÉ : {$shipment['reference']}, à partir de l'événement #{$eventId}" . PHP_EOL);
    }
}
echo "{$checked} journal(aux) d'expédition vérifié(s), {$broken} altéré(s)." . PHP_EOL;
exit($broken > 0 ? 1 : 0);
