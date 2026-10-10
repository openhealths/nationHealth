<?php

declare(strict_types=1);

namespace Tests\Feature\Specimen;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\Cipher\CipherResponse;
use App\Classes\eHealth\Api\Patient\Specimen as SpecimenApi;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Livewire\Specimen\Forms\SpecimenActionForm;
use App\Livewire\Specimen\Forms\SpecimenCancellationForm;
use App\Livewire\Specimen\Forms\SpecimenForm;
use App\Livewire\Specimen\SpecimenCancellation;
use App\Livewire\Specimen\SpecimenCreate;
use App\Livewire\Specimen\SpecimenIndex;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\Relations\Party;
use App\Models\User;
use App\Rules\InDictionary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use Tests\TestCase;

class SpecimenMappingWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private array $dictionaryCache;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y', 'ehealth.specimen_max_days_passed' => 30, 'logging.default' => 'null']);
        date_default_timezone_set('Europe/Kyiv');
        Carbon::setTestNow('2026-10-06 12:00:00');
        $cache = new ReflectionProperty(InDictionary::class, 'dictionaryCache');
        $this->dictionaryCache = $cache->getValue();
        $cache->setValue(null, [
            'specimen_types' => ['blood'], 'specimen_conditions' => ['normal'],
            'specimen_reject_reasons' => ['unsuitable'], 'specimen_invalidate_reasons' => ['used'],
            'specimen_cancel_reasons' => ['incorrect_data'],
        ]);
        $this->instance('legalEntity', new LegalEntity()->forceFill(['uuid' => (string) Str::uuid()]));
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(InDictionary::class, 'dictionaryCache')->setValue(null, $this->dictionaryCache);
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('patientTypes')]
    public function test_draft_save_maps_validated_form_preserves_uuid_and_ignores_unvalidated_status_fields(string $patientType): void
    {
        $this->authorize('create');
        $component = $this->creation($patientType);
        $original = $component->form->specimen;
        $component->save();
        $this->assertSame($original, $component->form->specimen);
        $stored = Specimen::firstOrFail();
        $this->assertTrue(Str::isUuid($stored->uuid));
        $this->assertSame('draft', $stored->status->value);
        $this->assertNull($stored->receivedTime);
        $this->assertNull($stored->statusReason);
        $this->assertSame('blood', $stored->type->coding->first()->code);
        $this->assertSame('tube-1', $stored->container->first()->identifier);
        $this->assertSame($component->patientUuid, $stored->collection->collector->value);
        $this->assertSame(
            $patientType === Person::class ? $component->personId : $component->prepersonId,
            $stored->getRawOriginal($patientType === Person::class ? 'person_id' : 'preperson_id')
        );
        $this->assertSame($patientType === Person::class ? 'persons.specimens.edit' : 'prepersons.specimens.edit', $component->redirects[0][0]);
        $component->specimenId = $stored->uuid;
        $component->form->specimen['note'] = 'Оновлений чернетковий запис';
        $component->save();
        $this->assertSame(1, Specimen::count());
        $this->assertSame('Оновлений чернетковий запис', $stored->fresh()->note);
        $this->assertSame($stored->id, $component->redirects[1][1]['specimenId']);
        Http::assertNothingSent();
    }

    public function test_invalid_or_unauthorized_draft_never_reaches_persistence(): void
    {
        $this->authorize('create');
        $component = $this->creation(Person::class);
        $component->form->specimen['collectorId'] = (string) Str::uuid();
        $component->save();
        $this->assertTrue($component->getErrorBag()->has('form.specimen.collectorId'));
        $this->assertSame(0, Specimen::count());
        $this->authorize('create', allowed: false);
        $component->form->specimen = [];
        $component->save();
        $this->assertSame(__('specimens.policy.create'), Session::get('error'));
        $this->assertSame(0, Specimen::count());
        Http::assertNothingSent();
    }

    #[DataProvider('transitions')]
    public function test_actual_action_maps_validated_form_and_calls_existing_api(string $operation, array $expected): void
    {
        $this->authorize($operation);
        $component = $this->action();
        $component->form->receivedDate = '05.10.2026';
        $component->form->receivedTime = '11:00';
        $component->form->invalidateReason = 'used';
        $component->form->rejectReason = 'unsuitable';
        $property = 'show'.ucfirst($operation).'Modal';
        $component->$property = true;
        $api = $this->mock(SpecimenApi::class);
        $api->shouldReceive($operation)->once()->with('patient', 'specimen', $expected)->andReturn($this->job());
        $component->$operation();
        $this->assertFalse($component->$property);
        $this->assertNotNull(Session::get('success'));
        Http::assertNothingSent();
    }

    public function test_process_validates_received_time_against_period_before_calling_api(): void
    {
        $this->authorize('process');
        $component = $this->action();
        $component->specimen['collection'] = ['collectedPeriod' => ['end' => '2026-10-05T07:00:00Z']];
        $component->form->receivedDate = '05.10.2026';
        $component->form->receivedTime = '09:59';
        $this->mock(SpecimenApi::class)->shouldNotReceive('process');
        try {
            $component->process();
            $this->fail('Receiving a specimen before collection must fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form.receivedTime', $exception->errors());
        }
        Http::assertNothingSent();
    }

    #[DataProvider('operationNames')]
    public function test_unauthorized_transition_returns_before_validation_or_api(string $operation): void
    {
        $this->authorize($operation, allowed: false);
        $component = $this->action();
        $this->mock(SpecimenApi::class)->shouldNotReceive($operation);
        $component->$operation();
        $this->assertSame(__('specimens.policy.'.$operation), Session::get('error'));
        Http::assertNothingSent();
    }

    public function test_search_keeps_api_validation_and_camel_case_conversion(): void
    {
        $component = $this->action();
        $component->searchId = '0000-0000-0000-0000';
        $raw = ['uuid' => 'specimen', 'received_time' => '2026-10-05T08:00:00Z'];
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn($raw);
        $this->mock(SpecimenApi::class)->shouldReceive('getByAccessionIdentifier')->once()
            ->with($component->searchId)->andReturn($response);
        $component->search();
        $this->assertSame(Arr::toCamelCase($raw), $component->specimen);
        Http::assertNothingSent();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_signed_create_uses_validated_payload_and_updates_existing_draft(): void
    {
        $this->authorize('create');
        $component = $this->creation(Person::class);
        $component->save();
        $stored = Specimen::firstOrFail();
        $component->specimenId = $stored->uuid;
        $this->signingFields($component->form);
        $original = $component->form->specimen;
        $cipher = Mockery::mock('overload:'.CipherRequest::class);
        $cipher->shouldReceive('signData')->once()->withArgs(function ($payload, $knedp, $key, $password, $taxId) use ($stored): bool {
            $this->assertSame($stored->uuid, $payload['id']);
            $this->assertSame('available', $payload['status']);
            $this->assertArrayNotHasKey('received_time', $payload);
            $this->assertArrayNotHasKey('status_reason', $payload);
            $this->assertArrayNotHasKey('unknown', $payload);
            $this->assertArrayNotHasKey('context', $payload);
            $this->assertSame('test-knedp', $knedp);
            $this->assertInstanceOf(TemporaryUploadedFile::class, $key);
            $this->assertSame('synthetic-password', $password);
            $this->assertSame('1234567890', $taxId);

            return true;
        })->andReturn($this->signed());
        $this->mock(SpecimenApi::class)->shouldReceive('create')->once()
            ->with($component->patientUuid, ['signed_data' => 'signed-fixture', 'signed_data_encoding' => 'base64'])->andReturn($this->job());
        $component->sign();
        $this->assertSame($original, $component->form->specimen);
        $this->assertSame('available', $stored->fresh()->status->value);
        $this->assertSame(1, Specimen::count());
        $this->assertSame('', $component->form->password);
        $this->assertFalse(isset($component->form->keyContainerUpload));
        Http::assertNothingSent();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_cancellation_signs_full_raw_snapshot_after_validation_and_policy(): void
    {
        $this->authorize('cancel');
        $component = new SpecimenCancellation();
        $component->form = new SpecimenCancellationForm($component, 'form');
        $component->patientId = 'patient';
        $component->specimenId = 'specimen';
        $component->showSignatureModal = true;
        $component->form->cancellationReason = 'incorrect_data';
        $this->signingFields($component->form);
        $snapshot = [
            'id' => 'specimen', 'status' => 'available', 'literal_key' => null,
            'unknown' => ['false' => false, 'zero' => 0, 'list' => [42 => 'retained'], 'object' => new \stdClass()],
        ];
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(['uuid' => 'specimen', 'status' => 'available']);
        $response->shouldReceive('getData')->once()->andReturn($snapshot);
        $api = $this->mock(SpecimenApi::class);
        $api->shouldReceive('getDetails')->once()->with('patient', 'specimen')->andReturn($response);
        $api->shouldReceive('cancel')->once()->with('patient', 'specimen', [
            'signed_data' => 'signed-fixture', 'signed_data_encoding' => 'base64',
        ])->andReturn($this->job());
        Mockery::mock('overload:'.CipherRequest::class)->shouldReceive('signData')->once()
            ->withArgs(function ($payload) use ($snapshot): bool {
                $this->assertSame('entered_in_error', $payload['status']);
                $this->assertSame(['coding' => [['system' => 'specimen_cancel_reasons', 'code' => 'incorrect_data']], 'text' => ''], $payload['status_reason']);
                $this->assertSame($snapshot, array_replace(array_diff_key($payload, ['status_reason' => true]), ['status' => 'available']));
                foreach (['id', 'literal_key', 'unknown'] as $key) {
                    $this->assertSame($snapshot[$key], $payload[$key]);
                }

                return true;
            })->andReturn($this->signed());
        $component->cancel();
        $this->assertFalse($component->showSignatureModal);
        $this->assertSame('', $component->specimenId);
        $this->assertSame('', $component->form->password);
        $this->assertFalse(isset($component->form->keyContainerUpload));
        Http::assertNothingSent();
    }

    private function authorize(string $operation, bool $allowed = true): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRelation('party', new Party()->forceFill(['tax_id' => '1234567890']));
        $user->shouldReceive('cannot')->with($operation, Mockery::any())->andReturn(!$allowed);
        $manager = Mockery::mock(\Illuminate\Auth\AuthManager::class);
        $manager->shouldReceive('user')->andReturn($user);
        Auth::swap($manager);
    }

    private function creation(string $patientType): SpecimenCreateHarness
    {
        $patient = $patientType::create([
            'uuid' => (string) Str::uuid(), 'gender' => 'MALE', 'birth_date' => '1990-01-01',
            ...($patientType === Person::class ? ['patient_signed' => true, 'process_disclosure_data_consent' => true] : []),
        ]);
        $component = new SpecimenCreateHarness();
        $component->form = new SpecimenForm($component, 'form');
        $component->patientUuid = $patient->uuid;
        if ($patientType === Person::class) {
            $component->personId = $patient->id;
        } else {
            $component->prepersonId = $patient->id;
        }
        $employee = (string) Str::uuid();
        $component->registeredByEmployees = [['uuid' => $employee]];
        $component->form->specimen = [
            'registeredById' => $employee, 'typeCode' => 'blood', 'collectorType' => 'patient',
            'collectorId' => $patient->uuid, 'collectedType' => 'date_time',
            'collectedDate' => '05.10.2026', 'collectedTime' => '10:15',
            'containers' => [42 => ['identifier' => 'tube-1']],
            'isReferenced' => true, 'receivedDate' => '05.10.2026', 'receivedTime' => '11:00',
            'unknown' => 'ignored',
        ];

        return $component;
    }

    private function action(): SpecimenIndex
    {
        $component = new SpecimenIndex();
        $component->form = new SpecimenActionForm($component, 'form');
        $component->specimen = ['uuid' => 'specimen', 'subject' => ['identifier' => ['value' => 'patient']], 'collection' => ['collectedDateTime' => '2026-10-05T07:00:00Z']];

        return $component;
    }

    private function job(): EHealthResponse
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn(['id' => 'job']);

        return $response;
    }

    private function signed(): CipherResponse
    {
        return new CipherResponse(new \Illuminate\Http\Client\Response(Http::response(['base64Data' => 'signed-fixture'])->wait()));
    }

    private function signingFields(SpecimenForm|SpecimenCancellationForm $form): void
    {
        config(['livewire.temporary_file_upload.disk' => 'local']);
        Storage::fake('local');
        Storage::fake('tmp-for-tests');
        $filename = TemporaryUploadedFile::generateHashNameWithOriginalNameEmbedded(UploadedFile::fake()->create('key.dat'));
        Storage::disk('tmp-for-tests')->put('livewire-tmp/'.$filename, 'synthetic-key');
        $form->keyContainerUpload = TemporaryUploadedFile::createFromLivewire($filename);
        $form->knedp = 'test-knedp';
        $form->password = 'synthetic-password';
    }

    public static function patientTypes(): iterable
    {
        yield 'Person' => [Person::class];
        yield 'Preperson' => [Preperson::class];
    }

    public static function transitions(): iterable
    {
        yield 'process' => ['process', ['received_time' => '2026-10-05T08:00:00Z']];
        yield 'reject' => ['reject', ['status_reason' => ['coding' => [['system' => 'specimen_reject_reasons', 'code' => 'unsuitable']], 'text' => '']]];
        yield 'invalidate' => ['invalidate', ['status_reason' => ['coding' => [['system' => 'specimen_invalidate_reasons', 'code' => 'used']], 'text' => '']]];
    }

    public static function operationNames(): iterable
    {
        foreach (['process', 'reject', 'invalidate'] as $operation) {
            yield $operation => [$operation];
        }
    }
}

/** Capture navigation while exercising the real save/sign handlers without rendering the page shell. */
class SpecimenCreateHarness extends SpecimenCreate
{
    public array $redirects = [];

    public function redirectRoute($name, $parameters = [], $absolute = true, $navigate = false): void
    {
        $this->redirects[] = [$name, $parameters, $navigate];
    }
}
