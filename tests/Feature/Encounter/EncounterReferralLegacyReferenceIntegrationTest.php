<?php

declare(strict_types=1);

namespace Tests\Feature\Encounter;

use App\Livewire\EncounterPackage\EncounterReferralCreate;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Repositories\MedicalEvents\Repository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\TestCase;

class EncounterReferralLegacyReferenceIntegrationTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use \Tests\Concerns\MocksBasicDictionaries;
    use \Tests\Feature\Composition\UsesCompositionDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\App\Dto\ServiceRequest\Resource::class)) {
            $this->markTestSkipped('Requires ServiceRequest DTO metadata from PR #1230.');
        }
        $this->withoutVite();
        $this->fakeSignatureService();
        Http::preventStrayRequests();
        Http::fake();
        config(['cache.default' => 'array']);
        foreach ([\App\Services\Dictionary\Dictionaries\ServiceDictionary::KEY, \App\Services\Dictionary\Dictionaries\MedicalProgramDictionary::KEY] as $key) {
            \Illuminate\Support\Facades\Cache::forever($key, []);
            \Illuminate\Support\Facades\Cache::forever($key.':fresh', true);
        }
        $this->assertTrue(
            Schema::hasColumn('service_request_requests', 'resource_metadata'),
            'Prepare the testing database with the ServiceRequest install migrations before running this suite.'
        );
        $this->mockBasicDictionaries([
            'SERVICE_REQUEST_PRIORITY' => ['routine' => 'Планове'],
            'SERVICE_UNIT' => ['PIECE' => 'шт.'],
            'SERVICE_CATEGORY' => ['diagnostic_procedure' => 'Діагностичні процедури'],
        ]);
        Gate::before(fn (): bool => true);
    }

    private function draft(): array
    {
        $context = $this->compositionFixture();
        $person = $context['person'];
        $legalEntity = $context['legalEntity'];
        $employee = $context['employee'];
        $reference = static fn (string $uuid, string $type): array => ['identifier' => [
            'value' => $uuid, 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]],
        ]];
        $episode = Episode::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'active', 'name' => 'Episode',
            'managing_organization_id' => Identifier::create(['value' => $legalEntity->uuid])->id,
        ]);
        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'finished',
            'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => CodeableConcept::create(['text' => 'Consultation'])->id,
            'episode_id' => Identifier::create(['value' => $episode->uuid])->id,
            'performer_id' => Identifier::create(['value' => $employee->uuid])->id,
        ]);
        $resource = [
            'id' => (string) Str::uuid(), 'status' => 'draft', 'intent' => 'order', 'priority' => 'routine',
            'category' => ['coding' => [['system' => 'eHealth/SNOMED/service_request_categories', 'code' => 'diagnostic_procedure']]],
            'code' => $reference((string) Str::uuid(), 'service'),
            'subject' => $reference($person->uuid, 'patient'),
            'requesterEmployee' => $reference($employee->uuid, 'employee'),
            'requesterLegalEntity' => $reference($legalEntity->uuid, 'legal_entity'),
            'context' => $reference($encounter->uuid, 'encounter'),
            'quantity' => ['value' => 1, 'system' => 'SERVICE_UNIT', 'code' => 'PIECE', 'unit' => 'шт.'],
            'occurrencePeriod' => [
                'start' => CarbonImmutable::now('UTC')->addHour()->toIso8601String(),
                'end' => CarbonImmutable::now('UTC')->addMonths(3)->toIso8601String(),
            ],
            'supportingInfo' => [['displayValue' => 'First saved medical record'], ['displayValue' => 'Second saved medical record']],
            'reasonReference' => [['displayValue' => 'Saved clinical reason']],
            'note' => 'Original note', 'patientInstruction' => 'Keep this instruction',
        ];
        $record = Repository::serviceRequest()->storeResource($resource, $person->id, $employee->id);
        $parameters = ['legalEntity' => $legalEntity, 'person' => $person, 'encounter' => $encounter, 'referral' => $record->id];

        return [$record, $parameters, $resource];
    }

    public function test_existing_text_references_survive_open_edit_and_two_saves(): void
    {
        [$record, $parameters, $original] = $this->draft();
        $component = Livewire::test(EncounterReferralCreate::class, $parameters)
            ->assertSet('encounterReferralForm.supporting_info', $original['supportingInfo'])
            ->set('encounterReferralForm.note', 'Updated once')->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $after = Repository::serviceRequest()->getResource($record->fresh());
        $this->assertSame('Updated once', $after['note']);
        $this->assertSame($original['supportingInfo'], $after['supportingInfo']);
        $this->assertSame($original['reasonReference'], $after['reasonReference']);
        Livewire::test(EncounterReferralCreate::class, $parameters)
            ->assertSet('encounterReferralForm.note', 'Updated once')
            ->set('encounterReferralForm.note', 'Updated twice')->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $after = Repository::serviceRequest()->getResource($record->fresh());
        $this->assertSame('Updated twice', $after['note']);
        $this->assertSame('Keep this instruction', $after['patientInstruction']);
        $this->assertSame($original['supportingInfo'], $after['supportingInfo']);
        $this->assertSame($original['reasonReference'], $after['reasonReference']);
        // Opening the form reads auth methods; saving a local draft must perform no clinical writes.
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET'
            || !str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/authentication_methods'));
    }

    public function test_one_text_reference_can_be_removed_and_saved_without_losing_the_other(): void
    {
        [$record, $parameters, $original] = $this->draft();
        Livewire::test(EncounterReferralCreate::class, $parameters)
            ->call('removeReferralReference', 'supporting_info', '', '', md5(json_encode($original['supportingInfo'][1])))
            ->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $after = Repository::serviceRequest()->getResource($record->fresh());
        $this->assertSame([$original['supportingInfo'][0]], $after['supportingInfo']);
        $this->assertSame($original['reasonReference'], $after['reasonReference']);
        // Opening the form reads auth methods; saving a local draft must perform no clinical writes.
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET'
            || !str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/authentication_methods'));
    }
}
