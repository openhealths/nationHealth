<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Tests\Support\IsolatedConnectionFactory;

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->extend('db.factory', static fn ($factory, $container) => new IsolatedConnectionFactory($container));
$app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
    if ($app['config']->get('database.default') === 'mariadb' && !$app['config']->has('database.connections.mariadb')) {
        $app['config']->set('database.connections.mariadb', array_replace(
            $app['config']->get('database.connections.mysql'),
            ['driver' => 'mariadb']
        ));
    }
});

return $app;
