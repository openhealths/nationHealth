<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root.'/scripts/ci/assert-not-production.php';
require_once $root.'/vendor/autoload.php';

if (!is_file($root.'/.env.testing')) {
    throw new RuntimeException('Copy .env.testing.example to .env.testing before running tests.');
}

$settings = Dotenv::parse(file_get_contents($root.'/.env.testing'));
if (($settings['DB_CONNECTION'] ?? '') !== 'pgsql' || ($settings['DB_DATABASE'] ?? '') !== 'testing'
    || !empty($settings['DATABASE_URL']) || !empty($settings['DB_URL'])) {
    throw new RuntimeException('Expected a PostgreSQL testing database configured with DB_* settings.');
}

// CI supplies job credentials through the process environment. Locally the file owns the connection.
$ci = getenv('GITHUB_ACTIONS') === 'true' || getenv('TF_BUILD') === 'True';
$storage = $root.'/storage/cli-testing';
foreach (['bootstrap/cache', 'framework/views', 'framework/cache', 'framework/sessions', 'framework/testing'] as $dir) {
    $path = $storage.'/'.$dir;
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("Cannot create testing directory [$path].");
    }
}

$environment = [
    'APP_BASE_PATH' => $root,
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'testing',
    'DATABASE_URL' => '',
    'DB_URL' => '',
    'CACHE_DRIVER' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'FILESYSTEM_DISK' => 'local',
    'LOG_CHANNEL' => 'single',
    'LOG_DEPRECATIONS_CHANNEL' => 'null',
    'BROADCAST_DRIVER' => 'null',
    'TELESCOPE_ENABLED' => 'false',
    'EHEALTH_SEED_ICD10' => 'false',
    'DB_POOLED' => 'false',
    'LARAVEL_STORAGE_PATH' => $storage,
    'VIEW_COMPILED_PATH' => $storage.'/framework/views',
    'APP_CONFIG_CACHE' => $storage.'/bootstrap/cache/config.php',
    'APP_SERVICES_CACHE' => $storage.'/bootstrap/cache/services.php',
    'APP_PACKAGES_CACHE' => $storage.'/bootstrap/cache/packages.php',
    'APP_EVENTS_CACHE' => $storage.'/bootstrap/cache/events.php',
    'APP_ROUTES_CACHE' => $storage.'/bootstrap/cache/routes-v7.php',
];
foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'APP_KEY'] as $key) {
    $value = $ci ? getenv($key) : false;
    $environment[$key] = $value !== false ? $value : ($settings[$key] ?? '');
}
foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME'] as $key) {
    if ($environment[$key] === '') {
        throw new RuntimeException("Missing testing connection setting [$key].");
    }
}
foreach (['HOST', 'PORT', 'USERNAME', 'PASSWORD', 'SSLMODE'] as $key) {
    $environment['DB_DIRECT_'.$key] = '';
}
foreach ($environment as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
