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
use App\Livewire\Episode\EpisodeCreate;
use App\Livewire\Episode\EpisodeEdit;
use App\Livewire\Episode\EpisodeView;
use App\Livewire\Episode\EpisodeIndex;
use App\Livewire\Person\PersonCreate;
use App\Livewire\Person\PersonIndex;
use App\Livewire\Person\PersonRequestEdit;
use App\Livewire\Person\PersonUpdate;
use App\Livewire\Person\Records\PatientCarePlans;
use App\Livewire\Person\Records\PatientClinicalImpressions;
use App\Livewire\Person\Records\PatientConditions;
use App\Livewire\Person\Records\PatientData;
use App\Livewire\Person\Records\PatientDiagnosticReports;
use App\Livewire\Person\Records\PatientEncounters;
use App\Livewire\Person\Records\PatientImmunizations;
use App\Livewire\Person\Records\PatientMedicationRequests;
use App\Livewire\Person\Records\PatientReferrals;
use App\Livewire\Person\Records\PatientObservations;
use App\Livewire\Person\Records\PatientProcedures;
use App\Livewire\Person\Records\PatientDevices;
use App\Livewire\Person\Records\PatientDeviceAssociations;
use App\Livewire\Person\Records\DeviceDispenses;
use App\Livewire\Person\Records\PatientDeviceIssues;
use App\Livewire\Person\Records\PatientSummary;
use App\Livewire\Person\Records\PatientVerification;
use App\Livewire\Person\PatientVerifications;
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
use App\Models\Relations\PersonVerificationDetail;
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

