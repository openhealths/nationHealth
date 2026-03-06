<?php

declare(strict_types=1);

use App\Livewire\Dictionary\DrugList;
use App\Livewire\Dictionary\MedicationProgram;
use App\Livewire\Dictionary\ServiceProgram;

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
        Route::get('/drug-list', DrugList::class)
            ->name('drug-list.index');

        Route::get('/medication-programs', MedicationProgram::class)
            ->name('medication-programs.index');

        Route::get('/service-programs', ServiceProgram::class)
            ->name('service-programs.index');
    });
