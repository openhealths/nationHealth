<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\EHealthLoginController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\HomeController;
use App\Livewire\Actions\Logout;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\LoginDev;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Auth\SelectLegalEntity;
use App\Livewire\Auth\VerifyEmail;
use App\Livewire\Auth\VerifyPersonality;
use App\Livewire\Contract\CapitationContractCreate;
use App\Livewire\Contract\ContractIndex;
use App\Livewire\Contract\ContractShow;
use App\Livewire\Contract\ReimbursementContractCreate;
use App\Livewire\ContractRequest\ContractRequestEdit;
use App\Livewire\ContractRequest\ContractRequestIndex;
use App\Livewire\ContractRequest\ContractRequestShow;
use App\Livewire\Dashboard;
use App\Livewire\Declaration\DeclarationCreate;
use App\Livewire\Declaration\DeclarationEdit;
use App\Livewire\Declaration\DeclarationIndex;
use App\Livewire\Declaration\DeclarationView;
use App\Livewire\DiagnosticReport\DiagnosticReportCreate;
use App\Livewire\Division\DivisionCreate;
use App\Livewire\Division\DivisionEdit;
use App\Livewire\Division\DivisionIndex;
use App\Livewire\Division\DivisionView;
use App\Livewire\Division\HealthcareService\HealthcareServiceCreate;
use App\Livewire\Division\HealthcareService\HealthcareServiceEdit;
use App\Livewire\Division\HealthcareService\HealthcareServiceIndex;
use App\Livewire\Division\HealthcareService\HealthcareServiceUpdate;
use App\Livewire\Division\HealthcareService\HealthcareServiceView;
use App\Livewire\Employee\EmployeeCreate;
use App\Livewire\Employee\EmployeeEdit;
use App\Livewire\Employee\EmployeeIndex;
use App\Livewire\Employee\EmployeePositionAdd;
use App\Livewire\Employee\EmployeeRequestEdit;
use App\Livewire\Employee\EmployeeRequestShow;
use App\Livewire\Employee\EmployeeShow;
use App\Livewire\EmployeeRequest\EmployeeRequestIndex;
use App\Livewire\EmployeeRole\EmployeeRoleCreate;
use App\Livewire\EmployeeRole\EmployeeRoleIndex;
use App\Livewire\Encounter\EncounterCreate;
use App\Livewire\Encounter\EncounterEdit;
use App\Livewire\Equipment\EquipmentCreate;
use App\Livewire\Equipment\EquipmentEdit;
use App\Livewire\Equipment\EquipmentIndex;
use App\Livewire\LegalEntity\LegalEntityDetails;
use App\Livewire\Equipment\EquipmentView;
use App\Livewire\LegalEntity\CreateLegalEntity;
use App\Livewire\LegalEntity\EditLegalEntity;
use App\Livewire\License\LicenseCreate;
use App\Livewire\License\LicenseEdit;
use App\Livewire\License\LicenseIndex;
use App\Livewire\License\LicenseView;
use App\Livewire\Party\PartyEdit;
use App\Livewire\Party\PartyVerify;
use App\Livewire\Party\PartyVerificationIndex;
use App\Livewire\Person\PersonCreate;
use App\Livewire\Person\PersonUpdate;
use App\Livewire\Person\PersonRequestEdit;
use App\Livewire\Person\PersonIndex;
use App\Livewire\Person\Records\PersonData;
use App\Livewire\Person\Records\PersonEpisodes;
use App\Livewire\Person\Records\PersonSummary;
use App\Livewire\Procedure\ProcedureCreate;
use App\Models\Declaration;
use App\Models\DeclarationRequest;
use App\Models\Division;
use App\Models\EmployeeRole;
use App\Models\Equipment;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Models\License;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Person\Person;
use App\Models\Person\PersonRequest;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', [HomeController::class, 'index'])->name('home.index');
Route::post('/send-email', [EmailController::class, 'sendEmail'])->name('send.email');

/* Auth */

Route::get('/ehealth/oauth', EHealthLoginController::class)->name('ehealth.oauth.callback');

