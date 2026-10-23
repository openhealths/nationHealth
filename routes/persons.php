<?php

declare(strict_types=1);

use App\Livewire\CarePlan\CarePlanCreate;
use App\Livewire\ClinicalImpression\ClinicalImpressionIndex;
use App\Livewire\ClinicalImpression\ClinicalImpressionView;
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
use App\Livewire\DeviceRequest\DeviceRequestForm;
use App\Livewire\DeviceRequest\DeviceRequestIndex;
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
use App\Livewire\Immunization\ImmunizationView;
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
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DetectedIssue;
use App\Models\MedicalEvents\Sql\Device;
use App\Models\MedicalEvents\Sql\DeviceAssociation;
use App\Models\MedicalEvents\Sql\DeviceDispense;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Immunization;
use App\Models\MedicalEvents\Sql\Observation;
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

Route::prefix('persons')->whereNumber('person')
    ->group(static function () {
        Route::name('persons.')->group(static function () {
            Route::get('/', PersonIndex::class)->can('viewAny', Person::class)->name('index');
            Route::get('/create', PersonCreate::class)->can('create', PersonRequest::class)->name('create');
            Route::get('/edit/{personRequest}', PersonRequestEdit::class)
                ->can('create', PersonRequest::class)
                ->whereNumber('personRequest')
                ->name('edit');
            Route::get('/update/{person}', PersonUpdate::class)->can('create', PersonRequest::class)->name('update');

            Route::get('/{person}/patient-data', PatientData::class)
                ->can('viewAny', Person::class)
                ->name('patient-data');

            Route::get('/{person}/verification', PatientVerification::class)
                ->can('view', PersonVerificationDetail::class)
                ->name('verification');

            Route::prefix('{person}')->middleware('can:view,' . Person::class)->group(function () {
                Route::get('/summary', PatientSummary::class)->can('view', Person::class)->name('summary');
                Route::get('/specimens/{specimen}', PatientSpecimenView::class)
                    ->can('view', Specimen::class)
                    ->whereNumber('specimen')
                    ->name('specimens.view');
                Route::get('/specimens', PatientSpecimens::class)
                    ->can('view', Specimen::class)
                    ->name('specimens');
                Route::get('/specimens/create', SpecimenCreate::class)
                    ->can('create', Specimen::class)
                    ->name('specimens.create');
                Route::get('/specimens/{specimenId}/edit', SpecimenEdit::class)
                    ->can('create', Specimen::class)
                    ->whereNumber('specimenId')
                    ->name('specimens.edit');
                Route::get('/episodes', EpisodeIndex::class)->can('viewAny', Episode::class)->name('episodes');
                Route::get('/episodes/create', EpisodeCreate::class)
                    ->can('create', Episode::class)
                    ->name('episodes.create');
                Route::get('/episodes/{episode}', EpisodeView::class)
                    ->can('view', ['episode', 'person'])
                    ->whereNumber('episode')
                    ->name('episodes.view');
                Route::get('/episodes/{episode}/edit', EpisodeEdit::class)
                    ->can('view', ['episode', 'person'])
                    ->can('update', 'episode')
                    ->whereNumber('episode')
                    ->name('episodes.edit');
                Route::get('/care-plans', PatientCarePlans::class)->name('care-plans');
                Route::get('/medication-requests', PatientMedicationRequests::class)
                    ->name('medication-requests');
                Route::get('/medication-requests/{requestId}', PatientMedicationRequestView::class)
                    ->name('medication-requests.view');
                Route::get('/prescription-requests', PatientPrescriptionRequests::class)
                    ->name('prescription-requests');
                Route::get('/prescription-requests/{requestId}', PatientPrescriptionRequestView::class)
                    ->name('prescription-requests.view');
                Route::get('/referrals', PatientReferrals::class)->name('referrals');
                Route::get('/device-requests', DeviceRequestIndex::class)
                    ->can('viewAny', DeviceRequestRequest::class)->name('device-requests');
                Route::get('/device-requests/create', DeviceRequestForm::class)
                    ->can('create', DeviceRequestRequest::class)->name('device-requests.create');
                Route::get('/observations', ObservationIndex::class)
                    ->can('viewAny', Observation::class)
                    ->name('observations');
                Route::get('/observations/{observation}', ObservationView::class)
                    ->can('view', ['observation', 'person'])
                    ->whereNumber('observation')
                    ->name('observations.view');
                Route::get('/immunizations', ImmunizationIndex::class)
                    ->can('viewAny', Immunization::class)
                    ->name('immunizations');
                Route::get('/immunizations/{immunization}', ImmunizationView::class)
                    ->can('view', ['immunization', 'person'])
                    ->whereNumber('immunization')
                    ->name('immunizations.view');
                Route::get('/conditions', ConditionIndex::class)
                    ->can('viewAny', Condition::class)
                    ->name('conditions');
                Route::get('/conditions/{condition}', ConditionView::class)
                    ->can('view', ['condition', 'person'])
                    ->whereNumber('condition')
                    ->name('conditions.view');
                Route::get('/diagnostic-reports', DiagnosticReportIndex::class)
                    ->can('viewAny', DiagnosticReport::class)
                    ->name('diagnostic-reports');
                Route::get('/clinical-impressions', ClinicalImpressionIndex::class)
                    ->can('viewAny', ClinicalImpression::class)
                    ->name('clinical-impressions');
                Route::get('/clinical-impressions/{clinicalImpression}', ClinicalImpressionView::class)
                    ->can('view', ['clinicalImpression', 'person'])
                    ->whereNumber('clinicalImpression')
                    ->name('clinical-impressions.view');
                Route::get('/encounters', EncounterIndex::class)
                    ->can('viewAny', Encounter::class)
                    ->name('encounters');
                Route::get('/encounters/{encounter}', EncounterView::class)
                    ->can('view', ['encounter', 'person'])
                    ->whereNumber('encounter')
                    ->name('encounters.view');
                Route::get('/procedures', ProcedureIndex::class)
                    ->can('viewAny', Procedure::class)
                    ->name('procedures');
                Route::get('/devices', DeviceIndex::class)
                    ->can('viewAny', Device::class)
                    ->name('devices');
                Route::get('/devices/{device}', DeviceView::class)
                    ->can('view', ['device', 'person'])
                    ->whereNumber('device')
                    ->name('devices.view');
                Route::get('/device-associations', DeviceAssociationIndex::class)
                    ->can('viewAny', DeviceAssociation::class)
                    ->name('device-associations');
                Route::get('/device-associations/{deviceAssociation:id}', DeviceAssociationView::class)
                    ->can('view', DeviceAssociation::class)
                    ->whereNumber('deviceAssociation')
                    ->name('device-associations.view');
                Route::get('/device-dispenses', DeviceDispenses::class)
                    ->can('viewAny', DeviceDispense::class)
                    ->name('device-dispenses');
                Route::get('/device-dispenses/{deviceDispense}', DeviceDispenseView::class)
                    ->can('view', ['deviceDispense', 'person'])
                    ->whereNumber('deviceDispense')
                    ->name('device-dispenses.view');
                Route::get('/detected-issues', DetectedIssueIndex::class)
                    ->can('viewAny', DetectedIssue::class)
                    ->name('device-issues');
                Route::get('/detected-issues/{detectedIssue:id}', DetectedIssueView::class)
                    ->can('view', DetectedIssue::class)
                    ->whereNumber('detectedIssue')
                    ->name('device-issues.view');
                Route::get('/compositions', PatientCompositions::class)
                    ->can('viewAny', Composition::class)
                    ->name('compositions');
                Route::get('/compositions/temp-disability/create', CompositionTempDisabilityCreate::class)
                    ->can('createTempDisability', Composition::class)
                    ->name('compositions.temp-disability.create');
                Route::get('/compositions/newborn/create', CompositionCreate::class)
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
                ->can('create', DeclarationRequest::class);
            Route::get('/{person}/declaration/{declarationRequest}', DeclarationEdit::class)
                ->name('edit')
                ->can('update', 'declarationRequest')
                ->whereNumber('declarationRequest');
        });

        Route::prefix('{person}')->name('encounter.')->group(function () {
            Route::get('/encounter/create', EncounterPackageCreate::class)
                ->can('create', Encounter::class)
                ->name('create');
            Route::get('/encounter/{encounter}', EncounterPackageView::class)
                ->can('view', ['encounter', 'person'])
                ->whereNumber('encounter')
                ->name('view');
            Route::get('/encounter/{encounter}/edit', EncounterPackageEdit::class)
                ->can('create', Encounter::class)
                ->can('view', ['encounter', 'person'])
                ->whereNumber('encounter')
                ->name('edit');
        });

        Route::get('/{personId}/care-plan/create', CarePlanCreate::class)
            ->can('create', CarePlan::class)
            ->whereNumber('personId')
            ->name('care-plan.create');

        Route::prefix('{person}')->group(static function () {
            Route::get('/diagnostic-report/create', DiagnosticReportCreate::class)
                ->can('create', DiagnosticReport::class)
                ->name('diagnostic-report.create');
            Route::get('/diagnostic-report/{diagnosticReport}', DiagnosticReportView::class)
                ->can('view', ['diagnosticReport', 'person'])
                ->name('diagnostic-report.view')
                ->whereNumber('diagnosticReport');
            Route::get('/diagnostic-report/{diagnosticReport}/edit', DiagnosticReportEdit::class)
                ->can('view', ['diagnosticReport', 'person'])
                ->name('diagnostic-report.edit')
                ->whereNumber('diagnosticReport');

            Route::get('/procedure/create', ProcedureCreate::class)
                ->can('create', Procedure::class)
                ->name('procedure.create');
            Route::get('/procedure/{procedure}', ProcedureView::class)
                ->can('view', ['procedure', 'person'])
                ->name('procedure.view')
                ->whereNumber('procedure');
            Route::get('/procedure/{procedure}/edit', ProcedureEdit::class)
                ->can('view', ['procedure', 'person'])
                ->name('procedure.edit')
                ->whereNumber('procedure');
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

        Route::prefix('{preperson}')->middleware('can:view,preperson')->group(static function () {
            Route::get('/patient-data', PrepersonData::class)->name('patient-data');
            Route::get('/summary', PatientSummary::class)
                ->can('viewSummary', 'preperson')
                ->name('summary');
            Route::get('/specimens', PatientSpecimens::class)
                ->can('view', Specimen::class)
                ->name('specimens');
            Route::get('/specimens/create', SpecimenCreate::class)
                ->can('create', Specimen::class)
                ->name('specimens.create');
            Route::get('/specimens/{specimenId}/edit', SpecimenEdit::class)
                ->can('create', Specimen::class)
                ->whereNumber('specimenId')
                ->name('specimens.edit');
            Route::get('/specimens/{specimen}', PatientSpecimenView::class)
                ->can('view', Specimen::class)
                ->whereNumber('specimen')
                ->name('specimens.view');
            Route::get('/episodes', EpisodeIndex::class)
                ->can('viewAny', Episode::class)
                ->name('episodes');
            Route::get('/episodes/create', EpisodeCreate::class)
                ->can('create', Episode::class)
                ->name('episodes.create');
            Route::get('/episodes/{episode}', EpisodeView::class)
                ->can('view', ['episode', 'preperson'])
                ->whereNumber('episode')
                ->name('episodes.view');
            Route::get('/episodes/{episode}/edit', EpisodeEdit::class)
                ->can('view', ['episode', 'preperson'])
                ->can('update', 'episode')
                ->whereNumber('episode')
                ->name('episodes.edit');
            Route::get('/medication-requests', PatientMedicationRequests::class)
                ->name('medication-requests');
            Route::get('/medication-requests/{requestId}', PatientMedicationRequestView::class)
                ->name('medication-requests.view');
            Route::get('/prescription-requests', PatientPrescriptionRequests::class)
                ->name('prescription-requests');
            Route::get('/prescription-requests/{requestId}', PatientPrescriptionRequestView::class)
                ->name('prescription-requests.view');
            Route::get('/observations', ObservationIndex::class)
                ->can('viewAny', Observation::class)
                ->name('observations');
            Route::get('/observations/{observation}', ObservationView::class)
                ->can('view', ['observation', 'preperson'])
                ->whereNumber('observation')
                ->name('observations.view');
            Route::get('/immunizations', ImmunizationIndex::class)
                ->can('viewAny', Immunization::class)
                ->name('immunizations');
            Route::get('/immunizations/{immunization}', ImmunizationView::class)
                ->can('view', ['immunization', 'preperson'])
                ->whereNumber('immunization')
                ->name('immunizations.view');
            Route::get('/conditions', ConditionIndex::class)
                ->can('viewAny', Condition::class)
                ->name('conditions');
            Route::get('/conditions/{condition}', ConditionView::class)
                ->can('view', ['condition', 'preperson'])
                ->whereNumber('condition')
                ->name('conditions.view');
            Route::get('/diagnostic-reports', DiagnosticReportIndex::class)
                ->can('viewAny', DiagnosticReport::class)
                ->name('diagnostic-reports');
            Route::get('/clinical-impressions', ClinicalImpressionIndex::class)
                ->can('viewAny', ClinicalImpression::class)
                ->name('clinical-impressions');
            Route::get('/clinical-impressions/{clinicalImpression}', ClinicalImpressionView::class)
                ->can('view', ['clinicalImpression', 'preperson'])
                ->whereNumber('clinicalImpression')
                ->name('clinical-impressions.view');
            Route::get('/encounters', EncounterIndex::class)
                ->can('viewAny', Encounter::class)
                ->name('encounters');
            Route::get('/encounters/{encounter}', EncounterView::class)
                ->can('view', ['encounter', 'preperson'])
                ->whereNumber('encounter')
                ->name('encounters.view');
            Route::get('/procedures', ProcedureIndex::class)
                ->can('viewAny', Procedure::class)
                ->name('procedures');
            Route::get('/devices', DeviceIndex::class)
                ->can('viewAny', Device::class)
                ->name('devices');
            Route::get('/devices/{device}', DeviceView::class)
                ->can('view', ['device', 'preperson'])
                ->whereNumber('device')
                ->name('devices.view');
            Route::get('/device-associations', DeviceAssociationIndex::class)
                ->can('viewAny', DeviceAssociation::class)
                ->name('device-associations');
            Route::get('/device-associations/{deviceAssociation:id}', DeviceAssociationView::class)
                ->can('view', DeviceAssociation::class)
                ->whereNumber('deviceAssociation')
                ->name('device-associations.view');
            Route::get('/device-dispenses', DeviceDispenses::class)
                ->can('viewAny', DeviceDispense::class)
                ->name('device-dispenses');
            Route::get('/device-dispenses/{deviceDispense}', DeviceDispenseView::class)
                ->can('view', ['deviceDispense', 'preperson'])
                ->whereNumber('deviceDispense')
                ->name('device-dispenses.view');
            Route::get('/detected-issues', DetectedIssueIndex::class)
                ->can('viewAny', DetectedIssue::class)
                ->name('device-issues');
            Route::get('/detected-issues/{detectedIssue:id}', DetectedIssueView::class)
                ->can('view', DetectedIssue::class)
                ->whereNumber('detectedIssue')
                ->name('device-issues.view');
            // A birth conclusion is filed against the newborn, who exists only as a preperson,
            // and a disability conclusion may also be issued for an unidentified patient.
            Route::get('/compositions', PatientCompositions::class)
                ->can('viewAny', Composition::class)
                ->name('compositions');
            Route::get('/compositions/temp-disability/create', CompositionTempDisabilityCreate::class)
                ->can('createTempDisability', Composition::class)
                ->name('compositions.temp-disability.create');
            Route::get('/compositions/newborn/create', CompositionCreate::class)
                ->can('createNewborn', Composition::class)
                ->name('compositions.newborn.create');

            Route::get('/encounter/create', EncounterPackageCreate::class)
                ->can('create', Encounter::class)
                ->name('encounter.create');
            Route::get('/encounter/{encounter}', EncounterPackageView::class)
                ->can('view', ['encounter', 'preperson'])
                ->whereNumber('encounter')
                ->name('encounter.view');
            Route::get('/encounter/{encounter}/edit', EncounterPackageEdit::class)
                ->can('create', Encounter::class)
                ->can('view', ['encounter', 'preperson'])
                ->whereNumber('encounter')
                ->name('encounter.edit');

            Route::get('/diagnostic-report/create', DiagnosticReportCreate::class)
                ->can('create', DiagnosticReport::class)
                ->name('diagnostic-report.create');
            Route::get('/diagnostic-report/{diagnosticReport}', DiagnosticReportView::class)
                ->can('view', ['diagnosticReport', 'preperson'])
                ->whereNumber('diagnosticReport')
                ->name('diagnostic-report.view');
            Route::get('/diagnostic-report/{diagnosticReport}/edit', DiagnosticReportEdit::class)
                ->can('view', ['diagnosticReport', 'preperson'])
                ->whereNumber('diagnosticReport')
                ->name('diagnostic-report.edit');

            Route::get('/procedure/create', ProcedureCreate::class)
                ->can('create', Procedure::class)
                ->name('procedure.create');
            Route::get('/procedure/{procedure}', ProcedureView::class)
                ->can('view', ['procedure', 'preperson'])
                ->whereNumber('procedure')
                ->name('procedure.view');
            Route::get('/procedure/{procedure}/edit', ProcedureEdit::class)
                ->can('view', ['procedure', 'preperson'])
                ->whereNumber('procedure')
                ->name('procedure.edit');
        });
    });
