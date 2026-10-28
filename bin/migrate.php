<?php

declare(strict_types=1);

// php bin/migrate.php [--test] [--create]
//   --test   : cible la base de test (DB_TEST_NAME)
//   --create : crée la base si elle n'existe pas (poste de développement)

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$container = require dirname(__DIR__) . '/app/bootstrap.php';
$config = $container->get(Config::class);
$db = (array) $config->get('db');
$timezone = (string) $config->get('app.timezone');

if (in_array('--test', $argv, true)) {
    $db['name'] = $db['test_name'];
}
if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $db['name'])) {
    fwrite(STDERR, "Nom de base invalide.\n");
    exit(1);
}
if (in_array('--create', $argv, true)) {
    Database::connect($db, $timezone, false)
        ->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

$migrator = new Migrator(Database::connect($db, $timezone), BASE_PATH . '/database/migrations');
$count = $migrator->migrate(static function (string $line): void {
    echo $line, PHP_EOL;
});
echo $count === 0
    ? "Base « {$db['name']} » à jour." . PHP_EOL
    : "{$count} migration(s) appliquée(s) sur « {$db['name']} »." . PHP_EOL;
