<?php

declare(strict_types=1);

use Dotenv\Parser\Parser;
use Dotenv\Store\StringStore;

(static function (): void {
    $root = dirname(__DIR__, 2);
    $autoload = $root.'/vendor/autoload.php';
    $stop = static function (string $message): void {
        fwrite(STDERR, $message."\n");
        exit(1);
    };
    $check = static function ($environment) use ($stop): bool {
        if (!is_string($environment) || trim($environment) === '') {
            return false;
        }
        if (strtolower(trim($environment)) === 'production') {
            $stop('Testing environment scripts cannot run in production.');
        }

        return true;
    };

    $knownEnvironment = false;
    foreach ([getenv('APP_ENV'), $_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null] as $environment) {
        $knownEnvironment = $check($environment) || $knownEnvironment;
    }

    $cacheFiles = [$root.'/bootstrap/cache/config.php'];
    foreach ([getenv('APP_CONFIG_CACHE'), $_SERVER['APP_CONFIG_CACHE'] ?? null, $_ENV['APP_CONFIG_CACHE'] ?? null] as $cachePath) {
        if (is_string($cachePath) && $cachePath !== '') {
            $cacheFiles[] = $cachePath;
        }
    }
    $envPath = $root.'/.env';
    if (file_exists($envPath) || is_link($envPath)) {
        $contents = is_file($envPath) ? file_get_contents($envPath) : false;
        if ($contents === false) {
            $stop('Cannot read .env; refusing testing environment setup.');
        }
        try {
            if (is_file($autoload)) {
                require_once $autoload;
            }
            if (!class_exists(Parser::class)) {
                $stop('Composer dependencies are required to verify .env; refusing testing environment setup.');
            }
            $entries = (new Parser())->parse((new StringStore($contents))->read());
        } catch (Throwable) {
            $stop('Cannot parse .env; refusing testing environment setup.');
        }

        // Read declarations without loading them or resolving their variable references.
        foreach ($entries as $entry) {
            $name = $entry->getName();
            if ($name !== 'APP_ENV' && $name !== 'APP_CONFIG_CACHE') {
                continue;
            }
            $value = $entry->getValue()->getOrElse(null);
            if ($value !== null && $value->getVars() !== []) {
                $stop('Cannot resolve the application environment; refusing testing environment setup.');
            }
            $setting = $value?->getChars();
            if ($name === 'APP_ENV') {
                $knownEnvironment = $check($setting) || $knownEnvironment;
            } elseif ($setting !== null && $setting !== '') {
                $cacheFiles[] = $setting;
            }
        }
    }

    foreach (array_unique($cacheFiles) as $path) {
        $absolute = str_starts_with($path, '/')
            || (DIRECTORY_SEPARATOR === '\\' && preg_match('~^(?:[a-z]:)?[/\\\\]~i', $path));
        if (!$absolute) {
            $path = $root.'/'.$path;
        }
        if (!is_file($path)) {
            continue;
        }
        try {
            if (is_file($autoload)) {
                require_once $autoload;
            }
            $cached = require $path;
            if (!is_array($cached) || !isset($cached['app']['env']) || !is_string($cached['app']['env']) || trim($cached['app']['env']) === '') {
                $stop('Cannot determine APP_ENV from cached configuration; refusing testing environment setup.');
            }
            $knownEnvironment = $check($cached['app']['env']) || $knownEnvironment;
        } catch (Throwable $exception) {
            $stop('Cannot inspect cached configuration; refusing testing environment setup.');
        }
    }

    if (!$knownEnvironment) {
        $stop('Cannot determine APP_ENV; refusing testing environment setup.');
    }
})();
