<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

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

        return $app;
    }
}
