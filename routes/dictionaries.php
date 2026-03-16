<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Livewire\Dictionary\DrugList;
use App\Livewire\Dictionary\MedicationProgram;
use App\Livewire\Dictionary\ServiceCatalog;
use App\Livewire\Dictionary\ServiceProgram;
use App\Livewire\Dictionary\ConditionDiagnose;
use App\Livewire\Dictionary\ForbiddenGroup;
use App\Livewire\Dictionary\MedicalDevice;

/*
|--------------------------------------------------------------------------
| Dictionaries Routes
|--------------------------------------------------------------------------
|
| Dictionary-related routes that will be included in the main route group.
| Uses prefix 'dictionaries' and name prefix 'dictionaries.' for consistency.
|
*/

Route::prefix('dictionaries')->name('dictionaries.')
    ->group(function () {
        Route::get('/medication-programs', MedicationProgram::class)
            ->name('medication-programs.index');

        Route::get('/service-programs', ServiceProgram::class)
            ->name('service-programs.index');

        Route::get('/drug-list', DrugList::class)
            ->can('drugs')
            ->name('drug-list.index');

        Route::get('/service-catalog', ServiceCatalog::class)
            ->name('service-catalog.index');

        Route::get('/condition-diagnose', ConditionDiagnose::class)
            ->name('condition-diagnose.index');

        Route::get('/forbidden-group', ForbiddenGroup::class)
            ->name('forbidden-group.index');

        Route::get('/medical-device', MedicalDevice::class)
            ->name('medical-device.index');
    });
