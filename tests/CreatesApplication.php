<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\ParallelTesting;
use Tests\Support\TestingDatabase;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $storagePath = realpath(__DIR__.'/../storage') . '/cli-testing';
        if (!is_dir($storagePath)) {
            @mkdir($storagePath, 0777, true);
        }
        foreach (['framework/views', 'framework/cache', 'framework/sessions', 'framework/testing'] as $dir) {
            $path = $storagePath . '/' . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0777, true);
            }
        }

        $app->useStoragePath($storagePath);

        $app->make(Kernel::class)->bootstrap();

        // The Passport guard needs RSA keys in the test storage path; they are gitignored, so a fresh checkout has none.
        if (!is_file($storagePath . '/oauth-public.key')) {
            $app->make(Kernel::class)->call('passport:keys', ['--force' => true]);
        }

        // PHPUnit must never open mis_dev, even when Sail exports that name into the environment.
        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        TestingDatabase::assertIsolated($database, ParallelTesting::token(), allowBase: true);

        return $app;
    }
}
