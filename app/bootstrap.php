<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Logger;
use App\Core\Router;
use App\Core\Session;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

Env::load(BASE_PATH . '/.env');

$container = Container::instance();
$config = new Config(require BASE_PATH . '/config/app.php');
$container->put(Container::class, $container);
$container->put(Config::class, $config);
$container->put(Logger::class, new Logger(BASE_PATH . '/storage/logs'));
$container->set(Router::class, static function (Container $c): Router {
    $router = new Router($c, $c->get(Csrf::class), $c->get(Session::class));
    (require BASE_PATH . '/config/routes.php')($router);
    return $router;
});

date_default_timezone_set((string) $config->get('app.timezone'));
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', PHP_SAPI === 'cli' || $config->get('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if ($config->get('app.env') === 'production') {
    $key = base64_decode((string) $config->get('app.key'), true);
    if ($key === false || strlen($key) < 32) {
        throw new RuntimeException('APP_KEY absente ou trop courte : générer avec php bin/keygen.php');
    }
    if ($config->get('app.debug')) {
        throw new RuntimeException('APP_DEBUG doit être désactivé en production.');
    }
}

return $container;
