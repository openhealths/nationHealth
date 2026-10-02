<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionType;
use App\Enums\User\Role;
use App\Exceptions\MedicalEvents\CompositionGuardException;
use App\Livewire\Composition\CompositionCreate;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\CompositionRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Birth conclusion (МВН) — TV 3.8.1.
 *
 * Focuses on the preperson/newborn path that hits the local compositions table before
 * any eHealth write (duplicate guard) and on FHIR-shaped local persistence.
 */
class CompositionCreateNewbornTest extends TestCase
{
    use CompositionTestFixtures;
    use RefreshCompositionDatabase;

    public function test_compositions_table_exists_after_install_migrations(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('compositions'),
            'artisan install/update must create compositions (FHIR shape).'
        );
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('compositions', 'type_id'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('compositions', 'subject_id'));
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('compositions', 'subject_uuid'),
            'Scalar subject_uuid must not be the canonical column.'
        );
    }

    public function test_local_duplicate_guard_queries_subject_identifier_not_a_missing_column(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $mother] = $this->compositionFixture(
            LegalEntity::TYPE_OUTPATIENT,
            Role::SPECIALIST,
            ['composition:create', 'composition:read']
        );

        $newborn = $this->compositionPreperson();
        $this->storeActiveBirthConclusion($newborn);

        $component = Livewire::test(CompositionCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $newborn,
        ])
            ->set('form.prepersonUuid', $newborn->uuid)
            ->set('form.personUuid', $mother->uuid);

        $this->assertTrue(
            $component->get('hasExistingActiveBirthConclusion'),
            'An active local birth conclusion for this preperson must be detected via subject.value.'
        );
    }

    public function test_submission_is_blocked_when_a_local_birth_conclusion_already_exists(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $mother] = $this->compositionFixture();

        $newborn = $this->compositionPreperson();
        $this->storeActiveBirthConclusion($newborn);

        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]), 200)]);

        $component = Livewire::test(CompositionCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $newborn,
        ])
            ->set('form.prepersonUuid', $newborn->uuid)
            ->set('form.personUuid', $mother->uuid);

        $method = new ReflectionMethod(CompositionCreate::class, 'assertSubmissionAllowed');
        $method->setAccessible(true);

        $this->expectException(CompositionGuardException::class);
        $this->expectExceptionMessage(__('compositions.errors.newborn_duplicate'));

        $method->invoke($component->instance());
    }

    public function test_store_local_persists_a_birth_conclusion_in_fhir_shape(): void
    {
        $newborn = $this->compositionPreperson();
        $motherUuid = (string) Str::uuid();
        $compositionUuid = (string) Str::uuid();
        $encounterUuid = (string) Str::uuid();

        $stored = app(CompositionRepository::class)->store(
            [
                'identifier' => ['value' => $compositionUuid],
                'status' => 'final',
                'title' => 'МВН-1',
                'date' => '2026-08-13T10:00:00Z',
                'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'NEWBORN']]],
                'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => 'LIVE_BIRTH']]],
                'subject' => [
                    'value' => $newborn->uuid,
                    'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'preperson']]],
                ],
                'encounter' => [
                    'value' => $encounterUuid,
                    'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'encounter']]],
                ],
                'section' => [
                    'focus' => [
                        'value' => $motherUuid,
                        'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'person']]],
                    ],
                ],
                'event' => [[
                    'period' => ['start' => '2026-08-13T00:00:01Z', 'end' => null],
                ]],
                'extension' => [
                    ['valueCode' => 'NEWBORN_BIRTH_DATE', 'valueDate' => '2026-08-13'],
                    ['valueCode' => 'NEWBORN_SEX', 'valueString' => 'MALE'],
                ],
            ],
            $newborn
        );

        $this->assertNotNull($stored);
        $this->assertSame($compositionUuid, $stored->uuid);
        $this->assertSame($newborn->id, $stored->preperson_id);
        $this->assertSame(CompositionType::NEWBORN, $stored->type);
        $this->assertSame($newborn->uuid, $stored->subjectUuid);
        $this->assertSame($motherUuid, $stored->sectionFocusUuid);
        $this->assertSame($encounterUuid, $stored->encounterUuid);
        $this->assertNotNull($stored->type_id);
        $this->assertNotNull($stored->subject_id);
        $this->assertDatabaseHas('identifiers', ['id' => $stored->subject_id, 'value' => $newborn->uuid]);
    }

    public function test_entered_in_error_birth_conclusion_does_not_count_as_a_local_duplicate(): void
    {
        ['legalEntity' => $legalEntity] = $this->compositionFixture();
        $newborn = $this->compositionPreperson();
        $this->storeActiveBirthConclusion($newborn, CompositionStatus::ENTERED_IN_ERROR);

        $component = Livewire::test(CompositionCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $newborn,
        ])->set('form.prepersonUuid', $newborn->uuid);

        $this->assertFalse($component->get('hasExistingActiveBirthConclusion'));
    }

    public function test_encounter_cannot_be_selected_before_the_mother_is_identified(): void
    {
        ['legalEntity' => $legalEntity, 'employee' => $employee, 'person' => $mother] = $this->compositionFixture();
        $newborn = $this->compositionPreperson();
        $encounterUuid = (string) Str::uuid();

        $this->fakeEHealth([
            '*authentication_methods*' => Http::response($this->eHealthBody([]), 200),
            '*' => Http::response($this->eHealthBody([
                $this->compositionEncounter($encounterUuid, $employee->uuid),
            ]), 200),
        ]);

        $component = Livewire::test(CompositionCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $newborn,
        ]);

        $component->call('selectEncounter', $encounterUuid)
            ->assertSet('step', CompositionCreate::STEP_ENCOUNTER)
            ->assertSet('form.encounterUuid', '');

        $component->call('selectMother', $mother->id)
            ->call('selectEncounter', $encounterUuid)
            ->assertSet('form.personUuid', $mother->uuid)
            ->assertSet('form.encounterUuid', $encounterUuid)
            ->assertSet('step', CompositionCreate::STEP_AUTH_METHOD);
    }

    public function test_skipping_auth_without_a_mother_returns_to_the_encounter_step(): void
    {
        ['legalEntity' => $legalEntity] = $this->compositionFixture();
        $newborn = $this->compositionPreperson();

        Livewire::test(CompositionCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $newborn,
        ])
            ->set('step', CompositionCreate::STEP_AUTH_METHOD)
            ->call('skipAuthMethod')
            ->assertSet('step', CompositionCreate::STEP_ENCOUNTER)
            ->assertSet('form.informWithUuid', null);
    }

    public function test_local_encounter_id_in_the_query_resolves_to_its_uuid(): void
    {
        ['legalEntity' => $legalEntity, 'employee' => $employee, 'person' => $mother] = $this->compositionFixture();
        $newborn = $this->compositionPreperson();
        $encounterUuid = (string) Str::uuid();

        $episode = Identifier::create(['value' => (string) Str::uuid()]);
        $class = \App\Models\MedicalEvents\Sql\Coding::create([
            'system' => 'eHealth/encounter_classes',
            'code' => 'INPATIENT',
        ]);
        $type = CodeableConcept::create(['text' => null]);
        $type->coding()->create([
            'system' => 'eHealth/encounter_types',
            'code' => 'AMB',
        ]);

        $local = \App\Models\MedicalEvents\Sql\Encounter::create([
            'uuid' => $encounterUuid,
            'status' => 'finished',
            'preperson_id' => $newborn->id,
            'episode_id' => $episode->id,
            'class_id' => $class->id,
            'type_id' => $type->id,
        ]);

        $this->fakeEHealth([
            '*authentication_methods*' => Http::response($this->eHealthBody([]), 200),
            '*' => Http::response($this->eHealthBody([
                $this->compositionEncounter($encounterUuid, $employee->uuid),
            ]), 200),
        ]);

        Livewire::withQueryParams(['encounter' => (string) $local->id])
            ->test(CompositionCreate::class, [
                'legalEntity' => $legalEntity,
                'preperson' => $newborn,
            ])
            ->call('selectMother', $mother->id)
            ->assertSet('form.encounterUuid', $encounterUuid)
            ->assertSet('step', CompositionCreate::STEP_AUTH_METHOD);
    }

    public function test_birth_conclusion_uses_the_selected_encounters_author_instead_of_the_first_employee(): void
    {
        [$component, $author, $encounterUuid] = $this->birthConclusionWithSecondAuthor();

        $component->assertSee('Автор взаємодії')
            ->assertSee('Лікар-акушер-гінеколог')
            ->assertSet('selectedEncounter.authorUuid', $author->uuid)
            ->call('submitComposition')
            ->assertHasNoErrors()
            ->assertSet('asyncJobId', 'birth-job');

        Http::assertSent(static fn ($request): bool =>
            $request->method() === 'POST'
            && str_ends_with($request->url(), '/composition')
            && data_get($request->data(), 'author.value') === $author->uuid
            && data_get($request->data(), 'encounter.value') === $encounterUuid
            && data_get($request->data(), 'type.coding.0.code') === 'NEWBORN');
    }

    public function test_birth_job_stores_the_newborn_fields_and_selected_author_without_a_raw_snapshot(): void
    {
        [$component, $author, $encounterUuid] = $this->birthConclusionWithSecondAuthor();
        $component->call('submitComposition')->assertHasNoErrors();
        $compositionUuid = (string) Str::uuid();
        $newbornUuid = $component->get('form.prepersonUuid');
        $this->fakeEHealth([
            '*/composition/job/*' => Http::response(['data' => ['status' => 'DONE',
                'links' => [['href' => "/composition/$compositionUuid"]]]]),
            '*integrationData*' => Http::response(['data' => []]),
            '*' => Http::response(['data' => [
                'identifier' => ['value' => $compositionUuid], 'status' => 'PRELIMINARY', 'title' => 'МВН',
                'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'NEWBORN']]],
                'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => 'LIVE_BIRTH']]],
                'author' => ['value' => $author->uuid], 'encounter' => ['value' => $encounterUuid],
                'subject' => ['value' => $newbornUuid],
                'extension' => [
                    ['valueCode' => 'NEWBORN_BIRTH_DATE', 'valueDate' => now()->format('Y-m-d')],
                    ['valueCode' => 'NEWBORN_SEX', 'valueString' => 'MALE'],
                ],
            ]]),
        ]);
        $component->call('pollAsyncJob')->assertSet('step', CompositionCreate::STEP_REVIEW);
        $composition = Composition::whereUuid($compositionUuid)->firstOrFail();
        $this->assertSame(CompositionType::NEWBORN, $composition->type);
        $this->assertSame($author->uuid, $composition->authorUuid);
        $this->assertSame($newbornUuid, $composition->preperson->uuid);
        $this->assertNull($composition->personId);
        $this->assertSame(now()->format('Y-m-d'), $composition->newbornBirthDate->format('Y-m-d'));
        $this->assertSame('MALE', $composition->newbornSex);
        $this->assertSame('DONE', $composition->latestOperation->status);
        $this->assertArrayNotHasKey('data', $composition->getAttributes());
    }

    public function test_birth_conclusion_rejects_a_changed_encounter_after_selection(): void
    {
        [$component] = $this->birthConclusionWithSecondAuthor();

        $component->set('form.encounterUuid', (string) Str::uuid())
            ->call('submitComposition')
            ->assertSet('asyncJobId', null);

        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
    }

    public function test_birth_conclusion_rechecks_the_selected_authors_eligibility_before_submission(): void
    {
        [$component, $author] = $this->birthConclusionWithSecondAuthor();
        $author->update(['is_active' => false]);

        $component->call('submitComposition')->assertSet('asyncJobId', null);

        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
    }

    private function birthConclusionWithSecondAuthor(): array
    {
        ['legalEntity' => $legalEntity, 'employee' => $firstAuthor, 'person' => $mother, 'user' => $user]
            = $this->compositionFixture();
        $author = $firstAuthor->replicate();
        $author->uuid = (string) Str::uuid();
        $author->position = 'P6';
        $author->save();
        $user->employees()->attach($author->id);
        $this->assertSame($firstAuthor->uuid, $user->getCompositionAuthorEmployee(CompositionType::NEWBORN)->uuid);

        $ineligibleAuthor = $firstAuthor->replicate();
        $ineligibleAuthor->uuid = (string) Str::uuid();
        $ineligibleAuthor->position = 'P56';
        $ineligibleAuthor->save();

        $newborn = $this->compositionPreperson();
        $encounterUuid = (string) Str::uuid();
        $this->fakeEHealth([
            '*/encounters*' => Http::response($this->eHealthBody([
                $this->compositionEncounter($encounterUuid, $author->uuid),
                $this->compositionEncounter((string) Str::uuid(), $ineligibleAuthor->uuid),
                $this->compositionEncounter((string) Str::uuid(), (string) Str::uuid()),
            ])),
            '*/composition' => Http::response(['data' => ['id' => 'birth-job', 'status' => 'PENDING']]),
            '*' => Http::response($this->eHealthBody([])),
        ]);

        $component = Livewire::test(CompositionCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $newborn,
        ])->call('selectMother', $mother->id);

        $this->assertSame([$encounterUuid], $component->get('availableEncounters')->pluck('uuid')->all());

        $component->call('selectEncounter', $encounterUuid)
            ->call('skipAuthMethod')
            ->set('form.newbornBirthDate', now()->format(config('app.date_format')))
            ->set('form.newbornSex', 'MALE');

        return [$component, $author, $encounterUuid];
    }

    private function storeActiveBirthConclusion(
        Preperson $newborn,
        CompositionStatus $status = CompositionStatus::FINAL
    ): Composition {
        $typeConcept = CodeableConcept::create(['text' => null]);
        $typeConcept->coding()->create([
            'system' => 'COMPOSITION_TYPES',
            'code' => CompositionType::NEWBORN->value,
        ]);

        $subject = Identifier::create(['value' => $newborn->uuid]);

        return Composition::create([
            'uuid' => (string) Str::uuid(),
            'preperson_id' => $newborn->id,
            'status' => $status->value,
            'title' => 'МВН',
            'type_id' => $typeConcept->id,
            'subject_id' => $subject->id,
            'date' => now(),
        ]);
    }
}
