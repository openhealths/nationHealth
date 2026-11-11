<?php

declare(strict_types=1);

namespace Tests\Feature\DeviceRequest;

use App\Classes\eHealth\EHealthResponse;
use App\Jobs\UpdateDictionaryCache;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\Person\Person;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\Dictionary\DictionaryInterface;
use App\Services\Dictionary\DictionaryManager;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\Support\NativeClinicalFixtures as Native;
use Tests\TestCase;

class SharedClinicalBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_malformed_device_details_do_not_create_a_local_request(): void
    {
        $person = Person::create(['uuid' => Native::UUID, 'gender' => 'MALE']);
        $record = Native::deviceRequest(Native::UUID, Native::UUID);
        $record['requester']['identifier']['value'] = null;
        try {
            app(DeviceRequestLifecycleService::class)->sync($person, $record);
            $this->fail('Incomplete remote author must not be imported.');
        } catch (ValidationException) {
            $this->assertSame(0, DeviceRequestRequest::count());
        }
    }

    public function test_another_patient_snapshot_does_not_create_a_local_request(): void
    {
        $person = Person::create(['uuid' => Native::UUID, 'gender' => 'MALE']);
        $record = Native::deviceRequest(Native::UUID, '3bc7a920-53a5-46a6-a88e-b5f298c7b898');
        try {
            app(DeviceRequestLifecycleService::class)->sync($person, $record);
            $this->fail('A different patient must not be imported.');
        } catch (ValidationException) {
            $this->assertSame(0, DeviceRequestRequest::count());
        }
    }

    public function test_invalid_second_catalog_page_preserves_existing_rules(): void
    {
        $key = MedicalProgramDictionary::KEY;
        $existing = [['id' => 'previous-catalog']];
        Cache::put($key, $existing);
        Cache::forget($key.':fresh');
        $native = json_decode(file_get_contents(base_path('tests/Fixtures/shared-official-responses.json')), true, flags: JSON_THROW_ON_ERROR)['GET /api/medical_programs']['data'];
        $bad = $native;
        $bad[0]['is_active'] = 'invalid';
        $responses = [
            Native::response('GET /api/medical_programs', $native, Native::paging(1, 2, 2)),
            Native::response('GET /api/medical_programs', $bad, Native::paging(2, 2, 2)),
        ];
        $dictionary = new class ($responses) implements DictionaryInterface
        {
            public array $requested = [];

            public function __construct(private array $responses)
            {
            }

            public function getKey(): string
            {
                return MedicalProgramDictionary::KEY;
            }

            public function fetch(int $page = 1): EHealthResponse
            {
                $this->requested[] = $page;

                return $this->responses[$page - 1];
            }
        };
        $manager = new DictionaryManager();
        $manager->register($dictionary);
        (new UpdateDictionaryCache($key))->handle($manager);
        $this->assertSame([1, 2], $dictionary->requested);
        $this->assertSame($existing, Cache::get($key));
        $this->assertFalse(Cache::has($key.':fresh'));
    }

    public function test_valid_two_page_catalog_is_published_only_after_complete_validation(): void
    {
        $native = json_decode(file_get_contents(base_path('tests/Fixtures/shared-official-responses.json')), true, flags: JSON_THROW_ON_ERROR)['GET /api/medical_programs']['data'];
        $dictionary = new class ($native) implements DictionaryInterface
        {
            public function __construct(private array $native)
            {
            }

            public function getKey(): string
            {
                return MedicalProgramDictionary::KEY;
            }

            public function fetch(int $page = 1): EHealthResponse
            {
                return Native::response('GET /api/medical_programs', $this->native, Native::paging($page, 2, 2));
            }
        };
        Cache::forget(MedicalProgramDictionary::KEY);
        Cache::forget(MedicalProgramDictionary::KEY.':fresh');
        $manager = new DictionaryManager();
        $manager->register($dictionary);
        $this->assertSame(array_merge($native, $native), $manager->medicalPrograms()->all());
        $this->assertTrue(Cache::get(MedicalProgramDictionary::KEY.':fresh'));
    }
}
