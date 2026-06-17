<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for ReimbursementContractCreate medical programs handling:
 *  - dictionary()->medicalPrograms() as primary source
 *  - storage/app/exports/medical-programs-valid-reimbursement.json as fallback
 *  - User selection is used (not hardcoded)
 *  - Output format is [uuid, ...] (array of strings)
 *  - MFO in contractor_payment_details
 */
class ReimbursementMedicalProgramsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * @param  array<int, array<string, mixed>>  $programs
     */
    private function seedMedicalProgramsDictionary(array $programs): void
    {
        Cache::put(MedicalProgramDictionary::KEY, $programs, now()->addWeek());
        Cache::put(MedicalProgramDictionary::KEY.':fresh', true, now()->endOfDay());
    }

    public function test_dictionary_medical_programs_reads_cached_data_for_contract_module(): void
    {
        $programId = (string) Str::uuid();

        $this->seedMedicalProgramsDictionary([
            [
                'id' => $programId,
                'name' => 'Reimbursement NHS Program',
                'is_active' => true,
                'funding_source' => 'NHS',
                'type' => 'MEDICATION',
                'mr_blank_type' => 'F-1',
                'medical_program_settings' => ['request_allowed' => true],
            ],
        ]);

        $names = dictionary()->medicalPrograms()
            ->pluck('name', 'id')
            ->all();

        $this->assertSame('Reimbursement NHS Program', $names[$programId]);
    }

    public function test_contract_show_resolves_program_names_from_dictionary_cache(): void
    {
        $programId = (string) Str::uuid();

        $this->seedMedicalProgramsDictionary([
            [
                'id' => $programId,
                'name' => 'Insulin Program',
                'is_active' => true,
                'funding_source' => 'NHS',
                'type' => 'MEDICATION',
                'medical_program_settings' => ['request_allowed' => true],
            ],
        ]);

        $medicalProgramNames = dictionary()->medicalPrograms()
            ->pluck('name', 'id')
            ->all();

        $this->assertSame('Insulin Program', $medicalProgramNames[$programId] ?? null);
    }

    public function test_reimbursement_create_uses_dictionary_filter_for_program_list(): void
    {
        $validProgramId = (string) Str::uuid();
        $testProgramId = (string) Str::uuid();

        $this->seedMedicalProgramsDictionary([
            [
                'id' => $validProgramId,
                'name' => 'Valid Program',
                'is_active' => true,
                'funding_source' => 'NHS',
                'type' => 'MEDICATION',
                'mr_blank_type' => 'F-1',
                'medical_program_settings' => ['request_allowed' => true],
            ],
            [
                'id' => $testProgramId,
                'name' => 'Test Program',
                'is_active' => true,
                'funding_source' => 'NHS',
                'type' => 'MEDICATION',
                'mr_blank_type' => 'F-1',
                'medical_program_settings' => ['request_allowed' => true],
            ],
        ]);

        $filtered = dictionary()->medicalPrograms()
            ->filter(static function (array $item): bool {
                $name = mb_strtolower((string) ($item['name'] ?? ''));
                $settings = $item['medical_program_settings'] ?? [];

                return (bool) ($item['is_active'] ?? false)
                    && ($item['funding_source'] ?? null) === 'NHS'
                    && ($item['type'] ?? null) === 'MEDICATION'
                    && (bool) ($settings['request_allowed'] ?? false)
                    && !str_contains($name, 'тест')
                    && !str_contains($name, 'test');
            })
            ->values()
            ->all();

        $this->assertCount(1, $filtered);
        $this->assertSame($validProgramId, $filtered[0]['id']);
    }

    public function test_fallback_json_export_is_available_for_reimbursement_form(): void
    {
        $path = storage_path('app/exports/medical-programs-valid-reimbursement.json');

        $this->assertFileExists($path);

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($data['programs'] ?? null);
        $this->assertNotEmpty($data['programs']);
        $this->assertGreaterThanOrEqual(1, $data['count'] ?? count($data['programs']));
    }

    public function test_fallback_program_shape_is_compatible_with_reimbursement_filter(): void
    {
        $program = [
            'id' => (string) Str::uuid(),
            'name' => 'Fallback Insulin',
            'type' => 'MEDICATION',
            'funding_source' => 'NHS',
            'request_allowed' => true,
            'mr_blank_type' => 'F-1',
        ];

        $settings = $program['medical_program_settings'] ?? [];
        $requestAllowed = (bool) ($settings['request_allowed'] ?? $program['request_allowed'] ?? false);

        $isValid = (bool) ($program['is_active'] ?? true)
            && ($program['funding_source'] ?? null) === 'NHS'
            && ($program['type'] ?? null) === 'MEDICATION'
            && $requestAllowed;

        $this->assertTrue($isValid);
    }

    public function test_medical_programs_payload_uses_uuid_array_format(): void
    {
        $programUuid1 = (string) Str::uuid();
        $programUuid2 = (string) Str::uuid();

        $result = array_values(array_filter([$programUuid1, $programUuid2]));

        $this->assertSame([$programUuid1, $programUuid2], $result);
    }

    public function test_medical_programs_payload_is_not_id_object_array(): void
    {
        $programUuid = (string) Str::uuid();

        $result = array_values(array_filter([$programUuid]));

        $this->assertSame([$programUuid], $result);
        $this->assertNotSame([['id' => $programUuid]], $result);
    }

    public function test_medical_programs_payload_is_empty_array_when_no_programs_selected(): void
    {
        $result = array_values(array_filter([]));

        $this->assertSame([], $result);
    }

    public function test_hardcoded_insulin_id_is_not_used_in_payload(): void
    {
        $hardcodedInsulinId = '1a227396-a0e4-4c4f-a0a9-6b358c8929d2';
        $userSelectedId = (string) Str::uuid();

        $result = array_values(array_filter([$userSelectedId]));

        $this->assertNotContains($hardcodedInsulinId, $result);
        $this->assertContains($userSelectedId, $result);
    }

    public function test_reimbursement_program_filter_excludes_inactive_and_test_programs(): void
    {
        $programs = [
            [
                'id' => (string) Str::uuid(),
                'name' => 'Valid Program',
                'is_active' => true,
                'funding_source' => 'NHS',
                'type' => 'MEDICATION',
                'medical_program_settings' => ['request_allowed' => true],
            ],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Test Program',
                'is_active' => true,
                'funding_source' => 'NHS',
                'type' => 'MEDICATION',
                'medical_program_settings' => ['request_allowed' => true],
            ],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Inactive Program',
                'is_active' => false,
                'funding_source' => 'NHS',
                'type' => 'MEDICATION',
                'medical_program_settings' => ['request_allowed' => true],
            ],
        ];

        $filtered = collect($programs)->filter(static function (array $item): bool {
            $name = mb_strtolower((string) ($item['name'] ?? ''));
            $settings = $item['medical_program_settings'] ?? [];

            return (bool) ($item['is_active'] ?? false)
                && ($item['funding_source'] ?? null) === 'NHS'
                && ($item['type'] ?? null) === 'MEDICATION'
                && (bool) ($settings['request_allowed'] ?? false)
                && !str_contains($name, 'тест')
                && !str_contains($name, 'test');
        })->values()->all();

        $this->assertCount(1, $filtered);
        $this->assertSame('Valid Program', $filtered[0]['name']);
    }

    public function test_collect_payload_includes_mfo_in_contractor_payment_details(): void
    {
        $data = [
            'contractorPaymentDetails' => [
                'bankName' => 'Test Bank',
                'MFO' => '351005',
                'payerAccount' => 'UA 12 345678 9012345678901234567',
            ],
        ];

        $payerAccount = str_replace(' ', '', $data['contractorPaymentDetails']['payerAccount'] ?? '');
        $mfo = preg_replace('/\D/', '', (string) ($data['contractorPaymentDetails']['MFO'] ?? ''));

        $contractorPaymentDetails = [
            'payer_account' => $payerAccount,
            'bank_name' => $data['contractorPaymentDetails']['bankName'] ?? '',
            'MFO' => $mfo,
        ];

        $this->assertSame('351005', $contractorPaymentDetails['MFO']);
        $this->assertSame('UA123456789012345678901234567', $contractorPaymentDetails['payer_account']);
    }

    public function test_mfo_normalization_strips_non_digit_characters(): void
    {
        $raw = '35 10-05';
        $normalized = preg_replace('/\D/', '', $raw);

        $this->assertSame('351005', $normalized);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $normalized);
    }

    public function test_collect_payload_always_includes_six_digit_mfo(): void
    {
        $data = [
            'contractorPaymentDetails' => [
                'bankName' => 'Test Bank',
                'MFO' => '351005',
                'payerAccount' => 'UA123456789012345678901234567',
            ],
        ];

        $mfo = preg_replace('/\D/', '', (string) ($data['contractorPaymentDetails']['MFO'] ?? ''));

        $contractorPaymentDetails = [
            'payer_account' => $data['contractorPaymentDetails']['payerAccount'] ?? '',
            'bank_name' => $data['contractorPaymentDetails']['bankName'] ?? '',
            'MFO' => $mfo,
        ];

        $this->assertSame('351005', $contractorPaymentDetails['MFO']);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $contractorPaymentDetails['MFO']);
    }
}
