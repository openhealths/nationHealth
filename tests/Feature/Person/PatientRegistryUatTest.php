<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Livewire\Person\Records\PatientCompositions;
use App\Models\MedicalEvents\Sql\Composition;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
        unset($first['subject']);
        $second = $this->compositionRow($person->uuid, $employee->uuid, $legalEntity->uuid);
        config(['ehealth.api.page_size' => 1]);
        $responses = Http::sequence();
        for ($run = 0; $run < 2; $run++) {
            $responses->push($this->eHealthBody([$first]))
                ->push($this->eHealthBody([$second]))
                ->push($this->eHealthBody([]));
        }
        $this->fakeEHealth(['*' => $responses]);

        $component = Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->set('filterStatus', 'FINAL')
            ->set('filterType', 'NEWBORN')
            ->set('filterSectionFocusUuid', (string) Str::uuid())
            ->set('filterEncounterId', (string) Str::uuid())
            ->call('sync')
            ->assertSee(__('compositions.messages.sync_success'));

        $component->call('sync')->assertSee(__('compositions.messages.sync_success'));

        $this->assertSame(2, Composition::wherePersonId($person->id)->count());
        Http::assertSentCount(6);
        Http::assertSent(static fn ($request): bool => $request['subject'] === $person->uuid && (int) ($request['offset'] ?? -1) === 1);
        Http::assertNotSent(static fn ($request): bool => isset($request['focus']) || isset($request['status']) || isset($request['type']) || isset($request['encounter']));
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

        Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->assertDontSee('wire:click="sync"', false)
            ->call('sync')
            ->assertStatus(404);

        Http::assertNothingSent();
    }

    private function compositionRow(string $subjectUuid, string $authorUuid, string $legalEntityUuid): array
    {
        return [
            'identifier' => ['value' => (string) Str::uuid()], 'title' => 'Synthetic conclusion',
            'status' => 'PRELIMINARY', 'date' => '2026-10-22T09:00:00Z',
            'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'TEMP_DISABILITY']]],
            'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => 'SICKNESS']]],
            'subject' => $this->compositionReference('person', $subjectUuid)['identifier'],
            'author' => $this->compositionReference('employee', $authorUuid)['identifier'],
            'custodian' => $this->compositionReference('legal_entity', $legalEntityUuid)['identifier'],
        ];
    }
}
