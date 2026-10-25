<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\MedicationRequest\Model as ModelData;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class MedicationRequestModelDataTest extends TestCase
{
    public function test_preloaded_model_and_dosage_map_without_sql_and_preserve_fallback_signing_bytes(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../../Fixtures/Mapping/medication-request-outbound.json'), true, flags: JSON_THROW_ON_ERROR)['full dosage'];
        $source = new \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest([
            'created_at' => $fixture['data']['created_at'], 'started_at' => $fixture['data']['started_at'],
            'ended_at' => $fixture['data']['ended_at'], 'medication_id' => $fixture['data']['medication_id'],
            'medication_qty' => $fixture['data']['medication_qty'], 'medication_program_id' => $fixture['data']['medication_program_id'],
            'note' => $fixture['data']['note'], 'container_dosage' => $fixture['data']['container_dosage'],
            'inform_with' => $fixture['data']['inform_with'],
        ]);
        $source->setRelation('intent', null)->setRelation('category', null);
        $source->created_at = $fixture['data']['created_at'];
        $instruction = $fixture['data']['dosage_instructions'][0];
        $instruction['dose_and_rate'] = json_encode($instruction['dose_and_rate'], JSON_THROW_ON_ERROR);
        $source->setRelation('dosageInstructions', collect([new \App\Models\MedicalEvents\Sql\Medications\DosageInstruction($instruction)]));
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $fields = app(ObjectMapperInterface::class)->map($source, ModelData::class)->toSigningFields();
        $fields['based_on_uuid'] = $fixture['data']['based_on_uuid'];
        $payload = app(\Tests\Support\MedicationRequestPayloads::class)->signedContent(
            $fields,
            $fixture['uuids'],
            \Carbon\CarbonImmutable::parse('2026-10-05 12:15:30', 'Europe/Kyiv'),
            $fixture['carePlanUuid']
        );

        $this->assertSame(json_encode($fixture['signedContent'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame([], $queries);
    }

    public static function metadata(): iterable
    {
        yield 'partial does not clear' => [['status' => 'ACTIVE', 'medication_qty' => null], ['status' => 'ACTIVE']];
        yield 'zero and empty are supplied values' => [['medication_qty' => 0, 'request_number' => ''], ['request_number' => '', 'medication_qty' => 0]];
        yield 'null primary falls back to aliases' => [[
            'request_number' => null, 'requisition' => 'RX-1', 'medication_id' => null,
            'medication_info' => ['id' => 'medication-id'], 'medical_program' => ['id' => 'program-id'],
        ], ['request_number' => 'RX-1', 'medication_id' => 'medication-id', 'medication_program_id' => 'program-id']];
        yield 'primary wins and unrelated data is excluded' => [[
            'medication_id' => 'primary', 'medication_info' => ['id' => 'alias'],
            'medical_program_id' => 'program', 'medical_program' => ['id' => 'alias-program'],
            'employee_id' => 'foreign-author', 'context' => ['identifier' => ['value' => 'foreign-context']],
            'dosage_instruction' => [], 'signed_content' => 'raw',
        ], ['medication_id' => 'primary', 'medication_program_id' => 'program']];
    }

    #[DataProvider('metadata')]
    public function test_partial_metadata_matches_the_existing_cache_contract_without_queries(array $input, array $expected): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->assertSame($expected, app(ObjectMapperInterface::class)->map((object) $input, ModelData::class)->toSyncPatch());
        $this->assertSame([], $queries);
    }
}
