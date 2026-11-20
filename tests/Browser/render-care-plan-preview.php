<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Tests\Fixtures\CarePlan\ActivityPreview;

// php tests/Browser/render-care-plan-preview.php > care-plan-preview.html
// The generated HTML is standalone; it has no Livewire component or application routes.
$project = dirname(__DIR__, 2);
require $project.'/vendor/autoload.php';
$app = require $project.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->setLocale('uk');
view()->addNamespace('care-plan-fixtures', $project.'/tests/Fixtures/CarePlan');

echo view('care-plan-fixtures::activity-preview', [
    'cards' => ActivityPreview::cards(),
    'services' => ActivityPreview::services(),
])->render();
