<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Approval as ApprovalApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Person\ApprovalStatus;
use App\Exceptions\EHealth\EHealthJobTimeoutException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\CarePlan\CarePlanApprovals;
use App\Livewire\CarePlan\CarePlanIndex;
use App\Livewire\Person\Records\PatientCarePlans;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Repositories\Repository;
use App\Services\MedicalEvents\CarePlanApprovalService;
use App\Services\MedicalEvents\EHealthJobResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class CarePlanRegistryQualityTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use UsesCompositionDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('uk');
        Http::preventStrayRequests();
    }

    public function test_both_lists_prioritize_complete_active_plans_and_allow_incomplete_records_to_be_found(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        $repository = Repository::carePlan();
        $ids = [];
        foreach (['active', 'new', 'draft', 'on-hold', 'terminated'] as $status) {
            $resource = $this->resource();
            $resource['id'] = (string) Str::uuid();
            $resource['title'] = 'Full '.$status;
            $resource['status'] = $status;
            $ids[] = $repository->storeResource($resource, $person->id, $legalEntity->id)->id;
        }
        $partial = $repository->storeResource(array_replace($this->resource(), ['title' => 'Incomplete activity']), $person->id, $legalEntity->id);
        CarePlanActivity::forceCreate(['carePlanId' => $partial->id, 'uuid' => (string) Str::uuid(), 'status' => 'scheduled', 'kind' => 'medication_request']);
        $legacy = CarePlan::forceCreate([
            'uuid' => (string) Str::uuid(), 'personId' => $person->id, 'legalEntityId' => $legalEntity->id,
            'status' => 'active', 'title' => 'Summary only', 'created_at' => now()->addDay(),
        ]);
        $this->assertSame($ids, $repository->listing($legalEntity->id, ['completeness' => 'complete'])->modelKeys());
        $all = $repository->getByPersonId($person->id, legalEntityId: $legalEntity->id)->modelKeys();
        $this->assertSame($ids, array_slice($all, 0, count($ids)));
        $this->assertEqualsCanonicalizing([$partial->id, $legacy->id], $repository->listing($legalEntity->id, ['completeness' => 'incomplete'])->modelKeys());

        foreach ([CarePlanIndex::class, PatientCarePlans::class] as $component) {
            Livewire::test($component, ['legalEntity' => $legalEntity, 'personId' => $person->id])
                ->assertSet('carePlans', static fn ($plans): bool => collect($plans)->pluck('id')->all() === array_slice($ids, 0, 4))
                ->assertDontSeeHtml('wire:key="care-plan-'.$ids[4].'"')
                ->set('filterStatus', '')->call($component === PatientCarePlans::class ? 'loadCarePlans' : 'search')
                ->assertSet('carePlans', static fn ($plans): bool => collect($plans)->pluck('id')->all() === $ids)
                ->assertDontSeeHtml('wire:key="care-plan-'.$legacy->id.'"')->assertDontSeeHtml('wire:key="care-plan-'.$partial->id.'"')
                ->set('filterCompleteness', 'incomplete')->assertSeeHtml('wire:key="care-plan-'.$legacy->id.'"')->assertSeeHtml('wire:key="care-plan-'.$partial->id.'"')->assertDontSeeHtml('wire:key="care-plan-'.$ids[0].'"')
                ->set('filterCompleteness', 'all')->assertSeeHtml('wire:key="care-plan-'.$ids[0].'"')->assertSeeHtml('wire:key="care-plan-'.$legacy->id.'"')
                ->call('resetFilters')->assertSet('filterCompleteness', 'complete')->assertSet('filterStatus', 'current')
                ->assertDontSeeHtml('wire:key="care-plan-'.$legacy->id.'"')->assertDontSeeHtml('wire:key="care-plan-'.$ids[4].'"');
        }
        Http::assertNothingSent();
    }

    public function test_local_drafts_never_appear_as_requiring_remote_details_in_either_list(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        $repository = Repository::carePlan();
        $drafts = [
            CarePlan::forceCreate([
                'personId' => $person->id, 'legalEntityId' => $legalEntity->id,
                'status' => 'draft', 'title' => 'Local draft draft',
            ]),
            $repository->storeResource(array_replace($this->resource(), [
                'status' => 'DRAFT', 'title' => 'Local draft DRAFT',
            ]), $person->id, $legalEntity->id),
        ];
        CarePlanActivity::forceCreate([
            'carePlanId' => $drafts[1]->id, 'uuid' => (string) Str::uuid(),
            'status' => 'scheduled', 'kind' => 'medication_request',
        ]);
        $incomplete = CarePlan::forceCreate([
            'personId' => $person->id, 'legalEntityId' => $legalEntity->id,
            'uuid' => (string) Str::uuid(), 'status' => 'active', 'title' => 'Needs remote details',
        ]);
        $this->assertEqualsCanonicalizing(array_map(static fn (CarePlan $plan): int => $plan->id, $drafts), $repository->listing($legalEntity->id, ['completeness' => 'complete'])->modelKeys());
        $this->assertSame([$incomplete->id], $repository->listing($legalEntity->id, ['completeness' => 'incomplete'])->modelKeys());
        foreach ([CarePlanIndex::class, PatientCarePlans::class] as $component) {
            Livewire::test($component, ['legalEntity' => $legalEntity, 'personId' => $person->id])
                ->assertSeeHtml('wire:key="care-plan-'.$drafts[0]->id.'"')->assertSeeHtml('wire:key="care-plan-'.$drafts[1]->id.'"')->assertDontSeeHtml('wire:key="care-plan-'.$incomplete->id.'"')
                ->set('filterCompleteness', 'incomplete')->assertSeeHtml('wire:key="care-plan-'.$incomplete->id.'"')
                ->assertDontSeeHtml('wire:key="care-plan-'.$drafts[0]->id.'"')->assertDontSeeHtml('wire:key="care-plan-'.$drafts[1]->id.'"');
        }
        Http::assertNothingSent();
    }

    public function test_repeated_summary_refresh_updates_only_incomplete_records_without_duplicating_them(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        $repository = Repository::carePlan();
        $summary = ['id' => (string) Str::uuid(), 'title' => 'Short title', 'status' => 'active', 'requisition' => '1111'];
        $repository->storeSummaries([$summary], $person->id, $legalEntity->id);
        $summary['status'] = 'terminated';
        $repository->storeSummaries([$summary], $person->id, $legalEntity->id);
        $this->assertSame(1, CarePlan::wherePersonId($person->id)->count());
        $plan = CarePlan::whereUuid($summary['id'])->firstOrFail();
        $this->assertSame('terminated', $plan->status);
        $this->assertNull($plan->detailsSyncedAt);
    }

    public function test_uuid_titles_use_the_public_number_in_both_registries_without_changing_clinical_data(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        $repository = Repository::carePlan();
        $uuid = (string) Str::uuid();
        $repository->storeSummaries([
            ['id' => $uuid, 'title' => $uuid, 'status' => 'active', 'requisition' => '1234-5678-9012-3456'],
        ], $person->id, $legalEntity->id);
        $resource = $this->resource();
        $resource['title'] = $resource['id'];
        $resource['requisition'] = '4321-8765-2109-6543';
        $plan = $repository->storeResource($resource, $person->id, $legalEntity->id);
        $displayTitles = array_column($repository->patientListing($person->id, ['completeness' => 'all'], $legalEntity->id), 'title');
        $expected = [
            __('care-plan.care_plan_with_number', ['number' => '1234-5678-9012-3456']),
            __('care-plan.care_plan_with_number', ['number' => '4321-8765-2109-6543']),
        ];
        $this->assertEqualsCanonicalizing($expected, $displayTitles);
        foreach ([CarePlanIndex::class, PatientCarePlans::class] as $component) {
            Livewire::test($component, ['legalEntity' => $legalEntity, 'personId' => $person->id])
                ->set('filterCompleteness', 'all')->assertSee($expected[0])->assertSee($expected[1]);
        }
        $this->assertSame($resource['id'], $repository->getResource($plan)['title']);
        $this->assertSame($uuid, CarePlan::whereUuid($uuid)->firstOrFail()->title);
        $this->assertSame(__('care-plan.care_plan'), (new CarePlan(['title' => $uuid]))->displayTitle());
        $this->assertSame('Clinical title', (new CarePlan(['title' => 'Clinical title']))->displayTitle());
        Http::assertNothingSent();
    }

    #[DataProvider('cancellationResponses')]
    public function test_recipient_can_revoke_without_care_plan_write_permission_and_sees_confirmed_result(int $statusCode, ?string $legacyStatus): void
    {
        [$plan, $approval, $legalEntity] = $this->approvalContext(['care_plan:read', 'approval:cancel']);
        $approval->update(['status' => $legacyStatus]);
        $api = Mockery::mock(ApprovalApi::class);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn($statusCode === 201
            ? ['id' => $approval->uuid, 'is_verified' => true]
            : ['links' => [['href' => '/api/jobs/test-cancel']]]);
        $response->shouldReceive('status')->once()->andReturn($statusCode);
        $api->shouldReceive('cancelApproval')->once()->with($plan->person->uuid, $approval->uuid)->andReturn($response);
        $this->instance(ApprovalApi::class, $api);
        if ($statusCode === 202) {
            $resolver = Mockery::mock(EHealthJobResolver::class);
            $resolver->shouldReceive('resolve')->once()->andReturn(['status' => 'processed']);
            $this->instance(EHealthJobResolver::class, $resolver);
        }

        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $legalEntity, 'carePlan' => $plan])
            ->assertSee(__('care-plan.revoke_approval'))
            ->call('cancelApproval', $approval->uuid)->assertSee(__('care-plan.approval_cancelled'))
            ->assertDontSeeHtml('wire:click="cancelApproval')->assertDispatched('care-plan-approvals-changed');
        $this->assertSame($legacyStatus, $approval->fresh()->status);
        $this->assertTrue($approval->fresh()->getAttribute('is_verified'));
        $this->assertFalse($approval->fresh()->expiresAt->isFuture());
    }

    public static function cancellationResponses(): array
    {
        return [
            'synchronous' => [201, null],
            'asynchronous' => [202, null],
            'synchronous legacy status' => [201, 'active'],
            'asynchronous legacy status' => [202, 'active'],
        ];
    }

    public function test_previously_expired_approval_is_not_displayed_as_active(): void
    {
        [$plan, $approval, $legalEntity] = $this->approvalContext(['care_plan:read', 'approval:cancel']);
        $approval->update(['expires_at' => now()->subDay()]);
        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $legalEntity, 'carePlan' => $plan])
            ->assertSee(__('care-plan.no_approvals_found'))->assertDontSeeHtml('wire:click="cancelApproval');
        Http::assertNothingSent();
    }

    #[DataProvider('approvalVerificationFlags')]
    public function test_approval_expiring_after_mount_is_hidden_on_the_next_render(bool $verified): void
    {
        [$plan, $approval, $legalEntity] = $this->approvalContext(['care_plan:read', 'approval:cancel']);
        $approval->update(['is_verified' => $verified]);
        $component = Livewire::test(CarePlanApprovals::class, ['legalEntity' => $legalEntity, 'carePlan' => $plan])
            ->assertDontSee(__('care-plan.no_approvals_found'));

        $this->travelTo(now()->addDays(2), function () use ($component): void {
            $component->call('$refresh')->assertSee(__('care-plan.no_approvals_found'))
                ->assertDontSeeHtml('wire:click="cancelApproval')
                ->assertDontSeeHtml('wire:click="verifyExistingApproval');
        });
        Http::assertNothingSent();
    }

    public static function approvalVerificationFlags(): array
    {
        return ['verified' => [true], 'awaiting confirmation' => [false]];
    }

    public function test_revocation_denial_has_visible_feedback_and_no_api_or_database_effects(): void
    {
        [$plan, $approval, $legalEntity] = $this->approvalContext(['care_plan:read']);
        $before = $approval->fresh()->getAttributes();
        $this->instance(ApprovalApi::class, Mockery::mock(ApprovalApi::class));
        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $legalEntity, 'carePlan' => $plan])
            ->call('cancelApproval', $approval->uuid)->assertSee(__('approval.messages.cancel_forbidden'))
            ->assertNotDispatched('care-plan-approvals-changed');
        $this->assertSame($before, $approval->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_creator_without_recipient_access_or_declaration_cannot_revoke_another_employees_consent(): void
    {
        [$plan, $approval, $legalEntity] = $this->approvalContext(['care_plan:read', 'approval:cancel']);
        $recipient = Identifier::create(['value' => (string) Str::uuid()]);
        $approval->update(['created_by_id' => $approval->grantedToId, 'granted_to_id' => $recipient->id]);
        $before = $approval->fresh()->getAttributes();
        $this->instance(ApprovalApi::class, Mockery::mock(ApprovalApi::class));

        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $legalEntity, 'carePlan' => $plan])
            ->call('cancelApproval', $approval->uuid)->assertSee(__('approval.messages.cancel_forbidden'))
            ->assertNotDispatched('care-plan-approvals-changed');
        $this->assertSame($before, $approval->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    #[DataProvider('unconfirmedCancellations')]
    public function test_failed_or_pending_remote_revocation_preserves_the_existing_approval(bool $pending): void
    {
        [$plan, $approval, $legalEntity] = $this->approvalContext(['care_plan:read', 'approval:cancel']);
        $before = $approval->fresh()->getAttributes();
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(['links' => [['href' => '/api/jobs/test-cancel']]]);
        $response->shouldReceive('status')->once()->andReturn(202);
        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('cancelApproval')->once()->andReturn($response);
        $this->instance(ApprovalApi::class, $api);
        $resolver = Mockery::mock(EHealthJobResolver::class);
        $resolver->shouldReceive('resolve')->once()->andThrow($pending
            ? new EHealthJobTimeoutException('test-cancel', 1, 'pending')
            : new EHealthValidationException(['error' => ['message' => 'Rejected cancellation']]));
        $this->instance(EHealthJobResolver::class, $resolver);

        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $legalEntity, 'carePlan' => $plan])
            ->call('cancelApproval', $approval->uuid)
            ->assertSee(__($pending ? 'care-plan.approval_cancel_unconfirmed' : 'care-plan.approval_cancel_error'))
            ->assertDontSee(__('care-plan.approval_cancelled'))->assertNotDispatched('care-plan-approvals-changed');
        $this->assertSame($before, $approval->fresh()->getAttributes());
    }

    public static function unconfirmedCancellations(): array
    {
        return ['pending' => [true], 'failed' => [false]];
    }

    private function resource(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function approvalContext(array $scopes): array
    {
        ['person' => $person, 'legalEntity' => $legalEntity, 'employee' => $employee] = $this->compositionFixture(scopes: $scopes);
        $plan = Repository::carePlan()->storeResource($this->resource(), $person->id, $legalEntity->id);
        $this->assertTrue(auth()->user()->can('care_plan:read'));
        $this->assertTrue(auth()->user()->can('view', $plan));
        $recipient = Identifier::create(['value' => $employee->uuid]);
        $otherCreator = Identifier::create(['value' => (string) Str::uuid()]);
        $approval = Approval::create([
            'uuid' => (string) Str::uuid(), 'approvable_type' => $plan->getMorphClass(), 'approvable_id' => $plan->id,
            'granted_to_id' => $recipient->id, 'created_by_id' => $otherCreator->id,
            'granted_to_type' => 'employee', 'status' => ApprovalStatus::ACTIVE->value,
            'is_verified' => true, 'access_level' => 'write', 'expires_at' => now()->addDay(),
        ]);
        $service = Mockery::mock(CarePlanApprovalService::class)->makePartial();
        $service->shouldReceive('skipsPatientOtp')->andReturn(true);
        $this->instance(CarePlanApprovalService::class, $service);

        return [$plan, $approval, $legalEntity];
    }
}
