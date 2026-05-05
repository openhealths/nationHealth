<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$jobApi = App\Classes\eHealth\EHealth::job();
$response = $jobApi->getDetails('69f1ce0d06667b0046273ba8')->getData();
echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
