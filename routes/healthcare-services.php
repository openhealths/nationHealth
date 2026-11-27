<?php

declare(strict_types=1);

use App\Livewire\Division\HealthcareService\HealthcareServiceCreate;
use App\Livewire\Division\HealthcareService\HealthcareServiceEdit;
use App\Livewire\Division\HealthcareService\HealthcareServiceIndex;
use App\Livewire\Division\HealthcareService\HealthcareServiceUpdate;
use App\Livewire\Division\HealthcareService\HealthcareServiceView;
use App\Models\HealthcareService;
use Illuminate\Support\Facades\Route;

Route::get('/healthcare-services', HealthcareServiceIndex::class)
    ->name('healthcare-services.index')
    ->can('viewAny', HealthcareService::class);

// The division is scoped to the legal entity and the healthcare service to the division
Route::prefix('division/{division}/healthcare-services')
    ->name('healthcare-services.')
    ->scopeBindings()
    ->whereNumber(['division', 'healthcareService'])
    ->middleware(['permission:division:read|division:details'])
    ->group(static function () {
        Route::get('/create', HealthcareServiceCreate::class)
            ->name('create')
            ->can('create', HealthcareService::class);
        Route::get('/{healthcareService}', HealthcareServiceView::class)
            ->name('show')
            ->can('view', 'healthcareService');
        Route::get('/{healthcareService}/edit', HealthcareServiceEdit::class)
            ->name('edit')
            ->can('edit', 'healthcareService');
        Route::get('/{healthcareService}/update', HealthcareServiceUpdate::class)
            ->name('update')
            ->can('update', 'healthcareService');
    });
