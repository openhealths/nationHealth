<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Livewire\Person\Records\PatientReferrals;
use App\Repositories\MedicalEvents\DeviceRequestRequestRepository;
use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class PatientReferralDateFiltersTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions {
        beginDatabaseTransaction as beginIsolatedTransaction;
    }
    use UsesCompositionDictionaries;

    public function beginDatabaseTransaction(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertContains(DB::connection()->getDatabaseName(), ['testing', 'tv39_1202']);
        $this->beginIsolatedTransaction();
    }

    public static function ranges(): array
    {
        return [
            'start period' => ['filterStartedAt', 'started_at'],
            'end period' => ['filterEndedAt', 'ended_at'],
        ];
    }

    private function filters(string $prefix = '', string $from = '', string $to = ''): array
    {
        $filters = ['status' => null, 'started_at_from' => null, 'started_at_to' => null, 'ended_at_from' => null, 'ended_at_to' => null];
        if ($prefix !== '') {
            $filters[$prefix.'_from'] = $from !== '' ? $from : null;
            $filters[$prefix.'_to'] = $to !== '' ? $to : null;
        }

        return $filters;
    }

    #[DataProvider('ranges')]
    public function testSingleDateRangeClearingAndResetUseIsoRepositoryFilters(string $property, string $prefix): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture();
        $this->fakeSignatureService();
        foreach ([ServiceRequestRequestRepository::class, DeviceRequestRequestRepository::class] as $repository) {
            $mock = $this->mock($repository);
            foreach ([$this->filters(), $this->filters($prefix, '2026-10-01'),
                $this->filters($prefix, '2026-10-01', '2026-10-29'), $this->filters(),
                $this->filters($prefix, '2026-10-01', '2026-10-29'), $this->filters()] as $filters) {
                $mock->shouldReceive('searchByPersonId')->once()->ordered()->with($person->id, $filters)->andReturn([]);
            }
        }

        Livewire::test(PatientReferrals::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->set($property.'From', '2026-10-01')->call('applyFilters')->assertHasNoErrors()
            ->set($property.'To', '2026-10-29')->call('applyFilters')->assertHasNoErrors()
            ->set($property.'From', '')->set($property.'To', '')->call('applyFilters')->assertHasNoErrors()
            ->set($property.'From', '2026-10-01')->set($property.'To', '2026-10-29')->call('applyFilters')
            ->call('resetFilters')->assertSet($property.'From', '')->assertSet($property.'To', '')->assertHasNoErrors();
    }

    public static function invalidDates(): array
    {
        $cases = [];
        foreach (['filterStartedAtFrom', 'filterStartedAtTo', 'filterEndedAtFrom', 'filterEndedAtTo'] as $property) {
            foreach (['2026-10-2026 — 29-10-01', '31.02.2026', '2026-02-31', 'not-a-date'] as $value) {
                $cases[$property.' '.$value] = [$property, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidDates')]
    public function testInvalidDatesShowFieldErrorsBeforeEitherRepositoryQuery(string $property, string $value): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture();
        $this->fakeSignatureService();
        foreach ([ServiceRequestRequestRepository::class, DeviceRequestRequestRepository::class] as $repository) {
            $mock = $this->mock($repository);
            $mock->shouldReceive('searchByPersonId')->twice()->with($person->id, $this->filters())->andReturn([]);
        }

        Livewire::test(PatientReferrals::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->set($property, $value)->call('applyFilters')->assertHasErrors([$property => 'date_format'])
            ->call('loadReferrals')->assertHasErrors([$property => 'date_format'])
            ->call('resetFilters')->assertHasNoErrors()->assertSet($property, '');
    }
}