Route::middleware('guest')->group(function () {
    Route::get('login', Login::class)->name('login');
    Route::get('register', Register::class)->name('register');
    Route::get('forgot-password', ForgotPassword::class)->name('forgot.password');
    Route::get('reset-password/{token}', ResetPassword::class)->name('password.reset');

    Route::get('email/verify', VerifyEmail::class)->name('verification.notice');

    Route::get('email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    // Login to eHealth for development environment only
    if (App::isLocal()) {
        Route::get('dev/login', LoginDev::class)->name('dev.login');
    }
});

Route::post('logout', Logout::class)->name('logout');

/* Dashboard */
Route::middleware(['auth:web,ehealth', 'verified'])->group(function () {

    Route::get('/verify-personality', VerifyPersonality::class)->name('party.verify');

    Route::get('/select-legal-entity', SelectLegalEntity::class)->name('legalEntity.select');

    Route::prefix('/dashboard')->group(function () {
        Route::get('/', Dashboard::class)
            ->can('limitedAction', LegalEntity::class)
            ->name('dashboard.index');

        Route::get('/legal-entities/create', CreateLegalEntity::class)
            ->can('limitedAction', LegalEntity::class)
            ->name('legal-entity.new.create');
    });

    Route::middleware(['can:access,legalEntity'])->prefix('/dashboard/{legalEntity}')
        ->whereNumber('legalEntity')
        ->group(function () {
            Route::get('/', [HomeController::class, 'dashboard'])->name('dashboard');

            Route::get('/dictionary/programs-medications', \App\Livewire\Dictionary\ProgramsMedications::class)
                ->name('dictionary.programs-medications.index');

            Route::get('/dictionary/programs-services', \App\Livewire\Dictionary\ProgramsServices::class)
                ->name('dictionary.programs-services.index');

            Route::get('/edit', EditLegalEntity::class)
                ->can('edit', 'legalEntity')
                ->name('legal-entity.edit');

            Route::get('/create', CreateLegalEntity::class)
                ->can('create', LegalEntity::class)
                ->name('legal-entity.create');

            Route::get('/details', LegalEntityDetails::class)
                ->can('viewAny', LegalEntity::class)
                ->name('legal-entity.details');

            Route::get('/healthcare-service', HealthcareServiceIndex::class)
                ->name('healthcare-service.index')
                ->can('viewAny', HealthcareService::class);

            Route::prefix('division')->middleware(['permission:division:read|division:details'])->group(function () {
                Route::get('/', DivisionIndex::class)->name('division.index')->can('viewAny', Division::class);
                Route::get('/create', DivisionCreate::class)->name('division.create')->can('create', Division::class);
                Route::get('/{division}', DivisionView::class)->name('division.view')->can('viewAny', Division::class);
                Route::get('/{division}/edit', DivisionEdit::class)->name('division.edit')->can('update', 'division');

                Route::prefix('{division}/healthcare-service')->name('healthcare-service.')->group(static function () {
                    Route::get('/create', HealthcareServiceCreate::class)
                        ->name('create')
                        ->can('create', HealthcareService::class);
                    Route::get('/{healthcareService}', HealthcareServiceView::class)
                        ->name('view')
                        ->can('view', 'healthcareService');
                    Route::get('/{healthcareService}/edit', HealthcareServiceEdit::class)
                        ->name('edit')
                        ->can('edit', 'healthcareService');
                    Route::get('/{healthcareService}/update', HealthcareServiceUpdate::class)
                        ->name('update')
                        ->can('update', 'healthcareService');
                });
            });

            Route::prefix('employee')->name('employee.')->middleware('auth')->group(function () {
                Route::get('/', EmployeeIndex::class)->name('index');

                Route::get('/{employee}', EmployeeShow::class)
                    ->name('show')->middleware('can:view,employee');

                Route::get('/{employee}/edit', EmployeeEdit::class)
                    ->name('edit')->middleware('can:update,employee');
            });

            // --- Group for Employee Requests ---
            Route::prefix('employee-request')->name('employee-request.')->middleware('auth')->group(function () {
                Route::get('/', EmployeeRequestIndex::class)->name('index');
                Route::get('/create', EmployeeCreate::class)->name('create');
                Route::get('/party/{party}/position-add', EmployeePositionAdd::class)->name('position-add');

                Route::get('/{employee_request}', EmployeeRequestShow::class)
                    ->name('show')->middleware('can:view,employee_request');

                Route::get('/{employee_request}/edit', EmployeeRequestEdit::class)
                    ->name('edit')->middleware('can:update,employee_request');
            });

            Route::get('/party-verifications', PartyVerificationIndex::class)
                ->name('party.verification.index');
            Route::get('/party/{party}/verification', PartyVerify::class)
                ->name('party.verification.show');
            Route::get('/party/{party}/edit', PartyEdit::class)->name('party.edit');

            Route::get('/employee-role', EmployeeRoleIndex::class)
                ->name('employee-role.index')
                ->can('viewAny', EmployeeRole::class);
            Route::get('/employee-role/create', EmployeeRoleCreate::class)
                ->name('employee-role.create')
                ->can('create', EmployeeRole::class);

            // --- Group of Contracts (Already signed/active) ---
            Route::prefix('contract')->name('contract.')->group(function () {
                // Main page of existing contracts
                Route::get('/', ContractIndex::class)->name('index');

                // View (default type = 'contract')
                Route::get('/{contract:uuid}', ContractShow::class)->name('show');
            });

            // --- Contract Request Group (Contract Requests) ---
            Route::prefix('contract-request')->name('contract-request.')->group(function () {
                Route::get('/', ContractRequestIndex::class)->name('index');
                Route::get('/{contract}', ContractRequestShow::class)->name('show');
                Route::get('/{contract}/edit', ContractRequestEdit::class)->name('edit');
                Route::get('/create/capitation', CapitationContractCreate::class)->name('capitation.create');
                Route::get('/create/reimbursement', ReimbursementContractCreate::class)->name('reimbursement.create');
            });

            // Routes related to legal entity licenses; primary license can't be edited
            Route::prefix('license')->middleware(['permission:license:read|license:write'])
                ->name('license.')
                ->group(function () {
                    Route::get('/', LicenseIndex::class)->name('index')->can('viewAny', License::class);
                    Route::get('/create', LicenseCreate::class)->name('create')->can('create', License::class);

                    Route::middleware(['can:view,license'])->prefix('{license}')
                        ->whereNumber('license')->group(function () {
                            Route::get('/', LicenseView::class)->name('view')->can('view', 'license');
                            Route::get('/edit', LicenseEdit::class)->name('edit')->can('update', 'license');
                        });
                });

            Route::get('/treatment-plan', \App\Livewire\TreatmentPlan\TreatmentPlanIndex::class)
                ->name('treatmentPlan.index');
            Route::get('/treatment-plan/create', \App\Livewire\TreatmentPlan\TreatmentPlanCreate::class)
                ->name('treatmentPlan.create');

            Route::prefix('equipment')->name('equipment.')->group(static function () {
                Route::get('/', EquipmentIndex::class)->name('index')->can('viewAny', Equipment::class);
                Route::get('/create', EquipmentCreate::class)->name('create')->can('create', Equipment::class);
                Route::get('/{equipment}/edit', EquipmentEdit::class)->name('edit')->can('edit', 'equipment');
                Route::get('/{equipment}', EquipmentView::class)->name('view')->can('view', 'equipment');
            });

            Route::get('/declaration', DeclarationIndex::class)
                ->name('declaration.index')
                ->can('viewAny', Declaration::class);

            Route::prefix('persons')->group(static function () {
                Route::name('persons.')->group(static function () {
                    Route::get('/', PersonIndex::class)->can('viewAny', Person::class)->name('index');
                    Route::get('/create', PersonCreate::class)->can('create', PersonRequest::class)->name('create');
                    Route::get('/edit/{personRequest}', PersonRequestEdit::class)->can('create', PersonRequest::class)->name('edit');
                    Route::get('/update/{person}', PersonUpdate::class)->can('create', PersonRequest::class)->name('update');

                    Route::middleware('can:view,' . Person::class)->group(function () {
                        Route::get('/{patientId}/patient-data', PersonData::class)->name('patient-data');
                        Route::get('/{patientId}/summary', PersonSummary::class)->name('summary');
                        Route::get('/{patientId}/episodes', PersonEpisodes::class)->name('episodes');
                    });
                });



                Route::name('declaration.')->group(static function () {
                    Route::get('/declaration/{declaration}', DeclarationView::class)
                        ->can('view', 'declaration')
                        ->name('view')
                        ->whereNumber('declaration');
                    Route::get('/{patientId}/declaration/create', DeclarationCreate::class)
                        ->name('create')
                        ->can('create', DeclarationRequest::class)
                        ->whereNumber('patientId');
                    Route::get('/{patientId}/declaration/{declarationRequest}', DeclarationEdit::class)
                        ->name('edit')
                        ->can('update', 'declarationRequest')
                        ->whereNumber(['patientId', 'declarationRequest']);
                });

                Route::middleware('can:create,' . Encounter::class)->name('encounter.')->group(function () {
                    Route::get('/{patientId}/encounter/create', EncounterCreate::class)->name('create');
                    Route::get('/{patientId}/encounter/{encounterId}', EncounterEdit::class)->name('edit');
                });

                Route::whereNumber('patientId')->group(static function () {
                    Route::get('{patientId}/diagnostic-report/create', DiagnosticReportCreate::class)
                        ->can('create', DiagnosticReport::class)
                        ->name('diagnostic-report.create');

                    Route::get('{patientId}/procedure/create', ProcedureCreate::class)
                        ->can('create', Procedure::class)
                        ->name('procedure.create');
                });
            });
        });
});

Route::get('/page-not-found', fn () => view('errors.404'))->name('url.page-not-found');

/*
 * GLOBAL FALLBACK ROUTE (MUST BE LAST IN web.php)
 * This Route::fallback() will trigger for ANY request that has not been matched by any route above.
 * This is final 404 handler for both authenticated and unauthenticated users,
 * or for routes that simply do not fit into any structured groups.
 */
Route::fallback(fn () => redirect()->route('url.page-not-found'));
