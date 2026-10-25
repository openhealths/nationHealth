<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\ServiceRequest\Model as ServiceRequestModelData;
use App\Dto\DeviceRequest\Model as DeviceRequestModelData;
use App\Dto\FormCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class ServiceRequestModelDataTest extends TestCase
{
    public static function remoteCases(): iterable
    {
        foreach (['service-request' => ServiceRequestModelData::class, 'device-request' => DeviceRequestModelData::class] as $resource => $target) {
            $inputs = require __DIR__.'/../../Fixtures/Mapping/'.$resource.'-remote-inputs.php';
            $expected = json_decode(file_get_contents(__DIR__.'/../../Fixtures/Mapping/'.$resource.'-remote-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($inputs as $name => $input) {
                yield $resource.' '.$name => [$input, $expected[$name], $target];
            }
        }
    }

    #[DataProvider('remoteCases')]
    public function test_remote_patch_matches_the_pre_refactor_contract(array $input, array $expected, string $target): void
    {
        Http::preventStrayRequests();
        DB::listen(static fn () => throw new \LogicException('Mapping must not query the database.'));
        $mapped = app(ObjectMapperInterface::class)->map((object) $input, $target);

        $this->assertEquals($expected, $mapped->toSyncPatch());
        if (isset($expected['quantity'])) {
            $this->assertSame($expected['quantity'], $mapped->quantity);
        }
    }

    public function test_the_same_target_maps_local_validated_form_fields(): void
    {
        $form = new FormCollection([
            'started_at' => '30.09.2026', 'ended_at' => '01.10.2026',
            'quantity' => 1.5, 'service_id' => 'service-local', 'program_id' => 'program-local',
            'category' => 'procedure', 'intent' => 'order', 'priority' => 'routine',
            'patient_instruction' => '', 'note' => 'local note', 'inform_with' => 'auth-local',
            'supporting_info' => [['type' => 'condition', 'uuid' => 'local-condition']],
            'reason_reference' => [], 'employee_id' => 999, 'context_uuid' => 'untrusted',
            'uuid' => 'untrusted', 'status' => 'active', 'request_number' => 'untrusted',
        ]);

        $mapped = app(ObjectMapperInterface::class)->map($form, ServiceRequestModelData::class)->toArray();
        $this->assertSame('2026-09-30', $mapped['started_at']);
        $this->assertSame('2026-10-01', $mapped['ended_at']);
        foreach (['quantity', 'service_id', 'program_id', 'category', 'intent', 'priority', 'patient_instruction', 'note', 'inform_with', 'supporting_info', 'reason_reference'] as $field) {
            $this->assertSame($form[$field], $mapped[$field]);
        }
        $this->assertArrayNotHasKey('employee_id', $mapped);
        $this->assertArrayNotHasKey('context_uuid', $mapped);
        $this->assertNull($mapped['uuid']);
        $this->assertNull($mapped['status']);
        $this->assertNull($mapped['request_number']);
    }

    public function test_partial_remote_patch_retains_local_values_but_applies_zero(): void
    {
        $local = ['employee_id' => 17, 'context_uuid' => 'encounter-local', 'service_id' => 'service-local', 'quantity' => 3, 'note' => 'keep', 'supporting_info' => [['uuid' => 'keep', 'type' => 'condition']]];
        $patch = app(ObjectMapperInterface::class)->map((object) ['status' => 'active', 'quantity' => ['value' => 0], 'note' => null, 'supporting_info' => []], ServiceRequestModelData::class)->toSyncPatch();

        $this->assertSame(array_replace($local, ['status' => 'active', 'quantity' => 0]), array_replace($local, $patch));
    }
}
