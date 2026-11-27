<?php

declare(strict_types=1);

use Dotenv\Loader\Loader;
use Dotenv\Parser\Parser;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Store\StringStore;
use Illuminate\Support\Env;

$root = dirname(__DIR__);
require $root.'/scripts/ci/assert-not-production.php';
require_once $root.'/vendor/autoload.php';

if (!is_file($root.'/.env.testing')) {
    throw new RuntimeException('Copy .env.testing.example to .env.testing before running tests.');
}

$entries = (new Parser())->parse((new StringStore(file_get_contents($root.'/.env.testing')))->read());
$selectedEntries = [];
$connectionKeys = ['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET', 'MYSQL_ATTR_SSL_CA'];
$connections = 0;
foreach ($entries as $entry) {
    $name = $entry->getName();
    if ($name === 'DB_CONNECTION') {
        $connections++;
    }

    $isConnectionSetting = str_starts_with($name, 'DB_') || in_array($name, ['DATABASE_URL', 'MYSQL_ATTR_SSL_CA'], true);
    if ($isConnectionSetting) {
        if ($connections === 0) {
            throw new RuntimeException('Start each database block in .env.testing with DB_CONNECTION before its connection settings.');
        }
        $connectionKeys[] = $name;
    }
    if (!$isConnectionSetting || $connections === 1) {
        $selectedEntries[] = $entry;
    }
}
if ($connections === 0) {
    throw new RuntimeException('No active database connection in .env.testing. Add a DB_CONNECTION block for pgsql, mysql or mariadb.');
}

// Resolve only file declarations; inherited process variables cannot select or fill a connection.
$repository = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();
$settings = (new Loader())->load($repository, $selectedEntries);
$driver = $settings['DB_CONNECTION'] ?? '';
if (!in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
    throw new RuntimeException('Set DB_CONNECTION in the first active .env.testing block to pgsql, mysql or mariadb.');
}
if (($settings['DB_DATABASE'] ?? '') !== 'testing' || !empty($settings['DATABASE_URL']) || !empty($settings['DB_URL'])) {
    throw new RuntimeException('Expected database [testing] configured with DB_* settings in the first active .env.testing block.');
}
foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME'] as $key) {
    if (($settings[$key] ?? '') === '') {
        throw new RuntimeException("Missing testing connection setting [$key] in the first active .env.testing block.");
    }
}
$noticeKey = 'MIS_TESTING_DATABASE_NOTICE';
$notice = hash('sha256', $root.'|'.$driver.'|'.$connections);
if ($connections > 1 && getenv($noticeKey) !== $notice) {
    fwrite(STDERR, "Found $connections active database blocks in .env.testing; using the first [$driver, database testing]. Comment out unused blocks to select another connection.\n");
    putenv($noticeKey.'='.$notice);
    $_ENV[$noticeKey] = $_SERVER[$noticeKey] = $notice;
}

$storage = $root.'/storage/cli-testing';
$cachePath = $storage.'/bootstrap/cache';
foreach (['bootstrap/cache', 'framework/views', 'framework/cache', 'framework/sessions', 'framework/testing'] as $dir) {
    $path = $storage.'/'.$dir;
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("Cannot create testing directory [$path].");
    }
}

// Rebuild testing configuration from the selected file block, including after a driver switch.
$configurationCache = $cachePath.'/config.php';
if ((file_exists($configurationCache) || is_link($configurationCache)) && !unlink($configurationCache)) {
    throw new RuntimeException("Cannot invalidate testing configuration [$configurationCache].");
}

// Laravel discovers packages from installed.json and reads exclusions from composer.json.
$vendor = Env::get('COMPOSER_VENDOR_DIR', $settings['COMPOSER_VENDOR_DIR'] ?? null) ?: $root.'/vendor';
$packagesHash = hash_file('sha256', $vendor.'/composer/installed.json');
$projectHash = hash_file('sha256', $root.'/composer.json');
if ($packagesHash === false || $projectHash === false) {
    throw new RuntimeException('Cannot verify the Composer metadata used by testing manifests.');
}
$manifestHash = hash('sha256', $packagesHash.$projectHash);
$stamp = fopen($cachePath.'/composer-manifests.sha256', 'c+');
if ($stamp === false) {
    throw new RuntimeException('Cannot open the testing manifest fingerprint file.');
}
try {
    if (!flock($stamp, LOCK_EX)) {
        throw new RuntimeException('Cannot lock the testing manifest fingerprint file.');
    }
    if (stream_get_contents($stamp) !== $manifestHash) {
        foreach (['packages.php', 'services.php'] as $file) {
            $manifest = $cachePath.'/'.$file;
            if ((file_exists($manifest) || is_link($manifest)) && !unlink($manifest)) {
                throw new RuntimeException("Cannot invalidate testing manifest [$manifest].");
            }
        }
        if (!rewind($stamp) || !ftruncate($stamp, 0) || fwrite($stamp, $manifestHash) !== strlen($manifestHash) || !fflush($stamp)) {
            throw new RuntimeException('Cannot update the testing manifest fingerprint.');
        }
    }
} finally {
    fclose($stamp);
}

$environment = [];
foreach (array_unique($connectionKeys) as $key) {
    // Also pin absent settings so later blocks cannot leak in when Laravel loads .env.testing.
    $environment[$key] = $settings[$key] ?? '';
}
$environment = array_replace($environment, [
    'APP_BASE_PATH' => $root,
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => $driver,
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
    'EHEALTH_API_URL' => $settings['EHEALTH_API_URL'] ?? 'https://ehealth.invalid',
    'EHEALTH_SEED_ICD10' => 'false',
    'DB_POOLED' => 'false',
    'LARAVEL_STORAGE_PATH' => $storage,
    'VIEW_COMPILED_PATH' => $storage.'/framework/views',
    'APP_CONFIG_CACHE' => $cachePath.'/config.php',
    'APP_SERVICES_CACHE' => $cachePath.'/services.php',
    'APP_PACKAGES_CACHE' => $cachePath.'/packages.php',
    'APP_EVENTS_CACHE' => $cachePath.'/events.php',
    'APP_ROUTES_CACHE' => $cachePath.'/routes-v7.php',
    'APP_KEY' => $settings['APP_KEY'] ?? '',
]);
foreach (['HOST', 'PORT', 'USERNAME', 'PASSWORD', 'SSLMODE'] as $key) {
    $environment['DB_DIRECT_'.$key] = '';
}
foreach ($environment as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
