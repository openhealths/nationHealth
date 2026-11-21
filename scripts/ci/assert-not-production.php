<?php

declare(strict_types=1);

(static function (): void {
    $root = dirname(__DIR__, 2);
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
    $cachePath = getenv('APP_CONFIG_CACHE');
    if (is_string($cachePath) && $cachePath !== '') {
        $cacheFiles[] = $cachePath;
    }
    if (is_file($root.'/.env')) {
        $contents = file_get_contents($root.'/.env');
        if ($contents === false) {
            $stop('Cannot read .env; refusing testing environment setup.');
        }
        // Read only these two settings; never source .env as shell code or change the environment.
        $pattern = '/^\h*(?:export\h+)?(APP_ENV|APP_CONFIG_CACHE)\h*=\h*(?:"([^"]*)"|\'([^\']*)\'|([^\s#]*))/m';
        preg_match_all($pattern, ltrim($contents, "\xEF\xBB\xBF"), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $value = ($match[2] ?? '') !== '' ? $match[2] : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''));
            if (strpos($value, '$') !== false) {
                $stop('Cannot resolve the application environment; refusing testing environment setup.');
            }
            if ($match[1] === 'APP_ENV') {
                $knownEnvironment = $check($value) || $knownEnvironment;
            } elseif ($value !== '') {
                $cacheFiles[] = $value;
            }
        }
    }

    foreach (array_unique($cacheFiles) as $path) {
        $path = $path[0] === '/' ? $path : $root.'/'.$path;
        if (!is_file($path)) {
            continue;
        }
        try {
            if (is_file($root.'/vendor/autoload.php')) {
                require_once $root.'/vendor/autoload.php';
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
