<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Approval as ApprovalApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\Approval\ApprovalIndex;
use App\Models\CarePlan;
use App\Repositories\MedicalEvents\Repository;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Identifier;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class ApprovalRegistryProjectionTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use UsesCompositionDictionaries;

    #[DataProvider('recipientCases')]
    public function test_search_renders_existing_approvals_with_strict_attributes_and_correct_cancellation(
        string $recipient,
        bool $canCancel,
        bool $expired,
        bool $stored,
        bool $actionVisible
    ): void {
        app()->setLocale('uk');
        config()->set('pagination.per_page', 20);
        Http::preventStrayRequests();
        Bus::fake();
        $context = $this->compositionFixture(scopes: $canCancel ? ['approval:read', 'approval:cancel'] : ['approval:read']);
        $current = Identifier::create(['value' => $context['employee']->uuid]);
        $recipientUuid = $recipient === 'other' ? (string) Str::uuid() : $context['employee']->uuid;
        $recipientId = $recipient === 'missing' ? null : Identifier::firstOrCreate(['value' => $recipientUuid])->id;
        $uuid = (string) Str::uuid();
        $expiresAt = $expired ? now()->subHour() : now()->addHour();
        $approval = $stored ? Approval::create([
            'uuid' => $uuid,
            'approvable_type' => $context['person']->getMorphClass(),
            'approvable_id' => $context['person']->id,
            'granted_to_id' => $recipientId,
            'granted_to_type' => 'employee',
            'created_by_id' => $current->id,
            'status' => 'active',
            'is_verified' => true,
            'access_level' => 'read',
            'expires_at' => $expiresAt,
        ]) : null;
        $plan = CarePlan::forceCreate([
            'uuid' => (string) Str::uuid(), 'personId' => $context['person']->id,
            'legalEntityId' => $context['legalEntity']->id, 'status' => 'active', 'title' => 'Projection care plan',
        ]);
        $resource = ['identifier' => [
            'value' => $plan->uuid,
            'type' => ['coding' => [['code' => 'care_plan', 'system' => 'eHealth/resources']]],
        ], 'display_value' => 'Projection care plan'];
        if ($approval !== null) {
            $identifier = Identifier::create(['value' => $plan->uuid]);
            Repository::codeableConcept()->attach($identifier, $resource);
            $approval->grantedResources()->create(['granted_to_id' => $identifier->id]);
        }
        $before = $approval?->fresh()->getAttributes();
        $count = Approval::count();
        $identifier = static fn (string $value): array => ['identifier' => [
            'value' => $value,
            'type' => ['coding' => [['code' => 'employee', 'system' => 'eHealth/resources']]],
        ], 'display_value' => 'Synthetic employee'];
        $data = [
            'data' => [[
                'id' => $uuid,
                'created_by' => $identifier($context['employee']->uuid),
                'granted_to' => $identifier($recipientUuid),
                'is_verified' => true,
                'access_level' => 'read',
                'expires_at' => $expiresAt->toIso8601String(),
                'granted_resources' => [$resource],
                'granted_resource_types' => [],
            ]],
            'paging' => ['page_number' => 1, 'page_size' => 20, 'total_pages' => 1, 'total_entries' => 1],
        ];
        $api = Mockery::mock(ApprovalApi::class)->makePartial();
        $validator = (new ReflectionMethod(ApprovalApi::class, 'validateApprovalList'))->getClosure($api);
        $response = new EHealthResponse(new Response(200, [], json_encode($data)), $validator);
        $api->shouldReceive('getPatientApprovals')->once()->with($context['person']->uuid, Mockery::type('array'))->andReturn($response);
        $api->shouldNotReceive('createApproval');
        $api->shouldNotReceive('cancelApproval');
        $api->shouldNotReceive('getApprovalDetails');
        $this->instance(ApprovalApi::class, $api);

        $wasStrict = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();
        try {
            $component = Livewire::test(ApprovalIndex::class)
                ->set('filterPatientId', $context['person']->uuid)
                ->call('search')->assertSuccessful()->assertHasNoErrors();
            if ($stored) {
                $local = $component->get('localApprovalsByUuid')->get($uuid);
                $this->assertTrue($local->getAttribute('is_verified'));
                $this->assertSame('read', $local->accessLevel);
                $this->assertSame($expiresAt->getTimestamp(), $local->expiresAt->getTimestamp());
                $this->assertSame($recipient === 'missing' ? null : $recipientUuid, $local->grantedTo?->value);
                $this->assertSame([$plan->uuid], $local->grantedResourceIdentifiers->pluck('value')->all());
                $this->assertTrue($local->relationLoaded('grantedResourceTypes'));
            }
            if ($actionVisible) {
                $component->assertSeeHtml('wire:click="confirmCancelSearchedApproval');
            } else {
                $component->assertDontSeeHtml('wire:click="confirmCancelSearchedApproval');
            }
        } finally {
            Model::preventAccessingMissingAttributes($wasStrict);
        }

        $this->assertSame($count, Approval::count());
        if ($approval !== null) {
            $this->assertSame($before, $approval->fresh()->getAttributes());
        }
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public static function recipientCases(): array
    {
        return [
            'own recipient' => ['current', true, false, true, true],
            'other recipient despite current creator' => ['other', true, false, true, false],
            'missing stored recipient' => ['missing', true, false, true, false],
            'no cancellation scope' => ['current', false, false, true, false],
            'expired approval' => ['current', true, true, true, false],
            'remote approval not stored locally' => ['current', true, false, false, true],
        ];
    }
}
