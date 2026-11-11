<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Livewire\Person\Records\PatientCompositions;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class PatientRegistryUatTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions {
        beginDatabaseTransaction as beginIsolatedTransaction;
    }
    use UsesCompositionDictionaries;

    public function beginDatabaseTransaction(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->beginIsolatedTransaction();
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('uk');
        Http::preventStrayRequests();
    }

    public function test_composition_registry_has_translated_controls_and_explicit_sync(): void
    {
        ['person' => $person, 'employee' => $employee, 'legalEntity' => $legalEntity] = $this->compositionFixture();
        Repository::composition()->store($this->compositionRow($person->uuid, $employee->uuid, $legalEntity->uuid), $person);

        Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->assertOk()
            ->assertSee('Скинути всі фільтри')
            ->assertSee('Переглянути деталі')
            ->assertSee(__('forms.synchronise_with_eHealth'))
            ->assertSee('wire:click="sync"', false)
            ->assertDontSee('patients.reset_filters')
            ->assertDontSee('patients.view_details');

        Http::assertNothingSent();
    }

    public function test_composition_sync_is_patient_wide_paginated_and_repeatable_without_duplicates(): void
    {
        ['person' => $person, 'employee' => $employee, 'legalEntity' => $legalEntity] = $this->compositionFixture();
        $first = $this->compositionRow($person->uuid, $employee->uuid, $legalEntity->uuid);
        $second = $this->compositionRow($person->uuid, $employee->uuid, $legalEntity->uuid);
        config(['ehealth.api.page_size' => 1]);
        // Published native summaries have no author or subject. The patient query and
        // each result's encounter/episode supply scope; validated detail supplies the author.
        $summaries = [];
        foreach ([$first, $second] as $document) {
            $summaries[] = array_replace(Arr::only($document, ['identifier', 'status', 'title', 'type', 'date', 'encounter']), [
                'title' => 'Search summary',
                'episodeOfCare' => $this->compositionReference('episode', (string) Str::uuid())['identifier'],
            ]);
        }
        $responses = Http::sequence();
        for ($run = 0; $run < 2; $run++) {
            $responses->push(['data' => [$summaries[0]]])
                ->push(['data' => [$summaries[1]]])
                ->push(['data' => []]);
        }
        $this->fakeEHealth([
            '*searchComposition*' => $responses,
            '*/'.$person->uuid.'/composition/'.$first['identifier']['value'].'/episode/'.$summaries[0]['episodeOfCare']['value'].'/encounter/'.$first['encounter']['value'] => Http::response(['data' => $first]),
            '*/'.$person->uuid.'/composition/'.$second['identifier']['value'].'/episode/'.$summaries[1]['episodeOfCare']['value'].'/encounter/'.$second['encounter']['value'] => Http::response(['data' => $second]),
        ]);

        $component = Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->set('filterStatus', 'FINAL')
            ->set('filterType', 'NEWBORN')
            ->set('filterSectionFocusUuid', (string) Str::uuid())
            ->set('filterEncounterId', (string) Str::uuid())
            ->call('sync')
            ->assertSee(__('compositions.messages.sync_success'));

        $component->call('sync')->assertSee(__('compositions.messages.sync_success'));

        $this->assertSame(2, Composition::wherePersonId($person->id)->count());
        $this->assertSame($first['title'], Composition::whereUuid($first['identifier']['value'])->firstOrFail()->title);
        $this->assertSame($second['title'], Composition::whereUuid($second['identifier']['value'])->firstOrFail()->title);
        $this->assertSame($employee->uuid, Repository::composition()->findForPatient($person, $first['identifier']['value'])->authorUuid);
        $this->assertSame($employee->uuid, Repository::composition()->findForPatient($person, $second['identifier']['value'])->authorUuid);
        Http::assertSentCount(10);
        $this->assertSame([0, 1, 2, 0, 1, 2], Http::recorded(
            static fn ($request): bool => str_contains($request->url(), '/searchComposition')
        )->map(static fn (array $exchange): int => (int) ($exchange[0]['offset'] ?? 0))->values()->all());
        Http::assertSent(static fn ($request): bool => ($request['subject'] ?? null) === $person->uuid && (int) ($request['offset'] ?? -1) === 1);
        Http::assertNotSent(static fn ($request): bool => isset($request['focus']) || isset($request['status']) || isset($request['type']) || isset($request['encounter']) || isset($request['episodeOfCare']));
        $component->call('resetFilters')->assertDontSee(__('compositions.messages.sync_success'));
    }

    public function test_composition_search_keeps_filters_and_sync_rejects_other_patient_rows(): void
    {
        ['person' => $person, 'employee' => $employee, 'legalEntity' => $legalEntity] = $this->compositionFixture();
        $focusUuid = (string) Str::uuid();
        $other = $this->compositionRow((string) Str::uuid(), $employee->uuid, $legalEntity->uuid);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([$other]))]);

        Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->set('filterStatus', 'FINAL')
            ->set('filterSectionFocusUuid', $focusUuid)
            ->call('search')
            ->call('sync')
            ->assertOk();

        Http::assertSent(static fn ($request): bool => ($request['focus'] ?? null) === $focusUuid && ($request['status'] ?? null) === 'FINAL' && !isset($request['subject']));
        $this->assertSame(0, Composition::whereUuid($other['identifier']['value'])->count());
    }

    public function test_composition_sync_failure_does_not_show_success(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture();
        $this->fakeEHealth(['*' => Http::response(['error' => ['message' => 'Temporary failure']], 503)]);

        Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->call('sync')
            ->assertSee('Temporary failure')
            ->assertDontSee(__('compositions.messages.sync_success'));
    }

    public function test_composition_sync_requires_search_permission_before_any_http(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: []);
        Bus::fake();
        $compositionCount = Composition::count();
        $operationCount = CompositionOperation::count();

        Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->assertDontSee('wire:click="sync"', false)
            ->call('sync')
            ->assertOk()
            ->assertSee(__('compositions.errors.search_not_allowed'))
            ->assertDontSee(__('compositions.messages.sync_success'));

        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        $this->assertSame($compositionCount, Composition::count());
        $this->assertSame($operationCount, CompositionOperation::count());
    }

    private function compositionRow(string $subjectUuid, string $authorUuid, string $legalEntityUuid): array
    {
        return $this->nativeCompositionDocument([
            'identifier' => ['value' => (string) Str::uuid()],
            'title' => 'Synthetic conclusion',
            'subject' => $this->compositionReference('person', $subjectUuid)['identifier'],
            'section' => ['focus' => $this->compositionReference('person', $subjectUuid)['identifier']],
            'author' => $this->compositionReference('employee', $authorUuid)['identifier'],
            'custodian' => $this->compositionReference('legal_entity', $legalEntityUuid)['identifier'],
            'encounter' => $this->compositionReference('encounter', (string) Str::uuid())['identifier'],
        ]);
    }
}
