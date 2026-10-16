<?php

declare(strict_types=1);

use App\Livewire\CarePlan\CarePlanCreate;
use App\Livewire\ClinicalImpression\ClinicalImpressionIndex;
use App\Livewire\Composition\CompositionCreate;
use App\Livewire\Composition\CompositionTempDisabilityCreate;
use App\Livewire\Condition\ConditionIndex;
use App\Livewire\Condition\ConditionView;
use App\Livewire\Declaration\DeclarationCreate;
use App\Livewire\Declaration\DeclarationEdit;
use App\Livewire\Declaration\DeclarationView;
use App\Livewire\DetectedIssue\DetectedIssueIndex;
use App\Livewire\DetectedIssue\DetectedIssueView;
use App\Livewire\Device\DeviceIndex;
use App\Livewire\Device\DeviceView;
use App\Livewire\DeviceAssociation\DeviceAssociationIndex;
use App\Livewire\DeviceAssociation\DeviceAssociationView;
use App\Livewire\DeviceDispense\DeviceDispenses;
use App\Livewire\DeviceDispense\DeviceDispenseView;
use App\Livewire\DiagnosticReport\DiagnosticReportCreate;
use App\Livewire\DiagnosticReport\DiagnosticReportEdit;
use App\Livewire\DiagnosticReport\DiagnosticReportIndex;
use App\Livewire\DiagnosticReport\DiagnosticReportView;
use App\Livewire\Encounter\EncounterIndex;
use App\Livewire\Encounter\EncounterView;
use App\Livewire\EncounterPackage\EncounterPackageCreate;
use App\Livewire\EncounterPackage\EncounterPackageEdit;
use App\Livewire\EncounterPackage\EncounterPackageView;
use App\Livewire\Episode\EpisodeCreate;
use App\Livewire\Episode\EpisodeEdit;
use App\Livewire\Episode\EpisodeIndex;
use App\Livewire\Episode\EpisodeView;
use App\Livewire\Immunization\ImmunizationIndex;
use App\Livewire\Observation\ObservationIndex;
use App\Livewire\Observation\ObservationView;
use App\Livewire\Person\PatientVerifications;
use App\Livewire\Person\PersonCreate;
use App\Livewire\Person\PersonIndex;
use App\Livewire\Person\PersonRequestEdit;
use App\Livewire\Person\PersonUpdate;
use App\Livewire\Person\Records\PatientCarePlans;
use App\Livewire\Person\Records\PatientCompositions;
use App\Livewire\Person\Records\PatientData;
use App\Livewire\Person\Records\PatientMedicationRequests;
use App\Livewire\Person\Records\PatientMedicationRequestView;
use App\Livewire\Person\Records\PatientPrescriptionRequests;
use App\Livewire\Person\Records\PatientPrescriptionRequestView;
use App\Livewire\Person\Records\PatientReferrals;
use App\Livewire\Person\Records\PatientSpecimens;
use App\Livewire\Person\Records\PatientSpecimenView;
use App\Livewire\Person\Records\PatientSummary;
use App\Livewire\Person\Records\PatientVerification;
use App\Livewire\Preperson\PrepersonData;
use App\Livewire\Preperson\PrepersonEdit;
use App\Livewire\Preperson\PrepersonIndex;
use App\Livewire\Procedure\ProcedureCreate;
use App\Livewire\Procedure\ProcedureEdit;
use App\Livewire\Procedure\ProcedureIndex;
use App\Livewire\Procedure\ProcedureView;
use App\Livewire\Specimen\SpecimenCreate;
use App\Livewire\Specimen\SpecimenEdit;
use App\Models\CarePlan;
use App\Models\DeclarationRequest;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\DetectedIssue;
use App\Models\MedicalEvents\Sql\Device;
use App\Models\MedicalEvents\Sql\DeviceAssociation;
use App\Models\MedicalEvents\Sql\DeviceDispense;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\MedicalEvents\Sql\Specimen;
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

