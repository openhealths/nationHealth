<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
require $root.'/tests/bootstrap.php';

$appKey = getenv('APP_KEY');
if ($appKey === false || $appKey === '') {
    fwrite(STDERR, "Generate a testing key first: php scripts/dev/testing-artisan.php key:generate\n");
    exit(1);
}

$artisan = static function (string $command) use ($root): void {
    $process = new Process([PHP_BINARY, 'scripts/dev/testing-artisan.php', $command], $root);
    $process->setTimeout(null);
    $process->mustRun(static function (string $type, string $output): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
    });
};

$psql = (new ExecutableFinder())->find('psql');
if (getenv('DB_CONNECTION') === 'pgsql' && $psql !== null) {
    $client = [$psql, '-X', '-w', '-v', 'ON_ERROR_STOP=1', '-h', getenv('DB_HOST'), '-p', getenv('DB_PORT'),
        '-U', getenv('DB_USERNAME')];
    $env = ['PGPASSWORD' => getenv('DB_PASSWORD')];
    $probe = new Process([...$client, '-d', 'testing', '-c', 'SELECT 1'], $root, $env);
    if ($probe->run() !== 0) {
        $maintenance = [...$client, '-d', 'postgres'];
        $exists = new Process([...$maintenance, '-Atc', "SELECT 1 FROM pg_database WHERE datname='testing'"], $root, $env);
        if ($exists->run() !== 0 || trim($exists->getOutput()) === '1') {
            fwrite(STDERR, $probe->getErrorOutput());
            fwrite(STDERR, $exists->getErrorOutput());
            exit(1);
        }

        $create = new Process([...$maintenance, '-c', 'CREATE DATABASE testing'], $root, $env);
        if ($create->run() !== 0) {
            fwrite(STDERR, $create->getErrorOutput());
            fwrite(STDERR, "Create the testing database with the configured role as owner (see README.md).\n");
            exit(1);
        }
    }
}

$artisan('config:clear');
$artisan('migrate:install');
$artisan('install');
$artisan('update');
echo 'Testing DB ready: '.getenv('DB_CONNECTION')." / testing\n";
