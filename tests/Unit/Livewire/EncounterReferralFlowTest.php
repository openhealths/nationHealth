<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire;

use App\Enums\Person\EncounterStatus;
use App\Livewire\EncounterPackage\Concerns\ManagesEncounterReferrals;
use App\Livewire\Referral\ReferralIndex;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Repositories\EmployeeRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EncounterReferralFlowTest extends TestCase
{
    public function testFinishedEncounterOpensAndInitializesReferralDrawer(): void
    {
        $component = $this->finishedEncounterComponent();
        $component->encounterReferralForm = ['service_id' => 'old-selection'];
        $component->encounterReferralServiceResults = [['id' => 'old-selection']];
        $component->encounterReferralSelectedService = ['id' => 'old-selection'];
        $component->encounterReferralWarningMessage = 'old-error';

        $component->openEncounterReferralDrawer();

        $this->assertTrue($component->showEncounterReferralDrawer);
        $this->assertSame('', $component->encounterReferralForm['service_id']);
        $this->assertSame(now()->format('d.m.Y'), $component->encounterReferralForm['started_at']);
        $this->assertSame([], $component->encounterReferralServiceResults);
        $this->assertNull($component->encounterReferralSelectedService);
        $this->assertSame('', $component->encounterReferralWarningMessage);
        $this->assertTrue($component->authMethodsLoaded);
        $this->assertTrue($component->programsLoaded);
        $this->assertNull($component->redirectTarget);
    }

    public function testPrepersonEncounterOpensDrawerWithoutRedirectingToPatientPage(): void
    {
        $component = $this->finishedEncounterComponent();
        $component->prepersonId = 20;
        $component->encounter->person_id = null;
        $component->encounter->preperson_id = 20;

        $component->openEncounterReferralDrawer();

        $this->assertTrue($component->showEncounterReferralDrawer);
        $this->assertNull($component->redirectTarget);
    }

    public function testUnfinishedEncounterCannotOpenReferralDrawer(): void
    {
        $component = $this->finishedEncounterComponent();
        $component->encounter->status = EncounterStatus::DRAFT;

        $component->openEncounterReferralDrawer();

        $this->assertFalse($component->showEncounterReferralDrawer);
        $this->assertFalse($component->authMethodsLoaded);
        $this->assertNotNull(Session::get('error'));
    }

    public function testValidReferralCreatesDraftAndOpensExistingSignatureModal(): void
    {
        $component = $this->finishedEncounterComponent();
        $component->openEncounterReferralDrawer();
        $component->encounterReferralForm['service_id'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        Auth::shouldReceive('user')->once()->andReturnNull();

        $employeeContext = ['employee_id' => 10];
        $employees = $this->createMock(EmployeeRepository::class);
        $employees->expects($this->once())->method('resolveEncounterEmployeeContext')
            ->with($component->encounter, null)->willReturn($employeeContext);
        $component->draftHandler = function (Encounter $encounter, array $data, float $quantity, array $context) use ($component, $employeeContext): string {
            $this->assertSame($component->encounter, $encounter);
            $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $data['service_id']);
            $this->assertSame('service_request', $data['kind']);
            $this->assertNull($data['program_id']);
            $this->assertSame(1.0, $quantity);
            $this->assertSame($employeeContext, $context);

            return '11111111-2222-4333-8444-555555555555';
        };
        $this->instance(EmployeeRepository::class, $employees);

        $component->validateEncounterReferral();

        $this->assertSame('11111111-2222-4333-8444-555555555555', $component->encounterReferralRequestIdToSign);
        $this->assertFalse($component->showEncounterReferralDrawer);
        $this->assertTrue($component->showSignatureModal);
        $this->assertSame('sign_referral', $component->actionType);
    }

    public function testInvalidReferralDoesNotCreateDraftOrOpenSignature(): void
    {
        $component = $this->finishedEncounterComponent();
        $component->openEncounterReferralDrawer();
        $employees = $this->createMock(EmployeeRepository::class);
        $employees->expects($this->never())->method('resolveEncounterEmployeeContext');
        $this->instance(EmployeeRepository::class, $employees);

        try {
            $component->validateEncounterReferral();
            $this->fail('A service must be selected before creating the draft.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('encounterReferralForm.service_id', $exception->errors());
        }

        $this->assertTrue($component->showEncounterReferralDrawer);
        $this->assertFalse($component->showSignatureModal);
        $this->assertNull($component->encounterReferralRequestIdToSign);
    }

    public function testDraftFailureKeepsFormOpenWithoutReportingSuccess(): void
    {
        $component = $this->finishedEncounterComponent();
        $component->openEncounterReferralDrawer();
        $component->encounterReferralForm['service_id'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        Auth::shouldReceive('user')->once()->andReturnNull();
        $employees = $this->createMock(EmployeeRepository::class);
        $employees->expects($this->once())->method('resolveEncounterEmployeeContext')->willReturn([]);
        $component->draftHandler = static fn (): string => throw new RuntimeException('Draft failed');
        $this->instance(EmployeeRepository::class, $employees);

        $component->validateEncounterReferral();

        $this->assertTrue($component->showEncounterReferralDrawer);
        $this->assertFalse($component->showSignatureModal);
        $this->assertNull($component->encounterReferralRequestIdToSign);
        $this->assertStringContainsString('Draft failed', $component->encounterReferralWarningMessage);
        $this->assertNull(Session::get('success'));
    }

    public function testReferralRegistryStartsEmptyUntilSearch(): void
    {
        $component = new ReferralIndex();

        $this->assertFalse($component->hasSearched);
        $this->assertSame([], $component->searchResults);
    }

    private function finishedEncounterComponent(): EncounterReferralHarness
    {
        $component = new EncounterReferralHarness();
        $component->encounter = new Encounter(['status' => EncounterStatus::FINISHED]);

        return $component;
    }
}

class EncounterReferralHarness extends Component
{
    use ManagesEncounterReferrals;

    public Encounter $encounter;

    public ?\Closure $draftHandler = null;

    protected function createEncounterReferralDraft(Encounter $encounter, array $formData, float $qty, array $employeeContext): string
    {
        if ($this->draftHandler === null) {
            throw new RuntimeException('Draft creation was not expected.');
        }

        return ($this->draftHandler)($encounter, $formData, $qty, $employeeContext);
    }


    public ?int $prepersonId = null;

    public bool $showSignatureModal = false;

    public ?string $actionType = null;

    public ?string $redirectTarget = null;

    public bool $authMethodsLoaded = false;

    public bool $programsLoaded = false;

    protected function resolveEncounterModelForStandalone(): ?Encounter
    {
        return $this->encounter;
    }

    protected function loadEncounterReferralAuthMethods(Encounter $encounter): void
    {
        $this->authMethodsLoaded = true;
    }

    protected function loadEncounterReferralPrograms(): void
    {
        $this->programsLoaded = true;
    }

    protected function resolveDefaultEncounterReferralProgramId(): string
    {
        return '';
    }

    public function validate($rules = null, $messages = [], $attributes = [])
    {
        return Validator::make(['encounterReferralForm' => $this->encounterReferralForm], $rules, $messages, $attributes)->validate();
    }

    public function redirectRoute($name, $parameters = [], $absolute = true, $navigate = false)
    {
        $this->redirectTarget = $name;
    }
}
