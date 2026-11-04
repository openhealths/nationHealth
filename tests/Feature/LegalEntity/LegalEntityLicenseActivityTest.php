<?php

declare(strict_types=1);

namespace Tests\Feature\LegalEntity;

use App\Classes\eHealth\Api\LegalEntity as LegalEntityApi;
use App\Classes\eHealth\Api\License as LicenseApi;
use App\Classes\eHealth\EHealthResponse;
use App\Jobs\LegalEntityDetailsSync;
use App\Livewire\Actions\Logout;
use App\Livewire\LegalEntity\CreateLegalEntity;
use App\Livewire\LegalEntity\EditLegalEntity;
use App\Livewire\LegalEntity\Forms\LegalEntitiesForms;
use App\Livewire\LegalEntity\LegalEntity as LegalEntityComponent;
use App\Livewire\LegalEntity\LegalEntityDetails;
use App\Livewire\License\LicenseIndex;
use App\Livewire\License\LicenseCreate;
use App\Livewire\License\Forms\LicenseForm;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\License;
use App\Models\User;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

class LegalEntityLicenseActivityTest extends TestCase
{
    use MocksBasicDictionaries;

    private LegalEntity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        // These regressions use only a disposable schema, never the configured database.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'cache.default' => 'array',
            'app.date_format' => 'd.m.Y',
        ]);
        Http::preventStrayRequests();

        foreach ([
            '2025_12_10_000012_create_legal_entity_types_table.php',
            '2025_12_10_000014_create_legal_entities_table.php',
            '2025_12_10_000028_create_licenses_table.php',
            '2025_12_10_000032_create_phones_table.php',
            '2025_12_10_000042_create_addresses_table.php',
            '2025_12_10_000070_create_revisions_table.php',
        ] as $migration) {
            (require database_path('migrations/install/' . $migration))->up();
        }

        $this->entity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_type_id' => LegalEntityType::whereName(LegalEntity::TYPE_PRIMARY_CARE)->value('id'),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
        ]);
        $this->app->instance('legalEntity', $this->entity);
    }

    #[DataProvider('acceptedActivities')]
    public function test_optional_activity_is_validated_and_only_nonblank_values_are_sent(mixed $value): void
    {
        $component = $this->legalEntityComponent();
        $component->legalEntityForm->license['whatLicensed'] = $value;

        $component->legalEntityForm->rulesForLicense();
        $request = $this->prepareRequest($component);

        $this->assertSame(filled($value), array_key_exists('what_licensed', $request['license']));
        if (filled($value)) {
            $this->assertSame($value, $request['license']['what_licensed']);
        }
    }

    public static function acceptedActivities(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'spaces' => ['   '],
            'Ukrainian text' => ['Медична практика'],
            'license number and punctuation' => ['Ліцензія №123 (2025)'],
            'Latin text' => ['Medical practice'],
            'zero is a valid string' => ['0'],
            'maximum length' => [str_repeat('А', 255)],
        ];
    }

    public function test_an_absent_activity_is_valid_and_is_not_sent(): void
    {
        $component = $this->legalEntityComponent();
        $component->legalEntityForm->rulesForLicense();

        $this->assertArrayNotHasKey('what_licensed', $this->prepareRequest($component)['license']);
    }

    #[DataProvider('invalidActivities')]
    public function test_nonblank_activity_must_match_the_ehealth_pattern(mixed $value): void
    {
        $component = $this->legalEntityComponent();
        $component->legalEntityForm->license['whatLicensed'] = $value;

        $this->expectException(ValidationException::class);
        $component->legalEntityForm->rulesForLicense();
    }

    public static function invalidActivities(): array
    {
        $cases = [
            'invalid initial character' => ['/Медична практика'],
            'leading space' => [' Медична практика'],
            'overlong' => [str_repeat('А', 256)],
            'integer' => [123],
            'array' => [[]],
        ];
        foreach (preg_split('//u', 'ЫЪЭЁыъэё@%&$^#', -1, PREG_SPLIT_NO_EMPTY) as $character) {
            $cases['forbidden ' . $character] = ['Медична ' . $character . ' практика'];
        }

        return $cases;
    }

    #[DataProvider('writeScenarios')]
    public function test_successful_creation_or_edit_saves_the_local_choice_instead_of_a_stale_echo(
        string $mode,
        ?string $value,
        ?string $remoteValue
    ): void {
        $component = $this->legalEntityComponent($mode === 'create' ? CreateLegalEntity::class : EditLegalEntity::class);
        $licenseData = $this->licenseData($remoteValue);
        if ($mode === 'edit') {
            $this->entity->licenses()->create($this->licenseData('Попереднє значення'));
            $licenseData['uuid'] = $this->entity->licenses()->sole()->uuid;
        }
        $component->legalEntityForm->license['whatLicensed'] = $value;
        $request = $this->prepareRequest($component);
        $response = new ReflectionMethod(LegalEntityComponent::class, 'filterUnprovidedFields')->invoke(
            $component,
            $this->response($licenseData),
            $request,
            filled($value)
        );

        if ($mode === 'create') {
            $this->entity->delete();
            $entity = new ReflectionMethod(LegalEntityComponent::class, 'createNewLegalEntity')->invoke($component, $response);
            $this->entity = $entity;
            Repository::legalEntity()->saveLicense($response['data']['license'], $entity, preserveEmptyActivity: false);
        } else {
            new ReflectionMethod(LegalEntityComponent::class, 'modifyLegalEntity')->invoke($component, $response, false);
        }

        $saved = License::whereUuid($licenseData['uuid'])->sole();
        $this->assertSame($value, $saved->what_licensed);

        $form = new LegalEntitiesForms($component, 'legalEntityForm');
        $form->fillLicense($this->entity->fresh());
        $this->assertSame($value, $form->license['whatLicensed']);
    }

    public static function writeScenarios(): array
    {
        $cases = [];
        foreach (['create', 'edit'] as $mode) {
            $cases[$mode . ': empty despite stale echo'] = [$mode, null, 'Старе значення ЕСОЗ'];
            $cases[$mode . ': empty for first registration'] = [$mode, null, null];
            $cases[$mode . ': provided'] = [$mode, 'Медична практика', 'Медична практика'];
        }

        return $cases;
    }

    public function test_reregistration_without_activity_keeps_it_empty_after_repeated_license_sync(): void
    {
        $primary = $this->entity->licenses()->create($this->licenseData('Попередній напрям діяльності'));
        $component = Mockery::mock(CreateLegalEntity::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $component->legalEntityForm = new LegalEntitiesForms($component, 'legalEntityForm');
        $component->legalEntityForm->owner = ['documents' => [], 'phones' => [], 'noTaxId' => false];
        $component->legalEntityForm->license = [
            'type' => 'MSP', 'issuedBy' => 'МОЗ', 'issuedDate' => '01.01.2025',
            'activeFromDate' => '01.01.2025', 'orderNo' => '123', 'whatLicensed' => '',
        ];
        $component->boot(Repository::address(), Repository::phone());
        $request = $this->prepareRequest($component);
        $echoedLicense = array_replace($this->licenseData('Попередній напрям діяльності'), ['uuid' => $primary->uuid]);
        $component->shouldReceive('signLegalEntity')->once()->andReturn([
            'request' => $request,
            'response' => $this->response($echoedLicense),
            'isWhatLicensedProvided' => filled($component->legalEntityForm->license['whatLicensed']),
        ]);
        $component->shouldReceive('createUser')->once()->andReturn(new User());
        $component->shouldReceive('createEmployeeRequest')->once()->andReturnNull();
        $logout = Mockery::mock(Logout::class);
        $logout->shouldReceive('__invoke')->once()->andReturnNull();
        $this->instance(Logout::class, $logout);

        // Keep the real creation response handling and persistence; stub external signing and owner setup.
        new ReflectionMethod(LegalEntityComponent::class, 'legalEntityCreate')->invoke($component);

        $this->assertArrayNotHasKey('what_licensed', $request['license']);
        $this->assertNull($primary->fresh()->what_licensed);

        $mappedLicense = $echoedLicense + array_fill_keys([
            'license_number', 'expiry_date', 'issuer_status', 'is_active',
            'ehealth_inserted_at', 'ehealth_inserted_by', 'ehealth_updated_at', 'ehealth_updated_by',
        ], null) + ['legal_entity_id' => $this->entity->id];
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->twice()->andReturn([$mappedLicense]);
        $response->shouldReceive('map')->twice()->with([$mappedLicense])->andReturn([$mappedLicense]);
        $api = Mockery::mock(LicenseApi::class);
        $api->shouldReceive('getMany')->twice()->andReturn($response);
        $this->instance(LicenseApi::class, $api);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            new LicenseIndex()->sync();

            $this->assertSame(__('licenses.success.sync'), session('success'));
            $this->assertNull($primary->fresh()->what_licensed);
        }
    }

    public function test_unchanged_license_can_be_sent_by_id_without_forgetting_the_empty_choice(): void
    {
        $saved = $this->entity->licenses()->create($this->licenseData(null));
        $component = $this->legalEntityComponent();
        new ReflectionMethod(EditLegalEntity::class, 'getLicenseForm')->invoke($component);
        $request = $this->prepareRequest($component);

        $this->assertSame(['id' => $saved->uuid], $request['license']);
        $echo = $this->licenseData('Старе значення ЕСОЗ');
        $echo['uuid'] = $saved->uuid;
        Repository::legalEntity()->saveLicense($echo, $this->entity);

        $this->assertNull($saved->fresh()->what_licensed);
    }

    public function test_a_new_value_can_be_entered_after_clearing_the_field(): void
    {
        $saved = $this->entity->licenses()->create($this->licenseData(null));
        $component = $this->legalEntityComponent();
        $component->legalEntityForm->license['whatLicensed'] = 'Нове значення';
        $licenseData = $this->licenseData('Нове значення');
        $licenseData['uuid'] = $saved->uuid;
        $response = new ReflectionMethod(LegalEntityComponent::class, 'filterUnprovidedFields')->invoke(
            $component,
            $this->response($licenseData),
            $this->prepareRequest($component),
            true
        );
        new ReflectionMethod(LegalEntityComponent::class, 'modifyLegalEntity')->invoke($component, $response, false);

        $this->assertSame('Нове значення', $saved->fresh()->what_licensed);
    }

    #[DataProvider('syncScenarios')]
    public function test_sync_applies_the_activity_rules_for_each_entry_point(string $mode, ?string $storedValue): void
    {
        $saved = $this->entity->licenses()->create($this->licenseData($storedValue));
        $licenseData = $this->licenseData('Збережене в ЕСОЗ');
        $licenseData['uuid'] = $saved->uuid;
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(
            $mode === 'license_list' ? [$licenseData] : $this->response($licenseData)['data']
        );

        if ($mode === 'background') {
            $job = new LegalEntityDetailsSync($this->entity, isFirstLogin: true);
            new ReflectionMethod($job, 'processResponse')->invoke($job, $response);
        } elseif ($mode === 'manual') {
            $user = Mockery::mock(User::class)->makePartial();
            $user->shouldReceive('cannot')->with('sync', Mockery::type(LegalEntity::class))->andReturnFalse();
            $user->shouldReceive('can')->with('syncLegators', Mockery::type(LegalEntity::class))->andReturnFalse();
            $this->actingAs($user, 'ehealth');
            $api = Mockery::mock(LegalEntityApi::class);
            $api->shouldReceive('getDetails')->once()->andReturn($response);
            $this->instance(LegalEntityApi::class, $api);
            $component = $this->legalEntityComponent(LegalEntityDetails::class);
            $component->sync();
            $this->assertSame(__('forms.sync_successfull'), session('success'));
            $this->assertSame($storedValue === null ? null : 'Збережене в ЕСОЗ', $component->legalEntityForm->license['whatLicensed']);
        } else {
            $response->shouldReceive('map')->once()->andReturn([$licenseData + [
                'legal_entity_id' => $this->entity->id,
                'is_primary' => true,
                'is_active' => true,
                'license_number' => null,
                'expiry_date' => null,
                'issuer_status' => null,
                'ehealth_inserted_at' => null,
                'ehealth_inserted_by' => null,
                'ehealth_updated_at' => null,
                'ehealth_updated_by' => null,
            ]]);
            $api = Mockery::mock(LicenseApi::class);
            $api->shouldReceive('getMany')->once()->andReturn($response);
            $this->instance(LicenseApi::class, $api);
            new LicenseIndex()->sync();
            $this->assertSame(__('licenses.success.sync'), session('success'));
        }

        $expectedValue = $mode === 'background' || $storedValue !== null ? 'Збережене в ЕСОЗ' : null;
        $this->assertSame($expectedValue, $saved->fresh()->what_licensed);
    }

    public static function syncScenarios(): array
    {
        return [
            'manual, empty' => ['manual', null],
            'connection import, empty' => ['background', null],
            'license list, empty' => ['license_list', null],
            'manual, provided' => ['manual', 'Місцеве значення'],
            'connection import, provided' => ['background', 'Місцеве значення'],
            'license list, provided' => ['license_list', 'Місцеве значення'],
        ];
    }

    #[DataProvider('primaryLicensePositions')]
    public function test_license_list_sync_keeps_only_the_primary_activity_empty(?int $primaryPosition): void
    {
        $primary = $this->entity->licenses()->create($this->licenseData(null));
        $defaults = array_fill_keys([
            'license_number', 'expiry_date', 'issuer_status', 'is_active',
            'ehealth_inserted_at', 'ehealth_inserted_by', 'ehealth_updated_at', 'ehealth_updated_by',
        ], null) + ['legal_entity_id' => $this->entity->id];
        $additionalData = array_replace($this->licenseData('Додатковий напрям') + $defaults, [
            'type' => 'PHARMACY_DRUGS', 'is_primary' => false,
        ]);
        $additional = $this->entity->licenses()->create($additionalData);
        $rows = [array_replace($additionalData, ['what_licensed' => 'Оновлений додатковий напрям'])];
        if ($primaryPosition !== null) {
            array_splice($rows, $primaryPosition, 0, [
                array_replace($this->licenseData('Старе значення ЕСОЗ') + $defaults, ['uuid' => $primary->uuid]),
            ]);
        }
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn($rows);
        $response->shouldReceive('map')->once()->with($rows)->andReturn($rows);
        $api = Mockery::mock(LicenseApi::class);
        $api->shouldReceive('getMany')->once()->andReturn($response);
        $this->instance(LicenseApi::class, $api);

        new LicenseIndex()->sync();

        $this->assertSame(__('licenses.success.sync'), session('success'));
        $this->assertNull($primary->fresh()->what_licensed);
        $this->assertSame('Оновлений додатковий напрям', $additional->fresh()->what_licensed);
    }

    public static function primaryLicensePositions(): array
    {
        return [
            'primary first' => [0],
            'primary after additional license' => [1],
            'primary absent from response' => [null],
        ];
    }

    public function test_response_validation_accepts_null_or_missing_activity(): void
    {
        $component = $this->legalEntityComponent();
        $response = $this->response($this->licenseData(null));
        $validated = new ReflectionMethod(LegalEntityComponent::class, 'validateResponse')->invoke($component, $response);
        $this->assertNull($validated['data']['license']['what_licensed']);

        unset($response['data']['license']['what_licensed']);
        $validated = new ReflectionMethod(LegalEntityComponent::class, 'validateResponse')->invoke($component, $response);
        $this->assertArrayNotHasKey('what_licensed', $validated['data']['license']);
    }

    #[DataProvider('listedActivities')]
    public function test_license_list_requires_activity_only_for_additional_licenses(bool $primary, ?string $value, bool $valid): void
    {
        $this->mockBasicDictionaries(['LICENSE_TYPE' => ['MSP' => 'Медична практика', 'PHARMACY_DRUGS' => 'Наркотичні засоби']]);
        $data = $this->licenseData($value) + [
            'legal_entity_id' => $this->entity->uuid,
            'is_primary' => $primary,
            'is_active' => true,
            'ehealth_inserted_at' => '2025-01-01',
            'ehealth_inserted_by' => (string) Str::uuid(),
            'ehealth_updated_at' => '2025-01-01',
            'ehealth_updated_by' => (string) Str::uuid(),
        ];
        $data['type'] = $primary ? 'MSP' : 'PHARMACY_DRUGS';
        $data['is_primary'] = $primary;
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->once()->andReturn([$data]);

        if (!$valid) {
            $this->expectException(ValidationException::class);
        }
        $result = new ReflectionMethod(LicenseApi::class, 'validateMany')->invoke(new LicenseApi(), $response);
        $this->assertSame($value, $result[0]['what_licensed']);
    }

    public static function listedActivities(): array
    {
        return [
            'primary, null' => [true, null, true],
            'primary, provided' => [true, 'Медична практика', true],
            'additional, null' => [false, null, false],
            'additional, empty' => [false, '', false],
            'additional, provided' => [false, 'Наркотичні засоби', true],
        ];
    }

    #[DataProvider('additionalActivities')]
    public function test_additional_license_creation_requires_activity(?string $value, bool $valid): void
    {
        $component = new LicenseCreate();
        $component->licenseTypes = ['PHARMACY_DRUGS' => 'Наркотичні засоби'];
        $component->form = new LicenseForm($component, 'form');
        $component->form->fill([
            'type' => 'PHARMACY_DRUGS',
            'issuedBy' => 'МОЗ',
            'issuedDate' => '01.01.2025',
            'activeFromDate' => '01.01.2025',
            'expiryDate' => now()->addYear()->format('d.m.Y'),
            'orderNo' => '123',
        ]);
        if ($value === null) {
            unset($component->form->whatLicensed);
        } else {
            $component->form->whatLicensed = $value;
        }

        if (!$valid) {
            $this->expectException(ValidationException::class);
        }
        $validated = $component->form->validate();
        $this->assertSame($value, $validated['whatLicensed']);
    }

    public static function additionalActivities(): array
    {
        return [
            'missing' => [null, false],
            'empty' => ['', false],
            'spaces' => ['   ', false],
            'provided' => ['Реалізація наркотичних засобів', true],
        ];
    }

    public function test_first_import_of_a_license_keeps_the_remote_activity(): void
    {
        $data = $this->licenseData('Значення ЕСОЗ');
        Repository::legalEntity()->saveLicense($data, $this->entity);

        $this->assertSame('Значення ЕСОЗ', License::whereUuid($data['uuid'])->sole()->what_licensed);
    }

    public function test_upgrade_preserves_old_values_and_allows_empty_values(): void
    {
        $saved = $this->entity->licenses()->create($this->licenseData('Наявне значення'));
        $columns = Schema::getColumnListing('licenses');
        $migration = require database_path('migrations/update/0_1/2026_11_05_000000_make_what_licensed_nullable_on_license_table.php');
        $migration->down();
        $migration->down();
        $migration->up();
        $migration->up();

        $this->assertSame($columns, Schema::getColumnListing('licenses'));
        $this->assertSame('Наявне значення', $saved->fresh()->what_licensed);
        $saved->update(['what_licensed' => null]);
        $this->assertNull($saved->fresh()->what_licensed);
    }

    private function legalEntityComponent(string $class = EditLegalEntity::class): LegalEntityComponent
    {
        $component = new $class();
        $component->legalEntityForm = new LegalEntitiesForms($component, 'legalEntityForm');
        $component->legalEntityForm->owner = ['documents' => [], 'phones' => [], 'noTaxId' => false];
        $component->legalEntityForm->license = [
            'type' => 'MSP', 'issuedBy' => 'МОЗ', 'issuedDate' => '01.01.2025',
            'activeFromDate' => '01.01.2025', 'orderNo' => '123',
        ];
        $component->boot(Repository::address(), Repository::phone());
        new ReflectionProperty(LegalEntityComponent::class, 'legalEntity')->setValue($component, $this->entity);

        return $component;
    }

    private function prepareRequest(LegalEntityComponent $component): array
    {
        return new ReflectionMethod(LegalEntityComponent::class, 'prepareDataForRequest')->invoke(
            $component,
            $component->legalEntityForm->toArray()
        );
    }

    private function licenseData(?string $activity): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'type' => 'MSP', 'issued_by' => 'МОЗ',
            'issued_date' => '2025-01-01', 'active_from_date' => '2025-01-01',
            'order_no' => '123', 'what_licensed' => $activity, 'is_primary' => true,
        ];
    }

    private function response(array $license): array
    {
        return [
            'data' => [
                'uuid' => $this->entity->uuid, 'type' => LegalEntity::TYPE_PRIMARY_CARE,
                'status' => 'ACTIVE', 'edrpou' => '12345678', 'email' => 'test@example.org',
                'nhs_verified' => false, 'nhs_reviewed' => false,
                'edr' => [
                    'uuid' => (string) Str::uuid(), 'edrpou' => '12345678', 'name' => 'Заклад', 'state' => 1,
                    'kveds' => [['name' => 'Медична практика', 'code' => '86.21', 'is_primary' => true]],
                    'registration_address' => ['country' => 'UA'],
                ],
                'residence_address' => [
                    'type' => 'RESIDENCE', 'country' => 'UA', 'area' => 'Київська', 'settlement' => 'Київ',
                    'settlement_type' => 'CITY', 'settlement_id' => (string) Str::uuid(),
                ],
                'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
                'license' => $license,
            ],
            'urgent' => [
                'employee_request_id' => (string) Str::uuid(),
                'security' => ['client_secret' => 'test-secret', 'client_id' => $this->entity->uuid],
            ],
        ];
    }
}
