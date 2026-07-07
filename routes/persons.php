<?php

declare(strict_types=1);

use App\Livewire\CarePlan\CarePlanCreate;
use App\Livewire\Declaration\DeclarationCreate;
use App\Livewire\Declaration\DeclarationEdit;
use App\Livewire\Declaration\DeclarationView;
use App\Livewire\DiagnosticReport\DiagnosticReportCreate;
use App\Livewire\DiagnosticReport\DiagnosticReportEdit;
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
use App\Livewire\Person\Records\PatientProcedures;
use App\Livewire\Person\Records\PatientEncounters;
use App\Livewire\Person\Records\PatientEpisodes;
use App\Livewire\Person\Records\PatientImmunizations;
use App\Livewire\Person\Records\PatientObservations;
use App\Livewire\Person\Records\PatientSummary;
use App\Livewire\Preperson\PrepersonData;
use App\Livewire\Preperson\PrepersonEdit;
use App\Livewire\Preperson\PrepersonIndex;
use App\Livewire\Procedure\ProcedureCreate;
use App\Livewire\Procedure\ProcedureEdit;
use App\Models\DeclarationRequest;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Person\Person;
use App\Models\Person\PersonRequest;
use App\Models\Preperson;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Person / Preperson Routes
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
            Route::get('/{person}/patient-data', PatientData::class)->name('patient-data');
            Route::get('/{person}/summary', PatientSummary::class)->can('view', Person::class)->name('summary');
            Route::get('/{person}/episodes', PatientEpisodes::class)->can('view', Episode::class)->name('episodes');
            Route::get('/{person}/care-plans', PatientCarePlans::class)->name('care-plans');
            Route::get('/{person}/observations', PatientObservations::class)->name('observations');
            Route::get('/{person}/immunizations', PatientImmunizations::class)->name('immunizations');
            Route::get('/{person}/conditions', PatientConditions::class)->name('conditions');
            Route::get('/{person}/diagnoses', PatientDiagnoses::class)->name('diagnoses');
            Route::get('/{person}/diagnostic-reports', PatientDiagnosticReports::class)->name('diagnostic-reports');
            Route::get('/{person}/clinical-impressions', PatientClinicalImpressions::class)->name('clinical-impressions');
            Route::get('/{person}/encounters', PatientEncounters::class)->name('encounters');
            Route::get('/{person}/procedures', PatientProcedures::class)->name('procedures');
        });
    });

    Route::name('declaration.')->group(static function () {
        Route::get('/declaration/{declaration}', DeclarationView::class)
            ->can('view', 'declaration')
            ->name('view')
            ->whereNumber('declaration');
        Route::get('/{person}/declaration/create', DeclarationCreate::class)
            ->name('create')
            ->can('create', DeclarationRequest::class)
            ->whereNumber('person');
        Route::get('/{person}/declaration/{declarationRequest}', DeclarationEdit::class)
            ->name('edit')
            ->can('update', 'declarationRequest')
            ->whereNumber(['person', 'declarationRequest']);
    });

    Route::middleware('can:create,' . Encounter::class)->name('encounter.')->group(function () {
        Route::get('/{person}/encounter/create', EncounterCreate::class)->name('create');
        Route::get('/{person}/encounter/{encounterId}', EncounterEdit::class)->name('edit');
    });

    Route::get('/{personId}/care-plan/create', CarePlanCreate::class)->name('care-plan.create');

    Route::whereNumber('person')->group(static function () {
        Route::get('{person}/diagnostic-report/create', DiagnosticReportCreate::class)
            ->can('create', DiagnosticReport::class)
            ->name('diagnostic-report.create');
        Route::get('{person}/diagnostic-report/{diagnosticReportId}', DiagnosticReportEdit::class)
            ->name('diagnostic-report.view')
            ->whereNumber('diagnosticReportId');
        Route::get('{person}/diagnostic-report/{diagnosticReportId}/edit', DiagnosticReportEdit::class)
            ->name('diagnostic-report.edit')
            ->whereNumber('diagnosticReportId');

        Route::get('{person}/procedure/create', ProcedureCreate::class)
            ->can('create', Procedure::class)
            ->name('procedure.create');
        Route::get('{person}/procedure/{procedureId}', ProcedureEdit::class)
            ->name('procedure.view')
            ->whereNumber('procedureId');
        Route::get('{person}/procedure/{procedureId}/edit', ProcedureEdit::class)
            ->name('procedure.edit')
            ->whereNumber('procedureId');
    });
});

Route::prefix('prepersons')
    ->name('prepersons.')
    ->whereNumber('preperson')
    ->group(static function () {
        Route::get('/', PrepersonIndex::class)->can('viewAny', Preperson::class)->name('index');
        Route::get('/{preperson}/edit', PrepersonEdit::class)->can('edit', 'preperson')->name('edit');

        Route::get('/{preperson}/patient-data', PrepersonData::class)->can('view', 'preperson')->name('patient-data');
        Route::get('/{preperson}/summary', PatientSummary::class)->can('view', 'preperson')->name('summary');
        Route::get('/{preperson}/episodes', PatientEpisodes::class)->can('view', 'preperson')->name('episodes');
        Route::get('/{preperson}/observations', PatientObservations::class)
            ->can('view', 'preperson')
            ->name('observations');
        Route::get('/{preperson}/immunizations', PatientImmunizations::class)
            ->can('view', 'preperson')
            ->name('immunizations');
        Route::get('/{preperson}/conditions', PatientConditions::class)->can('view', 'preperson')->name('conditions');
        Route::get('/{preperson}/diagnoses', PatientDiagnoses::class)->can('view', 'preperson')->name('diagnoses');
        Route::get('/{preperson}/diagnostic-reports', PatientDiagnosticReports::class)
            ->can('view', 'preperson')
            ->name('diagnostic-reports');
        Route::get('/{preperson}/clinical-impressions', PatientClinicalImpressions::class)
            ->can('view', 'preperson')
            ->name('clinical-impressions');
        Route::get('/{preperson}/encounters', PatientEncounters::class)->can('view', 'preperson')->name('encounters');

        Route::get('/{preperson}/encounter/create', EncounterCreate::class)
            ->can('view', 'preperson')
            ->name('encounter.create');
        Route::get('/{preperson}/encounter/{encounterId}', EncounterEdit::class)
            ->can('view', 'preperson')
            ->whereNumber('encounterId')
            ->name('encounter.edit');

        Route::get('/{preperson}/diagnostic-report/create', DiagnosticReportCreate::class)
            ->can('view', 'preperson')
            ->name('diagnostic-report.create');
        Route::get('/{preperson}/diagnostic-report/{diagnosticReportId}', DiagnosticReportEdit::class)
            ->can('view', 'preperson')
            ->whereNumber('diagnosticReportId')
            ->name('diagnostic-report.view');
        Route::get('/{preperson}/diagnostic-report/{diagnosticReportId}/edit', DiagnosticReportEdit::class)
            ->can('view', 'preperson')
            ->whereNumber('diagnosticReportId')
            ->name('diagnostic-report.edit');

        Route::get('/{preperson}/procedure/create', ProcedureCreate::class)
            ->can('view', 'preperson')
            ->name('procedure.create');
        Route::get('/{preperson}/procedure/{procedureId}', ProcedureEdit::class)
            ->can('view', 'preperson')
            ->whereNumber('procedureId')
            ->name('procedure.view');
        Route::get('/{preperson}/procedure/{procedureId}/edit', ProcedureEdit::class)
            ->can('view', 'preperson')
            ->whereNumber('procedureId')
            ->name('procedure.edit');
    });
