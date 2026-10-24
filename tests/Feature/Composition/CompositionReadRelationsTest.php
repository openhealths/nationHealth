<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Enums\Composition\CompositionStatus;
use App\Livewire\Person\Records\PatientCompositions;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CompositionReadRelationsTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use UsesCompositionDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'testing') {
            throw new \RuntimeException('Requires isolated PostgreSQL testing database.');
        }
    }

    public function test_registry_and_details_load_all_references_under_strict_lazy_loading(): void
    {
        ['person' => $person, 'employee' => $employee, 'legalEntity' => $legalEntity] = $this->compositionFixture();
        foreach (['First', 'Second'] as $title) {
            $native = $this->native($person, $employee->uuid, $legalEntity->uuid);
            $native['title'] = $title;
            Repository::composition()->store($native, $person);
        }
        $wasStrict = Model::preventsLazyLoading();
        Model::preventLazyLoading();
        try {
            $rows = Repository::composition()->paginateForPatient($person, [], 15);
            $this->assertCount(2, $rows);
            DB::enableQueryLog();
            DB::flushQueryLog();
            foreach ($rows as $row) {
                $this->assertTrue($row->hasReadContext);
                $this->assertSame($person->uuid, $row->toDetail()['subject']['value']);
                $this->assertSame($employee->uuid, $row->authorUuid);
            }
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
            Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
                ->assertOk()->assertSee('First')->assertSee('Second');
        } finally {
            DB::disableQueryLog();
            Model::preventLazyLoading($wasStrict);
        }
    }

    private function native(Person $patient, string $authorUuid, string $custodianUuid): array
    {
        $identifier = fn (string $type, string $uuid): array => $this->compositionReference($type, $uuid)['identifier'];

        return [
            'identifier' => ['value' => (string) Str::uuid()], 'status' => CompositionStatus::PRELIMINARY->value,
            'title' => 'Existing draft', 'date' => '2026-10-21T09:00:00Z',
            'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'TEMP_DISABILITY']]],
            'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => 'SICKNESS']]],
            'subject' => $identifier('person', $patient->uuid), 'author' => $identifier('employee', $authorUuid),
            'custodian' => $identifier('legal_entity', $custodianUuid),
            'encounter' => $identifier('encounter', (string) Str::uuid()),
            'episodeOfCare' => $identifier('episode_of_care', (string) Str::uuid()),
            'section' => ['focus' => $identifier('person', $patient->uuid)],
            'relatesTo' => ['code' => 'replaces', 'targetIdentifier' => $identifier('composition', (string) Str::uuid())],
            'event' => [['period' => ['start' => '2026-10-21T00:00:00Z', 'end' => '2026-10-21T23:59:59Z']]],
        ];
    }
}
