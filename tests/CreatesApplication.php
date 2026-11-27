<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/application.php';
        $storagePath = $app->storagePath();

        $app->make(Kernel::class)->bootstrap();

        // The Passport guard needs RSA keys in the test storage path; they are gitignored, so a fresh checkout has none.
        if (!is_file($storagePath . '/oauth-public.key')) {
            $app->make(Kernel::class)->call('passport:keys', ['--force' => true]);
        }

        // PHPUnit must never open mis_dev, even when Sail exports that name into the environment.
        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if ($database !== 'testing') {
            throw new RuntimeException("Refusing to run tests against database [{$database}]. Expected [testing].");
        }

        return $app;
    }
}
