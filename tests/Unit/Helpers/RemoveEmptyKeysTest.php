<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Dto\HealthcareService\Ehealth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RemoveEmptyKeysTest extends TestCase
{
    public static function emptyValues(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'empty array' => [[]],
            'zero integer sentinel' => [0],
            'zero float sentinel' => [0.0],
            'negative zero sentinel' => [-0.0],
            'zero string sentinel' => ['0'],
            'list of empty values' => [[null, '', [], 0, 0.0, '0']],
            'nested empty object' => [['child' => ['value' => null, 'children' => []]]],
        ];
    }

    #[DataProvider('emptyValues')]
    public function test_removes_empty_values_and_legacy_zero_sentinels_at_every_level(mixed $value): void
    {
        $input = ['top' => $value, 'nested' => ['value' => $value], 'list' => [['value' => $value]]];

        $this->assertSame([], removeEmptyKeys($input));
    }

    public static function filledValues(): array
    {
        return [
            'false' => [false],
            'true' => [true],
            'integer' => [1],
            'negative integer' => [-1],
            'float' => [0.5],
            'negative float' => [-0.5],
            'leading zeros' => ['00'],
            'zero postal code' => ['00000'],
            'decimal text' => ['0.0'],
            'zero with suffix' => ['0А'],
            'fractional building number' => ['0/1'],
            'false text' => ['false'],
            'null text' => ['null'],
            'array text' => ['[]'],
        ];
    }

    #[DataProvider('filledValues')]
    public function test_preserves_filled_values_and_their_types_at_every_level(mixed $value): void
    {
        $input = ['top' => $value, 'nested' => ['value' => $value], 'list' => [['value' => $value]]];

        $this->assertSame($input, removeEmptyKeys($input));
    }

    public function test_removes_deeply_nested_empty_branches_without_mutating_the_input(): void
    {
        $input = ['empty' => null, 'false' => false, 'zero_sentinel' => '0', 'identifier' => '0000'];
        $expected = ['false' => false, 'identifier' => '0000'];

        for ($depth = 0; $depth < 64; $depth++) {
            $input = ['empty' => ['', null, []], 'child' => $input];
            $expected = ['child' => $expected];
        }
        $original = $input;

        $this->assertSame($expected, removeEmptyKeys($input));
        $this->assertSame($original, $input);
        $this->assertSame($expected, removeEmptyKeys(removeEmptyKeys($input)));
    }

    public function test_preserves_array_keys_when_removing_empty_list_items(): void
    {
        $this->assertSame([2 => false, 5 => '00'], removeEmptyKeys([0 => '', 1 => null, 2 => false, 4 => '0', 5 => '00']));
    }

    public function test_other_ehealth_payloads_keep_existing_filtering_and_boolean_semantics(): void
    {
        $dto = new Ehealth();
        $dto->divisionId = 'division-uuid';
        $dto->category = ['coding' => [['system' => 'HEALTHCARE_SERVICE_CATEGORIES', 'code' => 'MSP']]];
        $dto->comment = '0';
        $dto->availableTime = [[
            'allDay' => false, 'availableStartTime' => '', 'availableEndTime' => null, 'daysOfWeek' => [],
        ]];

        $this->assertSame([
            'division_id' => 'division-uuid',
            'category' => ['coding' => [['system' => 'HEALTHCARE_SERVICE_CATEGORIES', 'code' => 'MSP']]],
            'available_time' => [['all_day' => false]],
        ], $dto->toArray());
    }
}
