<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\MedicalEvents\Sql\Encounter;
use App\Repositories\CarePlanRepository;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use Tests\TestCase;
use Tests\Unit\Repositories\MedicalEvents\EncounterPrimaryDiagnosisTest;

class CarePlanPrimaryAddressesTest extends TestCase
{
    public function test_addresses_contain_only_the_primary_diagnosis(): void
    {
        $encounter = new Encounter(['person_id' => 11]);
        $encounter->setRelation('period', null);
        $encounter->setRelation('diagnoses', new Collection([
            EncounterPrimaryDiagnosisTest::diagnosis('secondary', 'E11'),
            EncounterPrimaryDiagnosisTest::diagnosis('primary', 'E10'),
        ]));
        $repository = Mockery::mock(CarePlanRepository::class)->makePartial();
        $repository->shouldReceive('findEncounter')->with('encounter', 11)->andReturn($encounter);

        $result = $repository->encounterData('encounter', 11);

        $this->assertSame([['coding' => [['system' => 'eHealth/ICD10_AM/condition_codes', 'code' => 'E10']]]], $result['addresses']);
        $this->assertSame([], $repository->encounterData('encounter', 11, 'E11')['addresses']);
        $this->assertSame($result['addresses'], $repository->encounterData('encounter', 11, 'E10')['addresses']);
    }
}
