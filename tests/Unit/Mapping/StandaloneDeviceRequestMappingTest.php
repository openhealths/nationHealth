<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\DeviceRequest\DraftResult;
use App\Dto\DeviceRequest\EhealthDraft;
use App\Dto\DeviceRequest\EhealthDraftPrequalify;
use App\Livewire\DeviceRequest\DeviceRequestForm;
use Illuminate\Support\Facades\DB;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class StandaloneDeviceRequestMappingTest extends TestCase
{
    public function test_the_form_maps_directly_to_the_existing_standalone_wire_contract_without_queries(): void
    {
        $form = new DeviceRequestForm();
        $form->patientId = 'person-id';
        $form->medicalProgram = 'program-id';
        $form->deviceType = 'device_CODE';
        $form->quantity = '2.75';
        $form->form['password'] = 'secret';
        $form->draftContent = ['unknown' => 'ui-state'];
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $mapper = app(ObjectMapperInterface::class);
        $create = $mapper->map($form, EhealthDraft::class)->toArray();
        $prequalify = $mapper->map($form, EhealthDraftPrequalify::class)->toArray();

        // Literal baseline from DeviceRequestForm at 168b55db; do not derive expectations from the DTO.
        $expected = ['person_id' => 'person-id', 'program' => 'program-id', 'code' => ['coding' => [['system' => 'eHealth/SNOMED', 'code' => 'device_CODE']]], 'quantity' => 2];
        $this->assertSame($expected, $create);
        $this->assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($create, JSON_THROW_ON_ERROR));
        $this->assertSame(['person_id' => 'person-id', 'programs' => [['id' => 'program-id']]], $prequalify);
        $this->assertSame([], $queries);
    }

    public function test_resolved_clinical_document_has_priority_and_is_not_reconstructed(): void
    {
        $raw = ['id' => 'accepted-id', 'status' => 'NEW', 'unknown_extension' => ['list' => [], 'zero' => 0]];
        $result = new DraftResult(['job_id' => 'job-id'], ['data' => ['device_request_request' => $raw]]);

        $this->assertSame('accepted-id', $result->uuid());
        $this->assertSame($raw, $result->document());
    }

    public function test_flat_resolved_clinical_status_is_distinct_from_job_metadata(): void
    {
        $raw = ['id' => 'accepted-id', 'status' => 'NEW', 'unknown_extension' => false];
        $result = new DraftResult(['job_id' => 'job-id'], $raw);

        $this->assertSame($raw, $result->document());
        $this->assertSame('accepted-id', $result->uuid());
    }

    public function test_job_metadata_is_not_exposed_as_a_signable_document(): void
    {
        $result = new DraftResult(['job_id' => 'create-job'], ['id' => 'device-id', 'status' => 'processed']);

        $this->assertSame('device-id', $result->uuid());
        $this->assertSame([], $result->document());
    }
}
