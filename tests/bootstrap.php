<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Container;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

date_default_timezone_set('Africa/Lubumbashi');

Container::instance()->put(Container::class, Container::instance());
Container::instance()->put(Config::class, new Config([
    'app' => ['locale' => 'fr', 'locales' => ['fr', 'en'], 'base_path' => ''],
]));