Route::prefix('persons')->whereNumber(['person', 'personRequest', 'personId', 'encounterId'])->group(static function () {
    Route::name('persons.')->group(static function () {
        Route::get('/', PersonIndex::class)->can('viewAny', Person::class)->name('index');
        Route::get('/create', PersonCreate::class)->can('create', PersonRequest::class)->name('create');
        Route::get('/edit/{personRequest}', PersonRequestEdit::class)
            ->can('create', PersonRequest::class)
            ->name('edit');
        Route::get('/update/{person}', PersonUpdate::class)->can('create', PersonRequest::class)->name('update');

        Route::middleware('can:view,' . Person::class)->group(function () {
            Route::get('/{person}/patient-data', PatientData::class)->name('patient-data');
            Route::get('/{person}/verification', PatientVerification::class)
                ->can('view', PersonVerificationDetail::class)
                ->name('verification');
            Route::get('/{person}/summary', PatientSummary::class)->can('view', Person::class)->name('summary');
            Route::get('/{person}/episodes', EpisodeIndex::class)->can('view', Episode::class)->name('episodes');
            Route::get('/{person}/episodes/create', EpisodeCreate::class)
                ->can('create', Episode::class)
                ->name('episodes.create');
            Route::get('/{person}/episodes/{episode:id}', EpisodeView::class)
                ->can('view', Episode::class)
                ->whereNumber('episode')
                ->name('episodes.view');
            Route::get('/{person}/episodes/{episode:id}/edit', EpisodeEdit::class)
                ->can('update', 'episode')
                ->whereNumber('episode')
                ->name('episodes.edit');
            Route::get('/{person}/care-plans', PatientCarePlans::class)->name('care-plans');
            Route::get('/{person}/medication-requests', PatientMedicationRequests::class)->name('medication-requests');
            Route::get('/{person}/referrals', PatientReferrals::class)->name('referrals');
            Route::get('/{person}/observations', PatientObservations::class)->name('observations');
            Route::get('/{person}/immunizations', PatientImmunizations::class)->name('immunizations');
            Route::get('/{person}/conditions', PatientConditions::class)->name('conditions');
            Route::get('/{person}/diagnostic-reports', PatientDiagnosticReports::class)->name('diagnostic-reports');
            Route::get('/{person}/clinical-impressions', PatientClinicalImpressions::class)->name('clinical-impressions');
            Route::get('/{person}/encounters', PatientEncounters::class)->name('encounters');
            Route::get('/{person}/procedures', PatientProcedures::class)->name('procedures');
            Route::get('/{person}/devices', PatientDevices::class)->name('devices');
            Route::get('/{person}/devices/{deviceId}', \App\Livewire\Person\Records\PatientDeviceView::class)->name('devices.view');
            Route::get('/{person}/device-associations', PatientDeviceAssociations::class)->name('device-associations');
            Route::get('/{person}/device-dispenses', DeviceDispenses::class)->name('device-dispenses');
            Route::get('/{person}/device-issues', PatientDeviceIssues::class)->name('device-issues');
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

    Route::get('/{personId}/care-plan/create', CarePlanCreate::class)
        ->can('create', \App\Models\CarePlan::class)
        ->name('care-plan.create');

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

    Route::get('/verifications', PatientVerifications::class)
        ->can('viewAny', PersonVerificationDetail::class)
        ->name('persons.verifications');
});

Route::prefix('prepersons')
    ->name('prepersons.')
    ->whereNumber('preperson')
    ->group(static function () {
        Route::get('/', PrepersonIndex::class)->can('viewAny', Preperson::class)->name('index');
        Route::get('/{preperson}/edit', PrepersonEdit::class)->can('edit', 'preperson')->name('edit');

        Route::get('/{preperson}/patient-data', PrepersonData::class)->can('view', 'preperson')->name('patient-data');
        Route::get('/{preperson}/summary', PatientSummary::class)
            ->can('view', 'preperson')
            ->can('viewSummary', 'preperson')
            ->name('summary');
        Route::get('/{preperson}/episodes', EpisodeIndex::class)->can('view', 'preperson')->name('episodes');
        Route::get('/{preperson}/episodes/create', EpisodeCreate::class)
            ->can('view', 'preperson')
            ->can('create', Episode::class)
            ->name('episodes.create');
        Route::get('/{preperson}/episodes/{episode:id}', EpisodeView::class)
            ->can('view', 'preperson')
            ->whereNumber('episode')
            ->name('episodes.view');
        Route::get('/{preperson}/episodes/{episode:id}/edit', EpisodeEdit::class)
            ->can('view', 'preperson')
            ->can('update', 'episode')
            ->whereNumber('episode')
            ->name('episodes.edit');
        Route::get('/{preperson}/observations', PatientObservations::class)
            ->can('view', 'preperson')
            ->name('observations');
        Route::get('/{preperson}/immunizations', PatientImmunizations::class)
            ->can('view', 'preperson')
            ->name('immunizations');
        Route::get('/{preperson}/conditions', PatientConditions::class)->can('view', 'preperson')->name('conditions');
        Route::get('/{preperson}/diagnostic-reports', PatientDiagnosticReports::class)
            ->can('view', 'preperson')
            ->name('diagnostic-reports');
        Route::get('/{preperson}/clinical-impressions', PatientClinicalImpressions::class)
            ->can('view', 'preperson')
            ->name('clinical-impressions');
        Route::get('/{preperson}/encounters', PatientEncounters::class)->can('view', 'preperson')->name('encounters');
        Route::get('/{preperson}/procedures', PatientProcedures::class)->can('view', 'preperson')->name('procedures');
        Route::get('/{preperson}/devices', PatientDevices::class)->can('view', 'preperson')->name('devices');
        Route::get('/{preperson}/device-associations', PatientDeviceAssociations::class)->can('view', 'preperson')->name('device-associations');
        Route::get('/{preperson}/device-dispenses', DeviceDispenses::class)->can('view', 'preperson')->name('device-dispenses');
        Route::get('/{preperson}/device-issues', PatientDeviceIssues::class)->can('view', 'preperson')->name('device-issues');

        Route::get('/{preperson}/encounter/create', EncounterCreate::class)
            ->can('view', 'preperson')
            ->name('encounter.create');
        Route::get('/{preperson}/encounter/{encounterId}', EncounterEdit::class)
            ->can('view', 'preperson')
            ->whereNumber('encounterId')
            ->name('encounter.edit');

        Route::get('/{preperson}/diagnostic-report/create', DiagnosticReportCreate::class)
            ->can('view', 'preperson')
            ->can('create', DiagnosticReport::class)
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
            ->can('create', Procedure::class)
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
