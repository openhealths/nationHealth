<?php

declare(strict_types=1);

use App\Jobs\ObservationConfigurationSync;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$root = dirname(__DIR__, 2);
require $root.'/tests/bootstrap.php';

if ($argc !== 2 || !in_array($argv[1], ['key:generate', 'config:clear', 'migrate:install', 'install', 'update', 'test'], true)) {
    fwrite(STDERR, "Expected a testing setup command or test.\n");
    exit(1);
}
$app = require $root.'/tests/application.php';
if ($argv[1] === 'update') {
    $app->booted(static fn () => Bus::fake([ObservationConfigurationSync::class]));
}
$kernel = $app->make(Kernel::class);
$input = new ArgvInput([$argv[0], $argv[1], '--env=testing', '--no-interaction']);
try {
    $shouldRun = true;
    if ($argv[1] === 'migrate:install') {
        $kernel->bootstrap();
        $shouldRun = !$app->make('migrator')->repositoryExists();
    }
    $status = $shouldRun ? $kernel->handle($input, new ConsoleOutput()) : 0;
    $kernel->terminate($input, $status);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Testing command failed: '.$exception->getMessage()."\n");
    exit(1);
}
exit($status);
