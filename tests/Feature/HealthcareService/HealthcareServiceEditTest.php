<?php

declare(strict_types=1);

namespace Tests\Feature\HealthcareService;

use App\Enums\HealthcareService\Status;
use App\Enums\User\Role;
use App\Livewire\Division\HealthcareService\HealthcareServiceEdit;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use Illuminate\Foundation\Testing\WithFaker;
use Livewire\Livewire;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\Concerns\RefreshInstalledDatabase;
use Tests\TestCase;

/**
 * Sending a draft healthcare service to eHealth from its edit page.
 *
 * Only eHealth is faked. The test goes in through the page and checks the request sent to eHealth and what is stored.
 */
class HealthcareServiceEditTest extends TestCase
{
    use MocksBasicDictionaries;
    use PreparesHealthcareServiceTests;
    use RefreshInstalledDatabase;
    use WithFaker;

    /**
     * The draft goes to eHealth with what it holds, and becomes the registered service eHealth returns: the same record
     * gets the eHealth ID and status, and no second record is stored.
     *
     * @return void
     */
    public function test_sent_draft_becomes_the_registered_service(): void
    {
        $legalEntity = LegalEntity::factory()->create();
        $division = Division::factory()->for($legalEntity)->create();
        $draft = HealthcareService::factory()->for($division)->draft()->create([
            'speciality_type' => 'FAMILY_DOCTOR',
            'providing_condition' => 'OUTPATIENT',
        ]);
        $uuid = $this->faker->uuid();

        $eHealth = HealthcareServiceApiFake::respond(201, [
            'data' => $this->ehealthHealthcareService($legalEntity, $division, ['id' => $uuid]),
        ]);

        Livewire::actingAs($this->userWithRole($legalEntity, Role::OWNER))
            ->test(HealthcareServiceEdit::class, [
                'legalEntity' => $legalEntity,
                'division' => $division,
                'healthcareService' => $draft,
            ])
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirectToRoute('healthcare-services.index', [$legalEntity, 'division' => $division->id]);

        $this->assertCount(1, $eHealth->receivedRequests);
        $request = $eHealth->receivedRequests[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame('MSP', $request['category']['coding'][0]['code']);
        $this->assertSame('FAMILY_DOCTOR', $request['speciality_type']);
        $this->assertSame('OUTPATIENT', $request['providing_condition']);

        $this->assertDatabaseCount('healthcare_services', 1);
        $draft->refresh();
        $this->assertSame($uuid, $draft->uuid);
        $this->assertSame(Status::ACTIVE, $draft->status);
        $this->assertTrue($draft->isActive);
    }

    /**
     * Cache the dictionaries the page uses.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockBasicDictionaries();
    }
}
