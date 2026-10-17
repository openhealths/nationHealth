<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Dto\Division\Ehealth;
use App\Http\Middleware\TrimStrings;
use App\Livewire\Division\Forms\DivisionForm;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
use Livewire\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

class EhealthDivisionMappingTest extends TestCase
{
    private function map(array $form): Ehealth
    {
        return $this->mapForm($this->divisionForm($form));
    }

    private function divisionForm(array $division): DivisionForm
    {
        $form = new DivisionForm(new class extends Component
        {}, 'divisionForm');
        $form->division = $division;

        return $form;
    }

    private function mapForm(DivisionForm $form): Ehealth
    {
        return (new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor()))
            ->map($form, Ehealth::class);
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

    public function test_maps_optional_external_id_from_the_form(): void
    {
        $data = $this->map($this->form(['externalId' => '123']))->toArray();

        $this->assertSame('123', $data['external_id']);
    }

    public static function externalIds(): array
    {
        return [
            'null' => [null, false],
            'empty string' => ['', false],
            'zero' => ['0', true],
            'leading zeros' => ['0000', true],
            'decimal text' => ['0.0', true],
            'numeric text' => ['123', true],
            'alphanumeric' => ['ID-0', true],
            'unicode' => ['МНП-0', true],
        ];
    }

    #[DataProvider('externalIds')]
    public function test_preserves_filled_external_ids_and_omits_empty_ones(?string $externalId, bool $keep): void
    {
        $data = $this->map($this->form(['externalId' => $externalId]))->toArray();

        $this->assertSame($keep, array_key_exists('external_id', $data));
        if ($keep) {
            $this->assertSame($externalId, $data['external_id']);
        }
    }

    public static function optionalAddressValues(): array
    {
        return [
            'null' => [null, false],
            'empty string' => ['', false],
            'zero string' => ['0', false],
            'zero integer' => [0, false],
            'zero float' => [0.0, false],
            'leading zeros' => ['00', true],
            'zero postal code' => ['00000', true],
            'zero with a suffix' => ['0А', true],
            'fractional building number' => ['0/1', true],
            'non-zero number' => ['10', true],
        ];
    }

    #[DataProvider('optionalAddressValues')]
    public function test_optional_address_fields_keep_existing_empty_value_semantics(mixed $value, bool $keep): void
    {
        $fields = ['region', 'building', 'apartment', 'zip'];
        $address = array_replace($this->form()['addresses']['residence'], array_fill_keys($fields, $value));
        $data = $this->map($this->form([
            'externalId' => '0',
            'addresses' => [
                'residence' => $address,
                'reception' => array_replace($address, ['type' => 'RECEPTION']),
            ],
        ]))->toArray();

        foreach ($data['addresses'] as $mappedAddress) {
            foreach ($fields as $field) {
                $this->assertSame($keep, array_key_exists($field, $mappedAddress), $field);
                if ($keep) {
                    $this->assertSame($value, $mappedAddress[$field], $field);
                }
            }
        }
        $this->assertSame('0', $data['external_id']);
    }

    public function test_combined_payloads_preserve_meaningful_zeros_and_remove_empty_fields(): void
    {
        $locations = [
            ['latitude' => 0, 'longitude' => 0],
            ['latitude' => 0.0, 'longitude' => 0.0],
            ['latitude' => -0.0, 'longitude' => 0.0],
            ['latitude' => 0, 'longitude' => 30.52],
            ['latitude' => 50.45, 'longitude' => 0],
        ];
        $schedules = [
            [[], []],
            [['mon' => [['08:00', '17:30']], 'sun' => []], ['mon' => [['08.00', '17.30']], 'sun' => []]],
            [['sun' => [['-1', '-1']]], ['sun' => [['00.00', '00.00']]]],
        ];

        foreach (self::externalIds() as [$externalId, $keepExternalId]) {
            foreach (self::optionalAddressValues() as [$addressValue, $keepAddressValue]) {
                foreach ($locations as $location) {
                    foreach ($schedules as [$workingHours, $expectedHours]) {
                        $input = $this->form([
                            'externalId' => $externalId,
                            'location' => $location,
                            'workingHours' => $workingHours,
                            'addresses' => ['residence' => [
                                'type' => 'RESIDENCE', 'country' => 'UA',
                                'building' => $addressValue, 'apartment' => '', 'region' => null,
                            ]],
                        ]);
                        $form = $this->divisionForm($input);
                        $data = $this->mapForm($form)->toArray();
                        $expected = [
                            'name' => 'Main division',
                            'type' => 'CLINIC',
                            'email' => 'div@example.com',
                            'addresses' => [['type' => 'RESIDENCE', 'country' => 'UA']],
                            'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
                            'location' => $location,
                            'working_hours' => $expectedHours,
                        ];
                        if ($keepAddressValue) {
                            $expected['addresses'][0]['building'] = $addressValue;
                        }
                        if ($keepExternalId) {
                            $expected['external_id'] = $externalId;
                        }

                        $this->assertEquals($expected, $data);
                        $this->assertSame($location, $data['location']);
                        $this->assertSame($input, $form->division);
                        $this->assertEquals($expected, json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
                    }
                }
            }
        }
    }

    public static function whitespaceValues(): array
    {
        return [
            'spaces' => ['   ', null, null],
            'tabs and newlines' => ["\t\n", null, null],
            'padded zero' => [' 0 ', '0', null],
            'padded leading zeros' => [' 00 ', '00', '00'],
        ];
    }

    #[DataProvider('whitespaceValues')]
    public function test_http_input_normalization_removes_whitespace_without_losing_external_id_zero(
        string $value,
        ?string $expectedExternalId,
        ?string $expectedBuilding
    ): void {
        $request = Request::create('/livewire/update', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'division' => $this->form([
                'externalId' => $value,
                'addresses' => ['residence' => ['type' => 'RESIDENCE', 'country' => 'UA', 'building' => $value]],
            ]),
        ], JSON_THROW_ON_ERROR));
        $input = new TrimStrings()->handle($request, fn (Request $request) => new ConvertEmptyStringsToNull()->handle(
            $request,
            fn (Request $request) => $request->input('division')
        ));
        $data = $this->map($input)->toArray();

        $this->assertSame($expectedExternalId !== null, array_key_exists('external_id', $data));
        $this->assertSame($expectedExternalId, $data['external_id'] ?? null);
        $this->assertSame($expectedBuilding !== null, array_key_exists('building', $data['addresses'][0]));
        $this->assertSame($expectedBuilding, $data['addresses'][0]['building'] ?? null);
    }

    public function test_ignores_form_state_and_does_not_mutate_the_source(): void
    {
        $division = $this->form();
        $form = $this->divisionForm($division);
        $form->search = 'Unrelated search';
        $form->showReceptionAddress = true;

        $data = $this->mapForm($form)->toArray();

        $this->assertArrayNotHasKey('search', $data);
        $this->assertArrayNotHasKey('show_reception_address', $data);
        $this->assertSame($division, $form->division);
        $this->assertSame('Main division', $data['name']);
    }

    public function test_normalizes_null_collections_from_the_form(): void
    {
        $dto = $this->map($this->form([
            'addresses' => null,
            'phones' => null,
            'workingHours' => null,
            'location' => null,
        ]));

        $this->assertSame([], $dto->addresses);
        $this->assertSame([], $dto->phones);
        $this->assertSame([], $dto->workingHours);
        $this->assertNull($dto->location);
        $this->assertSame([], $dto->toArray()['working_hours']);
    }
}
