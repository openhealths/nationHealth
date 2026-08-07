<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Traits\SubmitsEHealthEncounter;
use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\Api\Patient\Encounter as EHealthEncounterApi;
use App\Classes\eHealth\EHealthResponse;
use App\Repositories\MedicalEvents\EncounterRepository;
use App\Models\Person\Person;
use Tests\TestCase;
use Mockery;
use RuntimeException;

class SubmitsEHealthEncounterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function getTraitInstance()
    {
        return new class
        {
            use SubmitsEHealthEncounter;

            public function callWaitForEncounterJobAndSync(
                array $submitResponseData,
                string $patientUuid,
                string $encounterUuid,
                mixed $patientModel
            ): void {
                $this->waitForEncounterJobAndSync($submitResponseData, $patientUuid, $encounterUuid, $patientModel);
            }
        };
    }

    public function test_wait_for_encounter_job_and_sync_success(): void
    {
        // 1. Mock Job API and response
        $mockJobApi = Mockery::mock(Job::class);
        $jobResponse = Mockery::mock(EHealthResponse::class);
        $jobResponse->shouldReceive('getData')
            ->once()
            ->andReturn(['status' => 'processed']);
        $mockJobApi->shouldReceive('getDetails')
            ->once()
            ->with('job-123')
            ->andReturn($jobResponse);
        $this->instance(Job::class, $mockJobApi);

        // 2. Mock Encounter API and response
        $mockEncounterApi = Mockery::mock(EHealthEncounterApi::class);
        $encounterResponse = Mockery::mock(EHealthResponse::class);
        $encounterResponse->shouldReceive('validate')
            ->once()
            ->andReturn(['dummy' => 'data']);
        $mockEncounterApi->shouldReceive('getById')
            ->once()
            ->with('patient-uuid', 'encounter-uuid')
            ->andReturn($encounterResponse);
        $this->instance(EHealthEncounterApi::class, $mockEncounterApi);

        // 3. Mock Repository
        $mockEncounterRepo = Mockery::mock(EncounterRepository::class);
        $patientModel = Mockery::mock(Person::class);
        $mockEncounterRepo->shouldReceive('sync')
            ->once()
            ->with($patientModel, [['dummy' => 'data']]);
        $this->instance(EncounterRepository::class, $mockEncounterRepo);

        // Run
        $instance = $this->getTraitInstance();
        $instance->callWaitForEncounterJobAndSync(
            ['job_id' => 'job-123'],
            'patient-uuid',
            'encounter-uuid',
            $patientModel
        );

        $this->assertTrue(true); // Assert no exception was thrown
    }

    public function test_wait_for_encounter_job_and_sync_missing_job_id(): void
    {
        $instance = $this->getTraitInstance();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Не вдалося отримати Job ID від ЕСОЗ.');

        $instance->callWaitForEncounterJobAndSync(
            [],
            'patient-uuid',
            'encounter-uuid',
            Mockery::mock(Person::class)
        );
    }

    public function test_wait_for_encounter_job_and_sync_failed_status(): void
    {
        // 1. Mock Job API and response returning active/processed status failed
        $mockJobApi = Mockery::mock(Job::class);
        $jobResponse = Mockery::mock(EHealthResponse::class);
        $jobResponse->shouldReceive('getData')
            ->once()
            ->andReturn([
                'status' => 'failed',
                'error' => [
                    'message' => 'Something went wrong',
                ]
            ]);
        $mockJobApi->shouldReceive('getDetails')
            ->once()
            ->with('job-123')
            ->andReturn($jobResponse);
        $this->instance(Job::class, $mockJobApi);

        $instance = $this->getTraitInstance();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Something went wrong');

        $instance->callWaitForEncounterJobAndSync(
            ['job_id' => 'job-123'],
            'patient-uuid',
            'encounter-uuid',
            Mockery::mock(Person::class)
        );
    }
}
