<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$failedPlans = App\Models\CarePlan::where('status', 'failed')->latest()->take(3)->get();
foreach ($failedPlans as $plan) {
    echo "ID: " . $plan->uuid . "\n";
    echo "Status: " . $plan->status . "\n";
    echo "Reason: " . ($plan->status_reason ?? 'null') . "\n";
    echo "Desc: " . ($plan->description ?? 'null') . "\n";
}
