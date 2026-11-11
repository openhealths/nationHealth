<?php

declare(strict_types=1);

// Render the production drawer shells without booting database/API providers.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\ViewServiceProvider;
use RuntimeException;

$projectRoot = dirname(__DIR__, 2);
$app = new Application($projectRoot);
$app->instance('files', new Filesystem());
$app->instance('config', new Repository([
    'view' => [
        'paths' => [$projectRoot.'/resources/views'],
        'compiled' => $projectRoot.'/storage/framework/views',
    ],
]));
$app->register(EventServiceProvider::class);
$app->register(ViewServiceProvider::class);
$app->boot();
Facade::setFacadeApplication($app);

$pairs = [
    'service' => ['services-drawer', 'service-search-drawer'],
    'medication' => ['medication-form-drawer', 'medication-search-drawer'],
    'device' => ['medical-device-form-drawer', 'medical-device-search-drawer'],
];
$fixtures = [];

foreach ($pairs as $kind => [$formView, $searchView]) {
    $formSource = file_get_contents($projectRoot.'/resources/views/livewire/care-plan/parts/modals/'.$formView.'.blade.php');
    $searchSource = file_get_contents($projectRoot.'/resources/views/livewire/care-plan/parts/modals/'.$searchView.'.blade.php');

    // Keep the actual x-model, dimensions, stacking and event opt-in from each view.
    preg_match('/<x-dialog-drawer\b.*?>/s', $formSource, $formMatch);
    preg_match('/<x-dialog-drawer\b.*?>/s', $searchSource, $searchMatch);
    preg_match('/x-model="([^"]+)"/', $formMatch[0], $formModel);
    preg_match('/x-model="([^"]+)"/', $searchMatch[0], $searchModel);
    preg_match('/@click(?:\.stop)?="('.$searchModel[1].' = true)"/', $formSource, $openMatch);
    preg_match('/@click="('.$searchModel[1].' = false)"/', $searchSource, $cancelMatch);

    // Device reopening is a Livewire action; simulate its asynchronous UI transition.
    if (($kind === 'device' && str_contains($formSource, 'wire:click="openMedicalDeviceSearch"'))
        || ($kind === 'service' && str_contains($formSource, 'wire:click="openServiceSearch"'))) {
        $openMatch[1] = 'setTimeout(() => '.$searchModel[1].' = true, 25)';
    }

    if (!isset($openMatch[1], $cancelMatch[1])) {
        throw new RuntimeException('Missing production search open/cancel handler: '.$kind);
    }

    $formState = $formModel[1];
    $searchState = $searchModel[1];
    $source = <<<BLADE
        <div id="fixture" x-data="{
            $formState: false, $searchState: false,
            description: '', quantity: '', period: '', product: 'original',
            query: '', page: 1, showFilter: false
        }">
            <button id="start" @click="$formState = true">Open filled form</button>
            {$formMatch[0]}
                <input id="description" x-model="description" aria-label="Description">
                <input id="quantity" x-model="quantity" aria-label="Quantity">
                <input id="period" x-model="period" aria-label="Period">
                <span id="product" x-text="product"></span>
                <button id="reopen" @click="{$openMatch[1]}">Search again</button>
                <button id="close-form" @click="$formState = false">Close form</button>
            </x-dialog-drawer>
            {$searchMatch[0]}
                <input id="query" x-model="query" aria-label="Search">
                <button id="filter" @click="showFilter = !showFilter">Filters</button>
                <input id="filter-value" x-show="showFilter" aria-label="Filter">
                <button id="next" @click="page++">Next page</button>
                <span id="page" x-text="page"></span>
                <button id="select" @click="setTimeout(() => {
                    product = 'selected'; $searchState = false; $formState = true;
                }, 25)">Select result</button>
                <button id="cancel" @click="{$cancelMatch[1]}">Cancel</button>
            </x-dialog-drawer>
        </div>
        BLADE;

    $fixtures[$kind] = [
        'html' => Blade::render($source, [], true),
        'formState' => $formState,
        'searchState' => $searchState,
    ];
}

echo json_encode($fixtures, JSON_THROW_ON_ERROR);
