<?php

declare(strict_types=1);

use App\Livewire\CarePlan\CarePlanCreate;
use App\Livewire\Declaration\DeclarationCreate;
use App\Livewire\Declaration\DeclarationEdit;
use App\Livewire\Declaration\DeclarationView;
use App\Livewire\DiagnosticReport\DiagnosticReportCreate;
use App\Livewire\Encounter\EncounterCreate;
use App\Livewire\Encounter\EncounterEdit;
use App\Livewire\Person\PersonCreate;
use App\Livewire\Person\PersonIndex;
use App\Livewire\Person\PersonRequestEdit;
use App\Livewire\Person\PersonUpdate;
use App\Livewire\Person\Records\PatientCarePlans;
use App\Livewire\Person\Records\PatientClinicalImpressions;
use App\Livewire\Person\Records\PatientConditions;
use App\Livewire\Person\Records\PatientData;
use App\Livewire\Person\Records\PatientDiagnoses;
use App\Livewire\Person\Records\PatientDiagnosticReports;
use App\Livewire\Person\Records\PatientEncounters;
use App\Livewire\Person\Records\PatientEpisodes;
use App\Livewire\Person\Records\PatientImmunizations;
use App\Livewire\Person\Records\PatientObservations;
use App\Livewire\Person\Records\PatientSummary;
use App\Livewire\Procedure\ProcedureCreate;
use App\Models\DeclarationRequest;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Person\Person;
use App\Models\Person\PersonRequest;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Person / Patient Routes
|--------------------------------------------------------------------------
|
| Person- and patient-related routes that will be included in the main route
| group. Inherits the '/dashboard/{legalEntity}' prefix, the 'auth:web,ehealth'
| and 'can:access,legalEntity' middleware from the parent group in web.php.
|
*/

Route::prefix('persons')->group(static function () {
    Route::name('persons.')->group(static function () {
        Route::get('/', PersonIndex::class)->can('viewAny', Person::class)->name('index');
        Route::get('/create', PersonCreate::class)->can('create', PersonRequest::class)->name('create');
        Route::get('/edit/{personRequest}', PersonRequestEdit::class)
            ->can('create', PersonRequest::class)
            ->name('edit');
        Route::get('/update/{person}', PersonUpdate::class)->can('create', PersonRequest::class)->name('update');

        Route::middleware('can:view,' . Person::class)->group(function () {
            Route::get('/{personId}/patient-data', PatientData::class)->name('patient-data');
            Route::get('/{personId}/summary', PatientSummary::class)->can('view', Person::class)->name('summary');
            Route::get('/{personId}/episodes', PatientEpisodes::class)->can('view', Episode::class)->name('episodes');
            Route::get('/{personId}/care-plans', PatientCarePlans::class)->name('care-plans');
            Route::get('/{personId}/observations', PatientObservations::class)->name('observations');
            Route::get('/{personId}/immunizations', PatientImmunizations::class)->name('immunizations');
            Route::get('/{personId}/conditions', PatientConditions::class)->name('conditions');
            Route::get('/{personId}/diagnoses', PatientDiagnoses::class)->name('diagnoses');
            Route::get('/{personId}/diagnostic-reports', PatientDiagnosticReports::class)->name('diagnostic-reports');
            Route::get('/{personId}/clinical-impressions', PatientClinicalImpressions::class)
                ->name('clinical-impressions');
            Route::get('/{personId}/encounters', PatientEncounters::class)->name('encounters');
        });
    });

    Route::name('declaration.')->group(static function () {
        Route::get('/declaration/{declaration}', DeclarationView::class)
            ->can('view', 'declaration')
            ->name('view')
            ->whereNumber('declaration');
        Route::get('/{personId}/declaration/create', DeclarationCreate::class)
            ->name('create')
            ->can('create', DeclarationRequest::class)
            ->whereNumber('personId');
        Route::get('/{personId}/declaration/{declarationRequest}', DeclarationEdit::class)
            ->name('edit')
            ->can('update', 'declarationRequest')
            ->whereNumber(['personId', 'declarationRequest']);
    });

    Route::middleware('can:create,' . Encounter::class)->name('encounter.')->group(function () {
        Route::get('/{personId}/encounter/create', EncounterCreate::class)->name('create');
        Route::get('/{personId}/encounter/{encounterId}', EncounterEdit::class)->name('edit');
    });

    Route::get('/{personId}/care-plan/create', CarePlanCreate::class)->name('care-plan.create');

    Route::whereNumber('personId')->group(static function () {
        Route::get('{personId}/diagnostic-report/create', DiagnosticReportCreate::class)
            ->can('create', DiagnosticReport::class)
            ->name('diagnostic-report.create');

        Route::get('{personId}/procedure/create', ProcedureCreate::class)
            ->can('create', Procedure::class)
            ->name('procedure.create');
    });
});
