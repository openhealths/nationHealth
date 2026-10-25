<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\Device;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\DeviceRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\EncounterPackageHarness as EncounterPackageBuilder;
use Tests\Support\EncounterPackageHarness as EncounterPackageLoader;
use Tests\TestCase;

class DeviceRepositoryMappingTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('patientTypes')]
    public function test_actual_package_store_and_form_load_preserve_nested_values_and_owner(string $patientType): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        $patient = $patientType::create([
            'uuid' => (string) Str::uuid(), 'gender' => 'MALE', 'birth_date' => '1990-01-01',
            ...($patientType === Person::class ? ['patient_signed' => true, 'process_disclosure_data_consent' => true] : []),
        ]);
        $uuids = array_combine(['encounter', 'visit', 'episode', 'employee'], array_map(
            static fn (): string => (string) Str::uuid(),
            range(1, 4),
        ));
        $row = [
            'uuid' => (string) Str::uuid(), 'status' => 'active', 'primarySource' => true, 'typeCode' => 'device-type',
            'definitionId' => (string) Str::uuid(), 'parentId' => (string) Str::uuid(),
            'names' => [5 => ['type' => 'user_friendly', 'value' => 'Patient device']],
            'identifiers' => [8 => ['code' => 'external-device', 'text' => 'External ID', 'value' => 'external-id']],
            'properties' => [
                3 => ['code' => 'boolean', 'valueBoolean' => false],
                5 => ['code' => 'integer', 'valueInteger' => 0],
                8 => ['code' => 'string', 'valueString' => ''],
                13 => ['code' => 'quantity', 'valueQuantityValue' => 0, 'valueQuantityUnit' => 'g'],
                21 => ['code' => 'range', 'valueRangeLowValue' => 0, 'valueRangeLowUnit' => 'g', 'valueRangeHighValue' => 10, 'valueRangeHighUnit' => 'g'],
                34 => ['code' => 'concept', 'valueCodeableConceptSystem' => 'dictionary_with_underscore', 'valueCodeableConceptCode' => '0'],
            ],
        ];

        $package = app(EncounterPackageBuilder::class)->toFhir([
            'devices' => [42 => $row],
            'encounter' => [
                'periodDate' => '05.10.2026', 'periodStart' => '10:00', 'periodEnd' => '11:00',
                'classCode' => 'AMB', 'typeCode' => 'consultation', 'performerId' => $uuids['employee'],
                'referralType' => '', 'diagnoses' => [],
            ],
        ], $uuids);
        app(DeviceRepository::class)->store($package['devices'], $patient);

        $stored = Device::where('uuid', $row['uuid'])->firstOrFail();
        $ownerColumn = $patientType === Person::class ? 'person_id' : 'preperson_id';
        $otherColumn = $patientType === Person::class ? 'preperson_id' : 'person_id';
        $this->assertSame($patient->id, $stored->getRawOriginal($ownerColumn));
        $this->assertNull($stored->getRawOriginal($otherColumn));
        $this->assertSame($uuids['encounter'], $stored->context->value);
        $this->assertSame($uuids['employee'], $stored->recorder->value);
        $this->assertSame($row['definitionId'], $stored->definition->value);
        $this->assertSame($row['parentId'], $stored->parent->value);

        $loaded = new ReflectionMethod(EncounterPackageLoader::class, 'loadDevices')
            ->invoke(app(EncounterPackageLoader::class), $uuids['encounter']);
        $this->assertCount(1, $loaded);
        $form = array_values($loaded)[0];
        $this->assertSame($row['uuid'], $form['uuid']);
        $this->assertSame(array_values($row['names']), $form['names']);
        $this->assertSame(array_values($row['identifiers']), $form['identifiers']);
        $this->assertCount(6, $form['properties']);
        $this->assertTrue(array_is_list($form['properties']));
        $properties = array_column($form['properties'], null, 'code');
        $this->assertFalse($properties['boolean']['valueBoolean']);
        $this->assertSame(0, $properties['integer']['valueInteger']);
        $this->assertSame('', $properties['string']['valueString']);
        // Quantity's existing Eloquent cast hydrates numeric values as floats.
        $this->assertSame(0.0, $properties['quantity']['valueQuantityValue']);
        $this->assertSame('g', $properties['quantity']['valueQuantityUnit']);
        foreach (['Comparator', 'System', 'Code'] as $suffix) {
            $this->assertNull($properties['quantity']['valueQuantity'.$suffix]);
        }
        $this->assertSame(0.0, $properties['range']['valueRangeLowValue']);
        $this->assertSame(10.0, $properties['range']['valueRangeHighValue']);
        foreach (['Low', 'High'] as $bound) {
            $this->assertSame('g', $properties['range']['valueRange'.$bound.'Unit']);
            $this->assertNull($properties['range']['valueRange'.$bound.'System']);
            $this->assertNull($properties['range']['valueRange'.$bound.'Code']);
        }
        $this->assertSame('dictionary_with_underscore', $properties['concept']['valueCodeableConceptSystem']);
        $this->assertSame('0', $properties['concept']['valueCodeableConceptCode']);
        Http::assertNothingSent();
    }

    public static function patientTypes(): iterable
    {
        yield 'person' => [Person::class];
        yield 'preperson' => [Preperson::class];
    }
}
