<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Approval as ApprovalApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\CarePlan\CarePlanApprovals;
use App\Models\MedicalEvents\Sql\Identifier;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\CreatesFhirCarePlans;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class CarePlanRevokeApprovalTest extends TestCase
{
    use CompositionTestFixtures;
    use CreatesFhirCarePlans;
    use DatabaseTransactions;
    use UsesCompositionDictionaries;

    private function context(bool $canCancel = true): array
    {
        Http::preventStrayRequests();
        $scopes = ['care_plan:read', 'care_plan:write', 'approval:read'];
        if ($canCancel) {
            $scopes[] = 'approval:cancel';
        }
        $context = $this->compositionFixture(scopes: $scopes);
        $plan = $this->createFhirCarePlan([
            'person_id' => $context['person']->id, 'legal_entity_id' => $context['legalEntity']->id,
            'author_id' => $context['employee']->id, 'status' => 'active', 'terms_of_service' => 'INPATIENT',
        ]);
        $identifier = Identifier::create(['value' => $context['employee']->uuid]);
        $approval = $plan->approvals()->create([
            'uuid' => (string) Str::uuid(), 'granted_to_id' => $identifier->id, 'created_by_id' => $identifier->id,
            'granted_to_type' => 'employee', 'status' => 'active', 'is_verified' => true, 'expires_at' => now()->addDay(),
        ]);

        return $context + compact('plan', 'approval');
    }

    public function test_visible_revoke_button_calls_the_backend_and_refreshes_the_granted_approvals(): void
    {
        $context = $this->context();
        $identifier = ['identifier' => [
            'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'employee']]],
            'value' => $context['employee']->uuid,
        ]];
        $response = new EHealthResponse(
            new Response(201, [], json_encode(['data' => [
                'id' => $context['approval']->uuid,
                'granted_resources' => [], 'granted_resource_types' => [],
                'granted_to' => $identifier, 'created_by' => $identifier,
                'expires_at' => now()->toIso8601String(), 'is_verified' => true, 'access_level' => 'read',
                'authentication_method_current' => ['type' => 'OFFLINE'],
            ]], JSON_THROW_ON_ERROR)),
            (new ReflectionMethod(ApprovalApi::class, 'validateCancellation'))->getClosure(new ApprovalApi()),
        );
        $this->mock(ApprovalApi::class)->shouldReceive('cancelApproval')->once()
            ->with($context['person']->uuid, $context['approval']->uuid)->andReturn($response);
        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $context['legalEntity'], 'carePlan' => $context['plan']])
            ->assertSee(__('care-plan.revoke_approval'))
            ->assertSeeHtml('wire:click="cancelApproval(\''.$context['approval']->uuid.'\')"')
            ->call('cancelApproval', $context['approval']->uuid)->assertSee(__('care-plan.approval_cancelled'))
            ->assertDontSeeHtml('wire:click="cancelApproval(\''.$context['approval']->uuid.'\')"');
        $this->assertTrue($context['approval']->fresh()->expiresAt->isPast());
    }

    public function test_denial_does_not_revoke_the_approval_or_call_ehealth(): void
    {
        $context = $this->context(false);
        $expiry = $context['approval']->expiresAt;
        $this->mock(ApprovalApi::class)->shouldNotReceive('cancelApproval');
        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $context['legalEntity'], 'carePlan' => $context['plan']])
            ->call('cancelApproval', $context['approval']->uuid)->assertSee(__('approval.messages.cancel_forbidden'));
        $this->assertTrue($expiry->equalTo($context['approval']->fresh()->expiresAt));
    }
}
