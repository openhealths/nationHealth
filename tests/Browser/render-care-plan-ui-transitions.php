<?php

declare(strict_types=1);

use App\Models\LegalEntity;
use App\Providers\BladeServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\View\ViewServiceProvider;

$project = dirname(__DIR__, 2);
require $project.'/vendor/autoload.php';
$app = new Application($project);
$app->useLangPath($project.'/resources/lang');
$app->instance('files', new Filesystem());
$app->instance('request', Request::create('http://localhost'));
$app->instance('config', new Repository([
    'app' => ['locale' => 'uk', 'fallback_locale' => 'en'],
    'view' => ['paths' => [$project.'/resources/views'], 'compiled' => $project.'/storage/framework/views'],
]));
Facade::setFacadeApplication($app);
foreach ([EventServiceProvider::class, TranslationServiceProvider::class, ViewServiceProvider::class,
    RoutingServiceProvider::class, BladeServiceProvider::class] as $provider) {
    $app->register($provider);
}
$app->boot();
$app['router']->get('/dashboard/{legalEntity}/icd10', static fn () => [])->name('icd10.lookup');
$app['router']->getRoutes()->refreshNameLookups();
$app->instance('legalEntity', (new LegalEntity())->forceFill(['id' => 1]));

// Use the actual form listener, selection method, picker and teleported drawer.
$request = file_get_contents($project.'/resources/views/livewire/device-request/device-request-form.blade.php');
preg_match('/<div\s+x-data="[^"]*"\s+@device-request-medical-record-selected[^>]+>/s', $request, $root);
preg_match('/<x-dialog-drawer x-model="recordSearch"[^>]*>.*?<\/x-dialog-drawer>/s', $request, $drawer);
if (!isset($root[0], $drawer[0])) {
    throw new RuntimeException('Missing request event listener or medical-record drawer');
}
$record = ['uuid' => 'record-one', 'type' => 'condition', 'typeLabel' => 'Condition', 'label' => 'Test diagnosis', 'date' => '20.11.2026'];
$requestFixture = str_replace('<div', '<div id="request-fixture"', $root[0]).<<<'BLADE'
    <button id="open-records" @click="recordSearch = true">Open records</button>
    <table id="selected-records"><tbody>
        <template x-for="record in $wire.request.reason_reference" :key="record.uuid">
            <tr><td x-text="record.uuid"></td></tr>
        </template>
    </tbody></table>
    BLADE.$drawer[0].'</div>';
$fixtures = ['request' => Blade::render($requestFixture, [
    'carePlanUuid' => null, 'activityUuid' => null, 'planChoices' => [], 'recordRows' => [$record],
], true)];

foreach (['service' => 'showServiceDrawer', 'medication' => 'showMedicationFormDrawer'] as $prefix => $state) {
    $source = '<div id="grounds-fixture" x-data="{}"><div x-show="$wire.'.$state.'">'
        ."@include('livewire.care-plan.parts.activity.grounds-fields')".'</div></div>';
    $fixtures[$prefix] = Blade::render($source, ['inputPrefix' => $prefix, 'linkedGrounds' => []], true);
}

$devices = [];
for ($index = 0; $index < 22; $index++) {
    $devices[] = [
        'display_uuid' => 'device-'.$index, 'display_name' => 'Model '.$index,
        'model_number' => 'MODEL-'.$index,
        'classification_types' => [['code' => $index === 21 ? 'target' : 'other']],
        'program_devices' => [['name' => 'Program '.$index, 'max_daily_count' => $index + 1]],
    ];
}
$fixtures['device'] = Blade::render(
    '<div id="device-fixture" x-data="{ showMedicalDeviceSearchDrawer: true, showMedicalDeviceFormDrawer: false }">'
        ."@include('livewire.care-plan.parts.modals.medical-device-search-drawer')".'</div>',
    [
        'deviceSearchCatalog' => $devices, 'searchResults' => array_slice($devices, 0, 20),
        'searchQuery' => '', 'deviceSearchModelNumber' => '', 'searchPage' => 1,
        'deviceSearchTotalPages' => 2,
        'dictionaries' => ['device_definition_classification_type' => ['other' => 'Other', 'target' => 'Target', 'absent' => 'Absent']],
    ],
    true
);

echo json_encode($fixtures, JSON_THROW_ON_ERROR);
