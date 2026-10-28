<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'app' => [
        'name' => Env::get('APP_NAME', 'ETHAN'),
        'env' => Env::get('APP_ENV', 'production'),
        'debug' => Env::bool('APP_DEBUG'),
        'url' => Env::get('APP_URL', 'http://localhost'),
        'base_path' => Env::get('APP_BASE_PATH', ''),
        'timezone' => Env::get('APP_TIMEZONE', 'Africa/Lubumbashi'),
        'locale' => Env::get('APP_LOCALE', 'fr'),
        'locales' => ['fr', 'en'],
        'currency' => Env::get('APP_CURRENCY', 'USD'),
        'key' => Env::get('APP_KEY', ''),
        'reference_prefix' => 'ETH',
    ],
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::int('DB_PORT', 3306),
        'name' => Env::get('DB_NAME', 'rodnes'),
        'test_name' => Env::get('DB_TEST_NAME', 'rodnes_test'),
        'user' => Env::get('DB_USER', 'ethan_app'),
        'pass' => Env::get('DB_PASS', ''),
    ],
    'session' => [
        'name' => 'ethan_sid',
        'idle_minutes' => Env::int('SESSION_IDLE_MINUTES', 30),
        'absolute_hours' => Env::int('SESSION_ABSOLUTE_HOURS', 12),
    ],
    'security' => [
        'login_window_minutes' => 15,
        'login_max_per_email' => 5,
        'login_max_per_ip' => 20,
        'password_min_length' => 12,
    ],
    'uploads' => [
        'max_bytes' => 10 * 1024 * 1024,
        'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
        // Documents clients (RFQ) : bureautique et e-mails en plus des PDF / images.
        'document_mimes' => [
            'image/jpeg', 'image/png', 'image/webp', 'application/pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword',
            'application/vnd.ms-outlook', 'application/CDFV2', 'message/rfc822', 'text/plain', 'text/csv', 'application/zip',
        ],
    ],
    'mail' => [
        'host' => Env::get('MAIL_HOST', ''),
        'port' => Env::int('MAIL_PORT', 587),
        'user' => Env::get('MAIL_USER', ''),
        'pass' => Env::get('MAIL_PASS', ''),
        'from' => Env::get('MAIL_FROM', 'no-reply@example.com'),
        'from_name' => Env::get('MAIL_FROM_NAME', 'ETHAN'),
    ],
];
