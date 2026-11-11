<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Classes\eHealth\Api\Patient\DeviceRequest as PatientDeviceRequestApi;
use App\Classes\eHealth\Api\Patient\ServiceRequest as PatientServiceRequestApi;
use App\Livewire\Person\Records\PatientReferrals;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
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
        $this->assertContains(DB::connection()->getDatabaseName(), ['testing', 'tv39_1202']);
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
        $this->mock(ServiceRequestRequestRepository::class)->shouldReceive('completionStates')->andReturn([])->getMock()->shouldReceive('searchByPersonId')->once()->andReturn([
            ['uuid' => (string) Str::uuid(), 'id' => 123, 'kind' => 'service_request', 'status' => 'draft', 'encounterId' => $encounterId, 'basisLabel' => 'Test encounter', 'canSign' => true],
        ]);
        $this->mock(DeviceRequestRequestRepository::class)->shouldReceive('searchByPersonId')->once()->andReturn([]);

        Livewire::test(PatientReferrals::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->assertOk()
            ->assertSee(route('persons.encounters.edit', ['legalEntity' => $legalEntity, 'person' => $person->id, 'encounter' => $encounterId]), false);
    }

    public function test_device_cancel_uses_the_device_module_even_with_a_service_kind_argument(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity, 'employee' => $employee] = $this->compositionFixture();
        $device = DeviceRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'personId' => $person->id, 'employeeId' => $employee->id, 'deviceId' => 'synthetic-device', 'quantity' => 1, 'status' => 'active',
        ]);
        $this->mock(PatientServiceRequestApi::class)->shouldNotReceive('cancel', 'getById');
        $this->mock(PatientDeviceRequestApi::class)->shouldNotReceive('cancel');
        Livewire::test(PatientReferrals::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->call('cancelReferral', $device->uuid, 'service_request')
            ->assertRedirect(route('persons.device-requests', ['legalEntity' => $legalEntity, 'person' => $person]))
            ->assertSet('showSignatureModal', false);
    }

}
