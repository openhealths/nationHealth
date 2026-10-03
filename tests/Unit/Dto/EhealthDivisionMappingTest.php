<?php

declare(strict_types=1);

namespace Tests\Unit\Dto;

use App\Dto\Division\Ehealth;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ObjectMapper\ObjectMapper;

class EhealthDivisionMappingTest extends TestCase
{
    private function map(array $form): Ehealth
    {
        return (new ObjectMapper())->map((object) $form, Ehealth::class);
    }

    private function form(array $override = []): array
    {
        return array_merge([
            'name' => 'Main division',
            'type' => 'CLINIC',
            'email' => 'div@example.com',
            'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
            'addresses' => [
                'residence' => [
                    'type' => 'RESIDENCE',
                    'country' => 'UA',
                    'streetType' => 'STREET',
                    'street' => 'Shevchenka',
                    'settlementType' => 'CITY',
                    'building' => '',
                ],
            ],
            'location' => ['latitude' => 50.45, 'longitude' => 30.52],
            'workingHours' => ['mon' => [['08:00', '17:30']], 'sun' => []],
        ], $override);
    }

    public function test_maps_form_to_ehealth_dto(): void
    {
        $dto = $this->map($this->form());

        $this->assertInstanceOf(Ehealth::class, $dto);
        $this->assertSame('Main division', $dto->name);
        $this->assertSame([['08.00', '17.30']], $dto->workingHours['mon']);
    }

    public function test_to_array_has_snake_case_keys_and_no_empty_values(): void
    {
        $data = $this->map($this->form())->toArray();

        $this->assertSame([
            'name' => 'Main division',
            'type' => 'CLINIC',
            'email' => 'div@example.com',
            'addresses' => [[
                'type' => 'RESIDENCE',
                'country' => 'UA',
                'street_type' => 'STREET',
                'street' => 'Shevchenka',
                'settlement_type' => 'CITY',
            ]],
            'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
            'location' => ['latitude' => 50.45, 'longitude' => 30.52],
            'working_hours' => ['mon' => [['08.00', '17.30']], 'sun' => []],
        ], $data);
    }

    public function test_zero_location_is_kept(): void
    {
        $data = $this->map($this->form(['location' => ['latitude' => 0.0, 'longitude' => 0.0]]))->toArray();

        $this->assertSame(['latitude' => 0.0, 'longitude' => 0.0], $data['location']);
    }

    public function test_ignores_unrelated_form_keys(): void
    {
        $data = $this->map($this->form(['legal_entity_id' => 5, 'uuid' => 'x']))->toArray();

        $this->assertArrayNotHasKey('legal_entity_id', $data);
        $this->assertArrayNotHasKey('uuid', $data);
    }
}
