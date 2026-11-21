<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$root = dirname(__DIR__, 2);
require $root.'/tests/bootstrap.php';

if ($argc !== 2 || !in_array($argv[1], ['key:generate', 'config:clear', 'migrate:install', 'install', 'update', 'test'], true)) {
    fwrite(STDERR, "Expected a testing setup command or test.\n");
    exit(1);
}
$app = require $root.'/tests/application.php';
$kernel = $app->make(Kernel::class);
$input = new ArgvInput([$argv[0], $argv[1], '--env=testing', '--no-interaction']);
$status = $kernel->handle($input, new ConsoleOutput());
$kernel->terminate($input, $status);
exit($status);
