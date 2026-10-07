<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Livewire\Division\Forms\DivisionForm;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use App\Services\Dictionary\DictionaryManager;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFormObjects\FormObjectSynth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DivisionFormValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $dictionaries = new BasicDictionaryCollection(collect([
            'PHONE_TYPE' => ['MOBILE', 'LAND_LINE'],
            'ADDRESS_TYPE' => ['RESIDENCE', 'RECEPTION'],
            'SETTLEMENT_TYPE' => ['CITY', 'VILLAGE'],
            'STREET_TYPE' => ['STREET'],
            'DIVISION_TYPE' => ['CLINIC', 'AMBULANT_CLINIC', 'FAP'],
        ])->map(fn (array $codes, string $name): array => [
            'name' => $name,
            'values' => array_map(fn (string $code): array => ['code' => $code, 'description' => $code], $codes),
        ])->values()->all());
        $this->mock(DictionaryManager::class)->shouldReceive('basics')->andReturn($dictionaries);

        $entity = new LegalEntity(['status' => 'ACTIVE']);
        $entity->setRelation('type', new LegalEntityType(['name' => LegalEntity::TYPE_PRIMARY_CARE]));
        $this->app->instance('legalEntity', $entity);
    }

    private function divisionForm(array $overrides = []): DivisionForm
    {
        $component = new class extends Component
        {
            public DivisionForm $divisionForm;

            public array $address = [];

            public array $receptionAddress = [];

            public array $addressErrors = [];

            public array $receptionErrors = [];

            public int $receptionValidationCalls = 0;

            public function addressValidation(): array
            {
                return $this->addressErrors;
            }

            public function receptionAddressValidation(): array
            {
                $this->receptionValidationCalls++;

                return $this->receptionErrors;
            }
        };
        $form = new DivisionForm($component, 'divisionForm');
        $component->divisionForm = $form;
        $form->setDivision(array_replace([
            'name' => 'Main division',
            'type' => 'CLINIC',
            'email' => 'division@example.com',
            'externalId' => null,
            'location' => ['latitude' => 50.45, 'longitude' => 30.52],
            'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
            'addresses' => ['residence' => [
                'type' => 'RESIDENCE', 'country' => 'UA', 'area' => 'М.КИЇВ',
                'settlement' => 'Київ', 'settlementType' => 'CITY',
                'streetType' => 'STREET', 'street' => 'Київська', 'zip' => '01001',
            ]],
            'workingHours' => ['mon' => [['08:00', '17:00']]],
        ], $overrides));
        $component->address = $form->division['addresses']['residence'] ?? [];
        $component->receptionAddress = array_replace($component->address, ['type' => 'RECEPTION']);
        FormObjectSynth::bootFormObject($component, $form, 'divisionForm');
        foreach ($component->getAttributes() as $attribute) {
            if (method_exists($attribute, 'boot')) {
                $attribute->boot();
            }
        }

        return $form;
    }

    private function validationErrors(DivisionForm $form): array
    {
        try {
            $form->doValidation();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('Expected form validation to fail.');
    }

    public function test_complete_division_form_passes_validation(): void
    {
        $form = $this->divisionForm();

        $this->assertSame('', $form->doValidation());
        $this->assertSame('Main division', $form->getDivision()['name']);
        $this->assertSame('+380501234567', $form->getDivision()['phones'][0]['number']);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidFields(): array
    {
        return [
            'missing name' => ['name', ''],
            'short name' => ['name', 'Short'],
            'long name' => ['name', str_repeat('a', 256)],
            'missing type' => ['type', ''],
            'missing email' => ['email', ''],
            'invalid email' => ['email', 'invalid-email'],
            'non-integer external id' => ['externalId', 'invalid'],
            'zero external id' => ['externalId', 0],
            'negative external id' => ['externalId', -1],
            'missing latitude' => ['location.latitude', null],
            'missing longitude' => ['location.longitude', null],
            'non-numeric latitude' => ['location.latitude', 'invalid'],
            'non-numeric longitude' => ['location.longitude', 'invalid'],
            'invalid latitude' => ['location.latitude', 100],
            'invalid longitude' => ['location.longitude', 181],
            'latitude above range' => ['location.latitude', 90.000001],
            'latitude below range' => ['location.latitude', -90.000001],
            'longitude above range' => ['location.longitude', 180.000001],
            'longitude below range' => ['location.longitude', -180.000001],
            'missing phones' => ['phones', []],
            'missing phone type' => ['phones.0.type', ''],
            'invalid phone type' => ['phones.0.type', 'INVALID'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_invalid_form_fields(string $field, mixed $value): void
    {
        $form = $this->divisionForm();
        Arr::set($form->division, $field, $value);

        $this->assertArrayHasKey('divisionForm.division.' . $field, $this->validationErrors($form));
    }

    public function test_accepts_optional_external_id_and_zero_coordinates(): void
    {
        $this->assertSame('', $this->divisionForm(['externalId' => 123])->doValidation());
        $this->assertSame('', $this->divisionForm([
            'location' => ['latitude' => 0, 'longitude' => 0],
        ])->doValidation());
    }

    public function test_accepts_coordinates_at_axis_limits_and_longitudes_outside_latitude_range(): void
    {
        foreach ([[90, 180], [-90, -180], [0, 100], [0, -100]] as [$latitude, $longitude]) {
            $this->assertSame('', $this->divisionForm([
                'location' => ['latitude' => $latitude, 'longitude' => $longitude],
            ])->doValidation());
        }
    }

    public function test_rejects_duplicate_phone_types(): void
    {
        $form = $this->divisionForm(['phones' => [
            ['type' => 'MOBILE', 'number' => '+380501234567'],
            ['type' => 'MOBILE', 'number' => '+380671234567'],
        ]]);

        $errors = $this->validationErrors($form);

        $this->assertArrayHasKey('divisionForm.division.phones.0.type', $errors);
        $this->assertArrayHasKey('divisionForm.division.phones.1.type', $errors);
    }

    public function test_accepts_multiple_distinct_phone_types(): void
    {
        $form = $this->divisionForm(['phones' => [
            ['type' => 'MOBILE', 'number' => '+380501234567'],
            ['type' => 'LAND_LINE', 'number' => '+380441234567'],
        ]]);

        $this->assertSame('', $form->doValidation());
    }

    public function test_complete_phone_number_passes_validation(): void
    {
        $this->assertSame('', $this->divisionForm()->doValidation());
    }

    public function test_incomplete_phone_number_shows_format_error_not_required_error(): void
    {
        $form = $this->divisionForm(['phones' => [['type' => 'MOBILE', 'number' => '+3805555555']]]);
        $errors = $this->validationErrors($form);

        $this->assertSame(__('validation.phone', ['min' => 9]), $errors['divisionForm.division.phones.0.number'][0]);
        $this->assertNotSame(__('divisions.errors.phone.number_required'), $errors['divisionForm.division.phones.0.number'][0]);
    }

    public function test_empty_phone_number_shows_required_error(): void
    {
        $form = $this->divisionForm(['phones' => [['type' => 'MOBILE', 'number' => '']]]);
        $errors = $this->validationErrors($form);

        $this->assertSame(__('divisions.errors.phone.number_required'), $errors['divisionForm.division.phones.0.number'][0]);
    }

    public function test_non_string_phone_number_shows_string_error_not_required_error(): void
    {
        $form = $this->divisionForm(['phones' => [['type' => 'MOBILE', 'number' => 380501234567]]]);
        $errors = $this->validationErrors($form);

        $this->assertArrayHasKey('divisionForm.division.phones.0.number', $errors);
        $this->assertNotSame(__('divisions.errors.phone.number_required'), $errors['divisionForm.division.phones.0.number'][0]);
    }

    public function test_merges_component_address_errors_with_form_errors(): void
    {
        $form = $this->divisionForm(['email' => '']);
        $form->getComponent()->addressErrors = ['address.settlement' => ['Settlement is required.']];

        $errors = $this->validationErrors($form);

        $this->assertArrayHasKey('divisionForm.division.email', $errors);
        $this->assertSame(['Settlement is required.'], $errors['address.settlement']);
    }

    public function test_reception_address_validation_only_runs_when_enabled(): void
    {
        $form = $this->divisionForm();
        $form->getComponent()->receptionErrors = ['receptionAddress.settlement' => ['Settlement is required.']];

        $this->assertSame('', $form->doValidation());
        $this->assertSame(0, $form->getComponent()->receptionValidationCalls);

        $form->showReceptionAddress = true;
        $errors = $this->validationErrors($form);

        $this->assertSame(['Settlement is required.'], $errors['receptionAddress.settlement']);
        $this->assertSame(1, $form->getComponent()->receptionValidationCalls);
    }

    public function test_normalizes_default_kyiv_street_type_before_validation(): void
    {
        $form = $this->divisionForm();
        $form->getComponent()->address['streetType'] = '';

        $this->assertSame('', $form->doValidation());
        $this->assertSame('STREET', $form->getDivision()['addresses']['residence']['streetType']);
    }

    public function test_rejects_inactive_legal_entity(): void
    {
        legalEntity()->status = 'INACTIVE';

        $this->assertSame(
            __('validation.attributes.healthcareService.error.legalEntity.status'),
            $this->divisionForm()->doValidation()
        );
    }

    public function test_accepts_suspended_legal_entity(): void
    {
        legalEntity()->status = 'SUSPENDED';

        $this->assertSame('', $this->divisionForm()->doValidation());
    }

    public function test_rejects_unknown_division_type(): void
    {
        $this->assertNotSame('', $this->divisionForm(['type' => 'INVALID'])->doValidation());
    }

    public function test_rejects_invalid_address_zip(): void
    {
        $form = $this->divisionForm();
        $form->getComponent()->address['zip'] = '123';

        $this->assertSame(__('divisions.errors.address.zip'), $form->doValidation());
    }

    public function test_rejects_invalid_working_hours(): void
    {
        $form = $this->divisionForm(['workingHours' => ['mon' => [['17:00', '08:00']]]]);

        $this->assertStringContainsString(__('divisions.errors.workingHours.wrongRange'), $form->doValidation());
    }

    public function test_missing_addresses_fail_direct_form_validation(): void
    {
        $form = $this->divisionForm(['addresses' => []]);

        try {
            $form->validate();
            $this->fail('Expected missing addresses to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('divisionForm.division.addresses', $exception->errors());
        }
    }

    public function test_prepares_residence_address_before_validation(): void
    {
        $form = $this->divisionForm();
        $form->division['addresses'] = [];

        $this->assertSame('', $form->doValidation());
        $this->assertSame(['residence' => $form->getComponent()->address], $form->division['addresses']);
    }

    public function test_prepares_both_addresses_when_reception_is_enabled(): void
    {
        $form = $this->divisionForm();
        $form->showReceptionAddress = true;

        $form->prepareAddresses();

        $this->assertSame([
            'residence' => $form->getComponent()->address,
            'reception' => $form->getComponent()->receptionAddress,
        ], $form->division['addresses']);
    }

    public function test_removes_stale_reception_address_when_disabled(): void
    {
        $form = $this->divisionForm();
        $form->showReceptionAddress = true;
        $form->prepareAddresses();
        $form->showReceptionAddress = false;

        $this->assertSame('', $form->doValidation());
        $this->assertSame(['residence'], array_keys($form->division['addresses']));
    }
}