Route::prefix('persons')->whereNumber(['person', 'personRequest', 'personId'])
    ->group(static function () {
        Route::name('persons.')->group(static function () {
            Route::get('/', PersonIndex::class)->can('viewAny', Person::class)->name('index');
            Route::get('/create', PersonCreate::class)->can('create', PersonRequest::class)->name('create');
            Route::get('/edit/{personRequest}', PersonRequestEdit::class)
                ->can('create', PersonRequest::class)
                ->name('edit');
            Route::get('/update/{person}', PersonUpdate::class)->can('create', PersonRequest::class)->name('update');

            Route::get('/{person}/patient-data', PatientData::class)
                ->can('viewAny', Person::class)
                ->name('patient-data');

            Route::get('/{person}/verification', PatientVerification::class)
                ->can('view', PersonVerificationDetail::class)
                ->name('verification');

            Route::middleware('can:view,' . Person::class)->group(function () {
                Route::get('/{person}/summary', PatientSummary::class)->can('view', Person::class)->name('summary');
                Route::get('/{person}/specimens/{specimen}', PatientSpecimenView::class)
                    ->can('view', Specimen::class)
                    ->whereNumber('specimen')
                    ->name('specimens.view');
                Route::get('/{person}/specimens', PatientSpecimens::class)
                    ->can('view', Specimen::class)
                    ->name('specimens');
                Route::get('/{person}/specimens/create', SpecimenCreate::class)
                    ->can('create', Specimen::class)
                    ->name('specimens.create');
                Route::get('/{person}/specimens/{specimenId}/edit', SpecimenEdit::class)
                    ->can('create', Specimen::class)
                    ->whereNumber('specimenId')
                    ->name('specimens.edit');
                Route::get('/{person}/episodes', EpisodeIndex::class)->can('viewAny', Episode::class)->name('episodes');
                Route::get('/{person}/episodes/create', EpisodeCreate::class)
                    ->can('create', Episode::class)
                    ->name('episodes.create');
                Route::get('/{person}/episodes/{episode}', EpisodeView::class)
                    ->can('view', ['episode', 'person'])
                    ->whereNumber('episode')
                    ->name('episodes.view');
                Route::get('/{person}/episodes/{episode}/edit', EpisodeEdit::class)
                    ->can('view', ['episode', 'person'])
                    ->can('update', 'episode')
                    ->whereNumber('episode')
                    ->name('episodes.edit');
                Route::get('/{person}/care-plans', PatientCarePlans::class)->name('care-plans');
                Route::get('/{person}/medication-requests', PatientMedicationRequests::class)
                    ->name('medication-requests');
                Route::get('/{person}/medication-requests/{requestId}', PatientMedicationRequestView::class)
                    ->name('medication-requests.view');
                Route::get('/{person}/prescription-requests', PatientPrescriptionRequests::class)
                    ->name('prescription-requests');
                Route::get('/{person}/prescription-requests/{requestId}', PatientPrescriptionRequestView::class)
                    ->name('prescription-requests.view');
                Route::get('/{person}/referrals', PatientReferrals::class)->name('referrals');
                Route::get('/{person}/observations', ObservationIndex::class)->name('observations');
                Route::get('/{person}/observations/{observation}', ObservationView::class)
                    ->can('view', ['observation', 'person'])
                    ->whereNumber('observation')
                    ->name('observations.view');
                Route::get('/{person}/immunizations', ImmunizationIndex::class)->name('immunizations');
                Route::get('/{person}/conditions', ConditionIndex::class)->name('conditions');
                Route::get('/{person}/conditions/{condition}', ConditionView::class)
                    ->can('view', ['condition', 'person'])
                    ->whereNumber('condition')
                    ->name('conditions.view');
                Route::get('/{person}/diagnostic-reports', DiagnosticReportIndex::class)->name('diagnostic-reports');
                Route::get('/{person}/clinical-impressions', ClinicalImpressionIndex::class)
                    ->name('clinical-impressions');
                Route::get('/{person}/encounters', EncounterIndex::class)->name('encounters');
                Route::get('/{person}/encounters/{encounter}', EncounterView::class)
                    ->can('view', ['encounter', 'person'])
                    ->whereNumber('encounter')
                    ->name('encounters.view');
                Route::get('/{person}/procedures', ProcedureIndex::class)->name('procedures');
                Route::get('/{person}/devices', DeviceIndex::class)
                    ->can('view', Device::class)
                    ->name('devices');
                Route::get('/{person}/devices/{device:id}', DeviceView::class)
                    ->can('view', Device::class)
                    ->whereNumber('device')
                    ->name('devices.view');
                Route::get('/{person}/device-associations', DeviceAssociationIndex::class)
                    ->can('viewAny', DeviceAssociation::class)
                    ->name('device-associations');
                Route::get('/{person}/device-associations/{deviceAssociation:id}', DeviceAssociationView::class)
                    ->can('view', DeviceAssociation::class)
                    ->whereNumber('deviceAssociation')
                    ->name('device-associations.view');
                Route::get('/{person}/device-dispenses', DeviceDispenses::class)
                    ->can('viewAny', DeviceDispense::class)
                    ->name('device-dispenses');
                Route::get('/{person}/device-dispenses/{deviceDispense:id}', DeviceDispenseView::class)
                    ->can('view', DeviceDispense::class)
                    ->whereNumber('deviceDispense')
                    ->name('device-dispenses.view');
                Route::get('/{person}/detected-issues', DetectedIssueIndex::class)
                    ->can('viewAny', DetectedIssue::class)
                    ->name('device-issues');
                Route::get('/{person}/detected-issues/{detectedIssue:id}', DetectedIssueView::class)
                    ->can('view', DetectedIssue::class)
                    ->whereNumber('detectedIssue')
                    ->name('device-issues.view');
                Route::get('/{person}/compositions', PatientCompositions::class)
                    ->can('viewAny', Composition::class)
                    ->name('compositions');
                Route::get('/{person}/compositions/temp-disability/create', CompositionTempDisabilityCreate::class)
                    ->can('createTempDisability', Composition::class)
                    ->name('compositions.temp-disability.create');
                Route::get('/{person}/compositions/newborn/create', CompositionCreate::class)
                    ->can('createNewborn', Composition::class)
                    ->name('compositions.newborn.create');
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

        Route::name('encounter.')->group(function () {
            Route::get('/{person}/encounter/create', EncounterPackageCreate::class)
                ->can('create', Encounter::class)
                ->name('create');
            Route::get('/{person}/encounter/{encounter}', EncounterPackageView::class)
                ->can('view', ['encounter', 'person'])
                ->whereNumber('encounter')
                ->name('view');
            Route::get('/{person}/encounter/{encounter}/edit', EncounterPackageEdit::class)
                ->can('create', Encounter::class)
                ->can('view', ['encounter', 'person'])
                ->whereNumber('encounter')
                ->name('edit');
        });

        Route::get('/{personId}/care-plan/create', CarePlanCreate::class)
            ->can('create', CarePlan::class)
            ->name('care-plan.create');

        Route::whereNumber('person')->group(static function () {
            Route::get('{person}/diagnostic-report/create', DiagnosticReportCreate::class)
                ->can('create', DiagnosticReport::class)
                ->name('diagnostic-report.create');
            Route::get('{person}/diagnostic-report/{diagnosticReport}', DiagnosticReportView::class)
                ->can('view', ['diagnosticReport', 'person'])
                ->name('diagnostic-report.view')
                ->whereNumber('diagnosticReport');
            Route::get('{person}/diagnostic-report/{diagnosticReport}/edit', DiagnosticReportEdit::class)
                ->can('view', ['diagnosticReport', 'person'])
                ->name('diagnostic-report.edit')
                ->whereNumber('diagnosticReport');

            Route::get('{person}/procedure/create', ProcedureCreate::class)
                ->can('create', Procedure::class)
                ->name('procedure.create');
            Route::get('{person}/procedure/{procedureId}', ProcedureView::class)
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
        Route::get('/{preperson}/specimens', PatientSpecimens::class)
            ->can('view', 'preperson')
            ->can('view', Specimen::class)
            ->name('specimens');
        Route::get('/{preperson}/specimens/create', SpecimenCreate::class)
            ->can('view', 'preperson')
            ->can('create', Specimen::class)
            ->name('specimens.create');
        Route::get('/{preperson}/specimens/{specimenId}/edit', SpecimenEdit::class)
            ->can('view', 'preperson')
            ->can('create', Specimen::class)
            ->whereNumber('specimenId')
            ->name('specimens.edit');
        Route::get('/{preperson}/specimens/{specimen}', PatientSpecimenView::class)
            ->can('view', 'preperson')
            ->can('view', Specimen::class)
            ->whereNumber('specimen')
            ->name('specimens.view');
        Route::get('/{preperson}/episodes', EpisodeIndex::class)
            ->can('view', 'preperson')
            ->can('viewAny', Episode::class)
            ->name('episodes');
        Route::get('/{preperson}/episodes/create', EpisodeCreate::class)
            ->can('view', 'preperson')
            ->can('create', Episode::class)
            ->name('episodes.create');
        Route::get('/{preperson}/episodes/{episode}', EpisodeView::class)
            ->can('view', 'preperson')
            ->can('view', ['episode', 'preperson'])
            ->whereNumber('episode')
            ->name('episodes.view');
        Route::get('/{preperson}/episodes/{episode}/edit', EpisodeEdit::class)
            ->can('view', 'preperson')
            ->can('view', ['episode', 'preperson'])
            ->can('update', 'episode')
            ->whereNumber('episode')
            ->name('episodes.edit');
        Route::get('/{preperson}/medication-requests', PatientMedicationRequests::class)
            ->can('view', 'preperson')
            ->name('medication-requests');
        Route::get('/{preperson}/medication-requests/{requestId}', PatientMedicationRequestView::class)
            ->can('view', 'preperson')
            ->name('medication-requests.view');
        Route::get('/{preperson}/prescription-requests', PatientPrescriptionRequests::class)
            ->can('view', 'preperson')
            ->name('prescription-requests');
        Route::get('/{preperson}/prescription-requests/{requestId}', PatientPrescriptionRequestView::class)
            ->can('view', 'preperson')
            ->name('prescription-requests.view');
        Route::get('/{preperson}/observations', ObservationIndex::class)
            ->can('view', 'preperson')
            ->name('observations');
        Route::get('/{preperson}/observations/{observation}', ObservationView::class)
            ->can('view', 'preperson')
            ->can('view', ['observation', 'preperson'])
            ->whereNumber('observation')
            ->name('observations.view');
        Route::get('/{preperson}/immunizations', ImmunizationIndex::class)
            ->can('view', 'preperson')
            ->name('immunizations');
        Route::get('/{preperson}/conditions', ConditionIndex::class)->can('view', 'preperson')->name('conditions');
        Route::get('/{preperson}/conditions/{condition}', ConditionView::class)
            ->can('view', 'preperson')
            ->can('view', ['condition', 'preperson'])
            ->whereNumber('condition')
            ->name('conditions.view');
        Route::get('/{preperson}/diagnostic-reports', DiagnosticReportIndex::class)
            ->can('view', 'preperson')
            ->name('diagnostic-reports');
        Route::get('/{preperson}/clinical-impressions', ClinicalImpressionIndex::class)
            ->can('view', 'preperson')
            ->name('clinical-impressions');
        Route::get('/{preperson}/encounters', EncounterIndex::class)->can('view', 'preperson')->name('encounters');
        Route::get('/{preperson}/encounters/{encounter}', EncounterView::class)
            ->can('view', 'preperson')
            ->can('view', ['encounter', 'preperson'])
            ->whereNumber('encounter')
            ->name('encounters.view');
        Route::get('/{preperson}/procedures', ProcedureIndex::class)->can('view', 'preperson')->name('procedures');
        Route::get('/{preperson}/devices', DeviceIndex::class)
            ->can('view', 'preperson')
            ->can('view', Device::class)
            ->name('devices');
        Route::get('/{preperson}/devices/{device:id}', DeviceView::class)
            ->can('view', 'preperson')
            ->can('view', Device::class)
            ->whereNumber('device')
            ->name('devices.view');
        Route::get('/{preperson}/device-associations', DeviceAssociationIndex::class)
            ->can('view', 'preperson')
            ->can('viewAny', DeviceAssociation::class)
            ->name('device-associations');
        Route::get('/{preperson}/device-associations/{deviceAssociation:id}', DeviceAssociationView::class)
            ->can('view', 'preperson')
            ->can('view', DeviceAssociation::class)
            ->whereNumber('deviceAssociation')
            ->name('device-associations.view');
        Route::get('/{preperson}/device-dispenses', DeviceDispenses::class)
            ->can('view', 'preperson')
            ->can('viewAny', DeviceDispense::class)
            ->name('device-dispenses');
        Route::get('/{preperson}/device-dispenses/{deviceDispense:id}', DeviceDispenseView::class)
            ->can('view', 'preperson')
            ->can('view', DeviceDispense::class)
            ->whereNumber('deviceDispense')
            ->name('device-dispenses.view');
        Route::get('/{preperson}/detected-issues', DetectedIssueIndex::class)
            ->can('view', 'preperson')
            ->can('viewAny', DetectedIssue::class)
            ->name('device-issues');
        Route::get('/{preperson}/detected-issues/{detectedIssue:id}', DetectedIssueView::class)
            ->can('view', 'preperson')
            ->can('view', DetectedIssue::class)
            ->whereNumber('detectedIssue')
            ->name('device-issues.view');
        // A birth conclusion is filed against the newborn, who exists only as a preperson,
        // and a disability conclusion may also be issued for an unidentified patient.
        Route::get('/{preperson}/compositions', PatientCompositions::class)
            ->can('view', 'preperson')
            ->can('viewAny', Composition::class)
            ->name('compositions');
        Route::get('/{preperson}/compositions/temp-disability/create', CompositionTempDisabilityCreate::class)
            ->can('view', 'preperson')
            ->can('createTempDisability', Composition::class)
            ->name('compositions.temp-disability.create');
        Route::get('/{preperson}/compositions/newborn/create', CompositionCreate::class)
            ->can('view', 'preperson')
            ->can('createNewborn', Composition::class)
            ->name('compositions.newborn.create');

        Route::get('/{preperson}/encounter/create', EncounterPackageCreate::class)
            ->can('view', 'preperson')
            ->can('create', Encounter::class)
            ->name('encounter.create');
        Route::get('/{preperson}/encounter/{encounter}', EncounterPackageView::class)
            ->can('view', 'preperson')
            ->can('view', ['encounter', 'preperson'])
            ->whereNumber('encounter')
            ->name('encounter.view');
        Route::get('/{preperson}/encounter/{encounter}/edit', EncounterPackageEdit::class)
            ->can('view', 'preperson')
            ->can('create', Encounter::class)
            ->can('view', ['encounter', 'preperson'])
            ->whereNumber('encounter')
            ->name('encounter.edit');

        Route::get('/{preperson}/diagnostic-report/create', DiagnosticReportCreate::class)
            ->can('view', 'preperson')
            ->can('create', DiagnosticReport::class)
            ->name('diagnostic-report.create');
        Route::get('/{preperson}/diagnostic-report/{diagnosticReport}', DiagnosticReportView::class)
            ->can('view', 'preperson')
            ->can('view', ['diagnosticReport', 'preperson'])
            ->whereNumber('diagnosticReport')
            ->name('diagnostic-report.view');
        Route::get('/{preperson}/diagnostic-report/{diagnosticReport}/edit', DiagnosticReportEdit::class)
            ->can('view', 'preperson')
            ->can('view', ['diagnosticReport', 'preperson'])
            ->whereNumber('diagnosticReport')
            ->name('diagnostic-report.edit');

        Route::get('/{preperson}/procedure/create', ProcedureCreate::class)
            ->can('view', 'preperson')
            ->can('create', Procedure::class)
            ->name('procedure.create');
        Route::get('/{preperson}/procedure/{procedureId}', ProcedureView::class)
            ->can('view', 'preperson')
            ->whereNumber('procedureId')
            ->name('procedure.view');
        Route::get('/{preperson}/procedure/{procedureId}/edit', ProcedureEdit::class)
            ->can('view', 'preperson')
            ->whereNumber('procedureId')
            ->name('procedure.edit');
    });
