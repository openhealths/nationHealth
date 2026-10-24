<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Livewire\Person\Records\PatientReferrals;
use App\Repositories\MedicalEvents\DeviceRequestRequestRepository;
use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class PatientReferralRegistryUatTest extends TestCase
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

    public function test_referral_encounter_links_use_the_required_route_parameter(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture();
        $encounterId = 987654;
        $this->mock(ServiceRequestRequestRepository::class)->shouldReceive('searchByPersonId')->once()->andReturn([
            ['uuid' => (string) Str::uuid(), 'id' => 123, 'kind' => 'service_request', 'encounterId' => $encounterId, 'basisLabel' => 'Test encounter', 'canSign' => true],
        ]);
        $this->mock(DeviceRequestRequestRepository::class)->shouldReceive('searchByPersonId')->once()->andReturn([]);

        Livewire::test(PatientReferrals::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->assertOk()
            ->assertSee(route('encounter.edit', ['legalEntity' => $legalEntity, 'person' => $person->id, 'encounter' => $encounterId]), false);
    }

}
