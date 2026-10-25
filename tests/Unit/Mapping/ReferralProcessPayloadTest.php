<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\ServiceRequest\EhealthProcess;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class ReferralProcessPayloadTest extends TestCase
{
    public static function contexts(): iterable
    {
        yield 'employee only' => [null, null, null];
        yield 'all references' => ['division-id', 'legal-entity-id', 'program-id'];
        yield 'raw program preserved' => ['', 'legal-entity-id', ' program-id '];
        yield 'falsey optionals omitted' => ['0', '', 0];
    }

    #[DataProvider('contexts')]
    public function test_mapping_preserves_the_existing_wire_contract_without_queries(mixed $division, mixed $legalEntity, mixed $program): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $reference = static fn (string $type, mixed $value): array => ['identifier' => [
            'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]],
            'value' => $value,
        ]];
        $expected = ['used_by_employee' => $reference('employee', 'employee-id')];
        if ($division) {
            $expected['used_by_division'] = $reference('division', $division);
        }
        if ($legalEntity) {
            $expected['used_by_legal_entity'] = $reference('legal_entity', $legalEntity);
        }
        if ($program) {
            $expected['program'] = $reference('medical_program', $program);
        }

        $payload = app(ObjectMapperInterface::class)->map((object) [
            'employeeUuid' => 'employee-id', 'divisionUuid' => $division,
            'legalEntityUuid' => $legalEntity, 'programId' => $program,
            'status' => 'injected', 'note' => 'local-only',
        ], EhealthProcess::class)->toArray();

        $this->assertSame($expected, $payload);
        $this->assertSame(json_encode($expected), json_encode($payload));
        $this->assertSame([], $queries);
    }
}
