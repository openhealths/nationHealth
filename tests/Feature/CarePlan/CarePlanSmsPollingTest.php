<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Livewire\CarePlan\Concerns\HandlesCarePlanApprovalSms;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Repositories\MedicalEvents\ApprovalRepository;
use App\Repositories\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\TestCase;

class CarePlanSmsPollingTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;

    #[DataProvider('readAccess')]
    public function test_sms_polling_checks_read_policy_before_loading_state(bool $allowed): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity, 'user' => $user] = $this->compositionFixture(scopes: ['care_plan:read']);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $plan = Repository::carePlan()->storeResource($resource, $person->id, $legalEntity->id);
        $this->assertTrue($user->can('view', $plan));
        if (!$allowed) {
            $foreign = LegalEntity::create(['uuid' => (string) Str::uuid(), 'status' => 'ACTIVE',
                'legal_entity_type_id' => $legalEntity->legalEntityTypeId]);
            $plan->update(['legal_entity_id' => $foreign->id]);
            $this->assertFalse($user->can('view', $plan->fresh()));
        }
        $approvalId = (string) Str::uuid();
        $repository = Mockery::mock(ApprovalRepository::class);
        if ($allowed) {
            $repository->shouldReceive('carePlanSmsResendState')->once()
                ->with(Mockery::on(static fn (CarePlan $current): bool => $current->id === $plan->id), $approvalId, 17)
                ->andReturn(['status' => 'PENDING']);
        } else {
            $repository->shouldNotReceive('carePlanSmsResendState');
        }
        $this->instance(ApprovalRepository::class, $repository);
        Http::preventStrayRequests();
        Bus::fake();
        $component = Livewire::test(CarePlanSmsPollingHarness::class, ['carePlanId' => $plan->id, 'approvalId' => $approvalId])
            ->call('checkSmsResendStatus')->assertSet('smsResendLinkId', 17)->assertSet('isSmsResending', true);
        if (!$allowed) {
            $component->assertSee(__('care-plan.approval_access_denied'));
        }
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public static function readAccess(): array
    {
        return ['allowed pending poll' => [true], 'denied poll' => [false]];
    }
}

class CarePlanSmsPollingHarness extends Component
{
    use HandlesCarePlanApprovalSms;

    public int $carePlanId = 0;
    public ?string $approvalId = null;

    public function mount(int $carePlanId, string $approvalId): void
    {
        $this->carePlanId = $carePlanId;
        $this->approvalId = $approvalId;
        $this->smsResendLinkId = 17;
        $this->isSmsResending = true;
    }

    protected function carePlanForSms(): CarePlan
    {
        return Repository::carePlan()->findById($this->carePlanId);
    }

    protected function onSmsApprovalAlreadyGranted(): void
    {
    }

    public function render(): string
    {
        return '<div><livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" /></div>';
    }
}
