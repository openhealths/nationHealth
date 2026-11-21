<?php

declare(strict_types=1);

use Tests\Support\IsolatedConnectionFactory;

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->extend('db.factory', static fn ($factory, $container) => new IsolatedConnectionFactory($container));

return $app;
