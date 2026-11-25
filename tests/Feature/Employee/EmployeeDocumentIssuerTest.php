<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\Api\EmployeeRequest as EmployeeRequestApi;
use App\Enums\Employee\RequestStatus;
use App\Enums\Employee\RevisionStatus;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Employee\EmployeeRequestEdit;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\Relations\Party;
use App\Models\User;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use App\Services\Dictionary\DictionaryManager;
use App\Services\SignatureService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeDocumentIssuerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_saved_draft_requires_each_issuer_before_saving_or_signing(): void
    {
        config(['ehealth.api.domain' => 'https://ehealth.test']);
        Http::preventStrayRequests();
        $api = $this->partialMock(EmployeeRequestApi::class);
        $api->shouldReceive('create')->once()->with('signed-test')->andReturn([
            'id' => (string) Str::uuid(), 'ehealth_response' => ['data' => ['inserted_at' => now()->toISOString()]],
        ]);
        $this->fakeDictionaries();
        $type = LegalEntityType::firstOrCreate(['name' => 'OUTPATIENT']);
        $entity = LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_type_id' => $type->id, 'is_active' => true,
        ]);
        $this->instance('legalEntity', $entity);
        $partyData = [
            'tax_id' => '1234567890', 'first_name' => 'Тест', 'last_name' => 'Посада', 'second_name' => null,
            'birth_date' => '1990-01-01', 'gender' => 'MALE', 'no_tax_id' => false, 'working_experience' => 1,
        ];
        $party = Party::create(['uuid' => (string) Str::uuid()] + $partyData);
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'party_id' => $party->id,
            'email' => Str::uuid().'@example.test', 'password' => bcrypt('test'),
        ]);
        $this->actingAs($user);
        Gate::before(fn (): bool => true);
        $signature = $this->mock(SignatureService::class);
        $signature->shouldReceive('getCertificateAuthorities')->andReturn([]);
        $signature->shouldReceive('signData')->once()->withArgs(
            fn (array $payload): bool => $payload['employee_request']['party']['documents'][1]['issued_by'] === 'МВС'
        )->andReturn('signed-test');

        $position = [
            'employee_type' => 'HR', 'position' => config('ehealth.employee_type.HR.position')[0],
            'start_date' => '2024-01-01',
        ];
        $draft = EmployeeRequest::create($position + [
            'legal_entity_id' => $entity->id, 'status' => RequestStatus::NEW,
        ]);
        $draft->revision()->create(['status' => RevisionStatus::PENDING, 'data' => [
            'employee_request_data' => $position,
            'party' => $partyData + ['email' => $user->email],
            'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
            'documents' => [
                ['type' => 'NATIONAL_ID', 'number' => '123456789', 'issued_by' => 'ДМС', 'issued_at' => '2020-01-01'],
                ['type' => 'TEMPORARY_CERTIFICATE', 'number' => 'АА123456', 'issued_at' => '2020-01-01'],
            ],
        ]]);

        $issuer = 'form.documents.1.issuedBy';
        $component = Livewire::test(EmployeeRequestEdit::class, ['legalEntity' => $entity, 'employee_request' => $draft])
            ->call('save')->assertHasErrors($issuer)->assertDispatched('validation-failed-scroll', firstErrorKey: $issuer)
            ->call('prepareForSigning')->assertHasErrors($issuer)->assertNotDispatched('open-request-preview-modal')
            ->assertSee('АА123456 — '.__('validation.custom.employee.document_issuer_required'));
        $component->set('form.knedp', 'test')->set('form.password', 'test')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('test.dat', 1));
        foreach ([null, '', '   ', "\u{00a0}"] as $blank) {
            $component->set($issuer, $blank)->call('sign')
                ->assertHasErrors($issuer)->assertHasNoErrors('form.documents.0.issuedBy');
        }
        $signature->shouldNotHaveReceived('signData');
        $api->shouldNotHaveReceived('create');
        Http::assertNothingSent();

        $component->set($issuer, 'МВС')->call('save')->assertHasNoErrors()
            ->call('prepareForSigning')->assertHasNoErrors()->assertDispatched('open-request-preview-modal')
            ->call('sign')->assertHasNoErrors()->assertDispatched('close-signature-modal');
        $api->shouldHaveReceived('create')->once()->with('signed-test');
        $this->assertSame(RevisionStatus::SENT, $draft->fresh()->revision->status);
    }

    public function test_ehealth_issuer_error_has_a_translated_message(): void
    {
        $exception = new EHealthValidationException(['error' => ['invalid' => [[
            'entry' => '$.employee_request.party.documents[0].issued_by',
            'rules' => [['rule' => 'required', 'description' => 'required property issued_by was not present']],
        ]]]]);

        $message = $exception->getTranslatedMessage();
        $this->assertStringContainsString(__('validation.custom.employee.document_issuer_required'), $message);
        $this->assertStringNotContainsString('issued_by', $message);
        $this->assertStringNotContainsString('required property', $message);
    }

    protected function fakeDictionaries(): void
    {
        $values = [
            'EMPLOYEE_TYPE' => ['HR' => 'Кадровик'],
            'POSITION' => array_fill_keys(config('ehealth.employee_type.HR.position'), 'Посада'),
            'PHONE_TYPE' => ['MOBILE' => 'Мобільний'],
            'GENDER' => ['MALE' => 'Чоловіча'],
            'COUNTRY' => ['UA' => 'Україна'],
            'DOCUMENT_TYPE' => ['NATIONAL_ID' => 'ID-картка', 'TEMPORARY_CERTIFICATE' => 'Посвідчення'],
            'SETTLEMENT_TYPE' => ['CITY' => 'Місто'],
            'DIVISION_TYPE' => ['AMB' => 'Амбулаторія'],
            'SPECIALITY_TYPE' => ['THERAPIST' => 'Терапевт'],
            'SPECIALITY_LEVEL' => ['FIRST' => 'Перша'],
            'QUALIFICATION_TYPE' => ['TEST' => 'Кваліфікація'],
            'SCIENCE_DEGREE' => ['PHD' => 'Кандидат наук'],
            'SPEC_QUALIFICATION_TYPE' => ['TEST' => 'Кваліфікація'],
            'EDUCATION_DEGREE' => ['MASTER' => 'Магістр'],
        ];
        $items = [];
        foreach ($values as $name => $entries) {
            $items[] = ['name' => $name, 'values' => collect($entries)->map(
                fn ($label, $code) => ['code' => $code, 'description' => $label, 'is_active' => true]
            )->values()->all()];
        }
        $manager = $this->partialMock(DictionaryManager::class);
        $manager->shouldReceive('basics')->andReturn(new BasicDictionaryCollection($items));
    }
}
