<?php

declare(strict_types=1);

namespace Tests\Unit\eHealth;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\SchemaResponseContract;
use GuzzleHttp\Psr7\Response;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchemaResponseContractTest extends TestCase
{
    #[DataProvider('officialResponses')]
    public function test_documented_native_examples_validate(string $operation, array $body, string $group): void
    {
        try {
            $this->assertSame($body['data'], SchemaResponseContract::validate($this->response($body), $operation, $group));
        } catch (ValidationException $exception) {
            $this->fail($operation.' '.json_encode($exception->errors(), JSON_THROW_ON_ERROR));
        }
    }

    public static function officialResponses(): array
    {
        $datasets = [];
        foreach (['shared', 'medication'] as $group) {
            $fixtures = json_decode(file_get_contents(__DIR__.'/../../Fixtures/'.$group.'-official-responses.json'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($fixtures as $operation => $body) {
                $datasets[$group.' '.$operation] = [$operation, $group === 'medication' ? ['data' => $body] : $body, $group];
            }
        }

        return $datasets;
    }

    #[DataProvider('invalidScalars')]
    public function test_recursive_constraints_reject_invalid_values(mixed $value, array $schema): void
    {
        $this->expectException(ValidationException::class);
        SchemaResponseContract::validateData($value, $schema);
    }

    public static function invalidScalars(): array
    {
        return [
            'invalid calendar day' => ['2026-02-30T10:00:00Z', ['type' => 'string', 'format' => 'date-time']],
            'timezone missing' => ['2026-11-11T10:00:00', ['type' => 'string', 'format' => 'date-time']],
            'invalid time' => ['2026-11-11T25:00:00Z', ['type' => 'string', 'format' => 'date-time']],
            'typed extra property' => [['named' => 'ok', 'extra' => 'bad'], ['type' => 'object', 'properties' => ['named' => ['type' => 'string']], 'additionalProperties' => ['type' => 'integer']]],
            'closed allOf' => [['a' => 1, 'b' => 2], ['allOf' => [['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => false], ['properties' => ['b' => ['type' => 'integer']]]]]],
        ];
    }

    public function test_nullable_allof_and_typed_extra_properties_and_precise_datetime_are_valid(): void
    {
        SchemaResponseContract::validateData(null, ['nullable' => true, 'allOf' => [['type' => 'object', 'required' => ['id']]]]);
        SchemaResponseContract::validateData(['named' => 'ok', 'extra' => 1], ['type' => 'object', 'properties' => ['named' => ['type' => 'string']], 'additionalProperties' => ['type' => 'integer']]);
        SchemaResponseContract::validateData('2026-11-11T10:00:00.123456+02:00', ['type' => 'string', 'format' => 'date-time']);
        $this->assertTrue(true);
    }

    private function response(array $body): EHealthResponse
    {
        return new EHealthResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
    }
}
