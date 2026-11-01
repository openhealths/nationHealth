<?php

declare(strict_types=1);

namespace Tests\Feature\LegalEntity;

use App\Classes\eHealth\Api\LegalEntity as LegalEntityApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\JobStatus;
use App\Livewire\LegalEntity\Forms\LegalEntitiesForms;
use App\Livewire\LegalEntity\LegalEntityDetails;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\License;
use App\Models\User;
use App\Repositories\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LegalEntityDetailsSyncTest extends TestCase
{
    use DatabaseTransactions;

    private LegalEntity $legalEntity;

    private License $license;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['app.date_format' => 'd.m.Y']);
    }

    #[DataProvider('primaryLicenseTypes')]
    public function test_sync_updates_displayed_license_without_remounting(string $entityType, string $licenseType): void
    {
        $component = $this->detailsComponent($entityType, $licenseType);
        $updatedLicense = $this->updatedLicense($licenseType);
        $this->fakeDetails($updatedLicense);

        $component->sync();

        $this->assertSame(__('forms.sync_successfull'), session('success'));
        $this->assertDatabaseHas('licenses', $updatedLicense);
        $this->assertEquals([
            'type' => $licenseType,
            'licenseNumber' => 'NEW-456',
            'issuedBy' => 'Updated issuer',
            'issuedDate' => '02.02.2026',
            'expiryDate' => '03.03.2030',
            'activeFromDate' => '04.02.2026',
            'whatLicensed' => 'Updated activity',
            'orderNo' => 'NEW-ORDER',
        ], $component->legalEntityForm->license);
        $this->assertSame(JobStatus::COMPLETED->value, $this->legalEntity->fresh()->sync_status);
    }

    public static function primaryLicenseTypes(): array
    {
        return [
            'medical practice' => [LegalEntity::TYPE_PRIMARY_CARE, 'MSP'],
            'pharmacy' => [LegalEntity::TYPE_PHARMACY, 'PHARMACY'],
        ];
    }

    public function test_sync_clears_displayed_optional_license_fields(): void
    {
        $component = $this->detailsComponent();
        $updatedLicense = array_replace($this->updatedLicense(), [
            'license_number' => null,
            'expiry_date' => null,
        ]);
        $this->fakeDetails($updatedLicense);

        $component->sync();

        $this->assertSame(__('forms.sync_successfull'), session('success'));
        $this->assertNull($component->legalEntityForm->license['licenseNumber']);
        $this->assertNull($component->legalEntityForm->license['expiryDate']);
        $this->assertSame('NEW-ORDER', $component->legalEntityForm->license['orderNo']);
        $this->assertDatabaseHas('licenses', $updatedLicense);
    }

    #[DataProvider('optionalAddressFields')]
    public function test_sync_refreshes_displayed_address_without_remounting(array $optionalFields): void
    {
        $component = $this->detailsComponent();
        $address = array_merge([
            'type' => 'RESIDENCE',
            'country' => 'UA',
            'area' => 'Чернівецька',
            'settlement' => 'Чернівці',
            'settlement_type' => 'CITY',
            'settlement_id' => 'bb03f500-7fe2-477b-9d48-f21702b128b2',
        ], $optionalFields);
        $this->fakeDetails($this->updatedLicense(), ['residence_address' => $address]);

        $component->sync();

        $this->assertSame(__('forms.sync_successfull'), session('success'));
        $this->assertEquals([
            'type' => 'RESIDENCE',
            'country' => 'UA',
            'area' => 'ЧЕРНІВЕЦЬКА',
            'settlement' => 'Чернівці',
            'settlementType' => 'CITY',
            'settlementId' => 'bb03f500-7fe2-477b-9d48-f21702b128b2',
            'region' => $optionalFields['region'] ?? null,
            'streetType' => $optionalFields['street_type'] ?? null,
            'street' => $optionalFields['street'] ?? null,
            'building' => $optionalFields['building'] ?? null,
            'apartment' => $optionalFields['apartment'] ?? null,
            'zip' => $optionalFields['zip'] ?? null,
        ], $component->address);
        $this->assertSame($component->address, $component->legalEntityForm->residenceAddress);
        $this->assertDatabaseHas('addresses', array_merge($address, [
            'addressable_type' => LegalEntity::class,
            'addressable_id' => $this->legalEntity->id,
        ]));
    }

    public static function optionalAddressFields(): array
    {
        return [
            'updated fields' => [[
                'region' => 'Чернівецький',
                'street_type' => 'STREET',
                'street' => 'Головна',
                'building' => '25',
                'apartment' => '8',
                'zip' => '58000',
            ]],
            'cleared fields' => [[
                'region' => null,
                'street_type' => null,
                'street' => null,
                'building' => null,
                'apartment' => null,
                'zip' => null,
            ]],
            'omitted fields' => [[]],
        ];
    }

    #[DataProvider('updatedContactPhones')]
    public function test_sync_refreshes_displayed_contact_phones_without_remounting(array $phones): void
    {
        $component = $this->detailsComponent();
        $ownerContacts = [
            'phones' => [['type' => 'MOBILE', 'number' => '+380931234567']],
            'documents' => [['type' => 'NATIONAL_ID', 'number' => '000111000', 'issuedAt' => '01.01.2025']],
        ];
        $component->legalEntityForm->owner = $ownerContacts;
        $this->fakeDetails($this->updatedLicense(), ['phones' => $phones]);

        $component->sync();

        $this->assertSame(__('forms.sync_successfull'), session('success'));
        $this->assertEquals($phones, $component->legalEntityForm->phones);
        $this->assertEquals($phones, $this->legalEntity->phones()->get()->toArray());
        $this->assertSame($ownerContacts, $component->legalEntityForm->owner);
        $this->assertDatabaseMissing('phones', [
            'phoneable_type' => LegalEntity::class,
            'phoneable_id' => $this->legalEntity->id,
            'number' => '+380501234567',
        ]);
    }

    public static function updatedContactPhones(): array
    {
        return [
            'replace and add numbers' => [[
                ['type' => 'MOBILE', 'number' => '+380671112233'],
                ['type' => 'LAND_LINE', 'number' => '+380442223344'],
            ]],
            'replace with one number' => [[
                ['type' => 'LAND_LINE', 'number' => '+380442223344'],
            ]],
            'clear numbers' => [[]],
        ];
    }

    #[DataProvider('providedOptionalSections')]
    public function test_sync_refreshes_only_optional_sections_accepted_by_existing_filter(
        bool $hasAccreditation,
        bool $hasArchive
    ): void {
        $component = $this->detailsComponent();
        $this->fillOptionalDetails($component);
        if (!$hasAccreditation) {
            $component->legalEntityForm->accreditation = $this->emptyAccreditation();
        }
        if (!$hasArchive) {
            $component->legalEntityForm->archive = [];
        }
        $this->fakeDetails($this->updatedLicense(), $this->optionalDetails());

        $component->sync();

        $this->assertSame(__('forms.sync_successfull'), session('success'));
        $this->assertSame($hasAccreditation, $component->legalEntityForm->accreditationShow);
        $this->assertSame($hasArchive, $component->legalEntityForm->archivationShow);
        $this->assertEquals($hasAccreditation ? [
            'category' => 'FIRST',
            'orderNo' => 'NEW-ACCREDITATION',
            'orderDate' => '02.02.2026',
            'issuedDate' => '03.02.2026',
            'expiryDate' => '04.02.2030',
        ] : $this->emptyAccreditation(), $component->legalEntityForm->accreditation);
        $this->assertSame($hasArchive ? [
            ['date' => '05.02.2026', 'place' => 'New archive'],
            ['date' => '06.02.2026', 'place' => 'Second archive'],
        ] : [], $component->legalEntityForm->archive);

        $saved = $this->legalEntity->fresh();
        $this->assertSame($hasAccreditation ? 'FIRST' : null, $saved->accreditation['category'] ?? null);
        $this->assertSame($hasArchive ? 'New archive' : null, $saved->archive[0]['place'] ?? null);
    }

    public static function providedOptionalSections(): array
    {
        return [
            'both provided' => [true, true],
            'only accreditation provided' => [true, false],
            'only archive provided' => [false, true],
            'neither provided despite echoed eHealth values' => [false, false],
        ];
    }

    public function test_sync_clears_displayed_optional_sections_when_saved_data_is_empty(): void
    {
        $component = $this->detailsComponent();
        $this->fillOptionalDetails($component);
        $this->fakeDetails($this->updatedLicense(), ['accreditation' => null, 'archive' => null]);

        $component->sync();

        $this->assertSame(__('forms.sync_successfull'), session('success'));
        $this->assertFalse($component->legalEntityForm->accreditationShow);
        $this->assertFalse($component->legalEntityForm->archivationShow);
        $this->assertSame($this->emptyAccreditation(), $component->legalEntityForm->accreditation);
        $this->assertSame([], $component->legalEntityForm->archive);
    }

    public function test_sync_clears_old_accreditation_dates_missing_from_response(): void
    {
        $component = $this->detailsComponent();
        $this->fillOptionalDetails($component);
        $this->fakeDetails($this->updatedLicense(), [
            'accreditation' => [
                'category' => 'NO_ACCREDITATION',
                'order_no' => 'NEW-ORDER',
            ],
        ]);

        $component->sync();

        $this->assertSame(__('forms.sync_successfull'), session('success'));
        $this->assertTrue($component->legalEntityForm->accreditationShow);
        $this->assertSame([
            'category' => 'NO_ACCREDITATION',
            'orderNo' => 'NEW-ORDER',
            'orderDate' => null,
            'issuedDate' => null,
            'expiryDate' => null,
        ], $component->legalEntityForm->accreditation);
    }

    #[DataProvider('additionalInformationChanges')]
    public function test_sync_refreshes_additional_information(
        bool $hasPreviousValues,
        ?string $beneficiary,
        ?string $receiverFundsCode,
        ?string $expectedBeneficiary,
        ?string $expectedReceiverFundsCode
    ): void {
        $component = $this->detailsComponent();
        if ($hasPreviousValues) {
            $this->fillOptionalDetails($component);
        }
        $this->fakeDetails($this->updatedLicense(), [
            'beneficiary' => $beneficiary,
            'receiver_funds_code' => $receiverFundsCode,
        ]);

        $component->sync();

        $this->assertSame(__('forms.sync_successfull'), session('success'));
        $this->assertSame($expectedBeneficiary, $component->legalEntityForm->beneficiary);
        $this->assertSame($expectedReceiverFundsCode, $component->legalEntityForm->receiverFundsCode);
        $this->assertSame(!empty($expectedBeneficiary), $component->legalEntityForm->beneficiaryShow);
        $this->assertSame(!empty($expectedReceiverFundsCode), $component->legalEntityForm->receiverFundsCodeShow);
        $this->assertDatabaseHas('legal_entities', [
            'id' => $this->legalEntity->id,
            'beneficiary' => $expectedBeneficiary,
            'receiver_funds_code' => $expectedReceiverFundsCode,
        ]);
    }

    public static function additionalInformationChanges(): array
    {
        return [
            'updated values' => [true, 'New beneficiary', '1234567', 'New beneficiary', '1234567'],
            'values excluded by existing rules' => [false, 'New beneficiary', '1234567', null, null],
            'cleared values' => [true, null, null, null, null],
        ];
    }

    public function test_failed_sync_preserves_displayed_form_and_optional_data(): void
    {
        $component = $this->detailsComponent();
        $this->fillOptionalDetails($component);
        $originalForm = $component->legalEntityForm->toArray();
        $originalEntity = $this->legalEntity->fresh()->only(['accreditation', 'archive', 'beneficiary', 'receiver_funds_code']);
        $originalAddress = $component->address;
        $savedAddress = $this->legalEntity->addresses()->first()->toArray();
        $savedPhones = $this->legalEntity->phones()->get()->toArray();
        $api = Mockery::mock(LegalEntityApi::class);
        $api->shouldReceive('getDetails')->once()->andThrow(new RuntimeException('Request failed'));
        $this->instance(LegalEntityApi::class, $api);

        $component->sync();

        $this->assertSame(__('legal-entity.request.sync.errors.fail'), session('error'));
        $this->assertSame($originalForm, $component->legalEntityForm->toArray());
        $this->assertSame($originalEntity, $this->legalEntity->fresh()->only(array_keys($originalEntity)));
        $this->assertSame($originalAddress, $component->address);
        $this->assertSame($savedAddress, $this->legalEntity->addresses()->first()->toArray());
        $this->assertSame($savedPhones, $this->legalEntity->phones()->get()->toArray());
        $this->assertSame('OLD-123', $this->license->fresh()->license_number);
        $this->assertSame(JobStatus::FAILED->value, $this->legalEntity->fresh()->sync_status);
    }

    public function test_denied_sync_does_not_request_or_change_details(): void
    {
        $component = $this->detailsComponent(denied: true);
        $this->fillOptionalDetails($component);
        $originalForm = $component->legalEntityForm->toArray();
        $originalEntity = $this->legalEntity->fresh()->only(['accreditation', 'archive', 'beneficiary', 'receiver_funds_code']);
        $originalAddress = $component->address;
        $savedAddress = $this->legalEntity->addresses()->first()->toArray();
        $savedPhones = $this->legalEntity->phones()->get()->toArray();
        $api = Mockery::mock(LegalEntityApi::class);
        $api->shouldNotReceive('getDetails');
        $this->instance(LegalEntityApi::class, $api);

        $component->sync();

        $this->assertSame(__('legal-entity.policy.deny.sync'), session('error'));
        $this->assertSame($originalForm, $component->legalEntityForm->toArray());
        $this->assertSame($originalEntity, $this->legalEntity->fresh()->only(array_keys($originalEntity)));
        $this->assertSame($originalAddress, $component->address);
        $this->assertSame($savedAddress, $this->legalEntity->addresses()->first()->toArray());
        $this->assertSame($savedPhones, $this->legalEntity->phones()->get()->toArray());
        $this->assertSame('OLD-123', $this->license->fresh()->license_number);
        $this->assertSame(JobStatus::COMPLETED->value, $this->legalEntity->fresh()->sync_status);
    }

    private function detailsComponent(
        string $entityType = LegalEntity::TYPE_PRIMARY_CARE,
        string $licenseType = 'MSP',
        bool $denied = false
    ): LegalEntityDetails {
        $type = LegalEntityType::firstOrCreate(['name' => $entityType]);
        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_type_id' => $type->id,
            'status' => 'ACTIVE',
            'sync_status' => JobStatus::COMPLETED->value,
        ]);
        $this->app->instance('legalEntity', $this->legalEntity);
        $this->license = $this->legalEntity->licenses()->create([
            'uuid' => (string) Str::uuid(),
            'type' => $licenseType,
            'license_number' => 'OLD-123',
            'issued_by' => 'Original issuer',
            'issued_date' => '2025-01-01',
            'active_from_date' => '2025-01-01',
            'order_no' => 'OLD-ORDER',
            'what_licensed' => 'Original activity',
            'expiry_date' => '2027-01-01',
            'is_primary' => true,
        ]);
        $address = $this->legalEntity->addresses()->create([
            'type' => 'RESIDENCE',
            'country' => 'UA',
            'area' => 'КИЇВСЬКА',
            'region' => 'Бучанський',
            'settlement' => 'Ірпінь',
            'settlement_type' => 'CITY',
            'settlement_id' => 'd297b73c-431a-4693-9a6c-daf2d4aa493e',
            'street_type' => 'STREET',
            'street' => 'Соборна',
            'building' => '1',
            'apartment' => '2',
            'zip' => '08200',
        ]);
        $phone = $this->legalEntity->phones()->create([
            'type' => 'MOBILE',
            'number' => '+380501234567',
        ]);
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('cannot')->with('sync', Mockery::type(LegalEntity::class))->once()->andReturn($denied);
        $user->shouldReceive('can')->with('syncLegators', Mockery::type(LegalEntity::class))->andReturnFalse();
        $this->actingAs($user, 'ehealth');

        // Restore the form as Livewire does on an action request, without mounting the page again.
        $component = new LegalEntityDetails();
        $component->boot(Repository::address(), Repository::phone());
        $component->legalEntityForm = new LegalEntitiesForms($component, 'legalEntityForm');
        $component->address = $address->toArray();
        $component->legalEntityForm->residenceAddress = $component->address;
        $component->legalEntityForm->phones = [$phone->toArray()];
        $component->legalEntityForm->license = [
            'type' => $licenseType,
            'licenseNumber' => 'OLD-123',
            'expiryDate' => '01.01.2027',
        ];

        return $component;
    }

    private function updatedLicense(string $type = 'MSP'): array
    {
        return [
            'uuid' => $this->license->uuid,
            'type' => $type,
            'license_number' => 'NEW-456',
            'issued_by' => 'Updated issuer',
            'issued_date' => '2026-02-02',
            'expiry_date' => '2030-03-03',
            'active_from_date' => '2026-02-04',
            'what_licensed' => 'Updated activity',
            'order_no' => 'NEW-ORDER',
        ];
    }

    private function emptyAccreditation(): array
    {
        return [
            'category' => null,
            'orderNo' => null,
            'orderDate' => null,
            'issuedDate' => null,
            'expiryDate' => null,
        ];
    }

    private function optionalDetails(): array
    {
        return [
            'accreditation' => [
                'category' => 'FIRST',
                'order_no' => 'NEW-ACCREDITATION',
                'order_date' => '2026-02-02',
                'issued_date' => '2026-02-03',
                'expiry_date' => '2030-02-04',
            ],
            'archive' => [
                ['date' => '2026-02-05', 'place' => 'New archive'],
                ['date' => '2026-02-06', 'place' => 'Second archive'],
            ],
        ];
    }

    private function fillOptionalDetails(LegalEntityDetails $component): void
    {
        $this->legalEntity->update([
            'accreditation' => [
                'category' => 'SECOND',
                'order_no' => 'OLD-ACCREDITATION',
                'order_date' => '2025-01-01',
                'issued_date' => '2025-01-02',
                'expiry_date' => '2027-01-03',
            ],
            'archive' => [['date' => '2025-01-04', 'place' => 'Old archive']],
            'beneficiary' => 'Old beneficiary',
            'receiver_funds_code' => '7654321',
        ]);
        $component->legalEntityForm->accreditation = [
            'category' => 'SECOND',
            'orderNo' => 'OLD-ACCREDITATION',
            'orderDate' => '01.01.2025',
            'issuedDate' => '02.01.2025',
            'expiryDate' => '03.01.2027',
        ];
        $component->legalEntityForm->archive = [['date' => '04.01.2025', 'place' => 'Old archive']];
        $component->legalEntityForm->accreditationShow = true;
        $component->legalEntityForm->archivationShow = true;
        $component->legalEntityForm->beneficiary = 'Old beneficiary';
        $component->legalEntityForm->receiverFundsCode = '7654321';
        $component->legalEntityForm->beneficiaryShow = true;
        $component->legalEntityForm->receiverFundsCodeShow = true;
    }

    private function fakeDetails(array $license, array $details = []): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(array_replace([
            'uuid' => $this->legalEntity->uuid,
            'type' => $this->legalEntity->type->name,
            'status' => 'ACTIVE',
            'license' => $license,
            'residence_address' => [
                'type' => 'RESIDENCE',
                'country' => 'UA',
                'area' => 'Київська',
                'settlement' => 'Київ',
            ],
            'phones' => [],
        ], $details));
        $api = Mockery::mock(LegalEntityApi::class);
        $api->shouldReceive('getDetails')->once()->andReturn($response);
        $this->instance(LegalEntityApi::class, $api);
    }
}
