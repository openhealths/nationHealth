<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\Api\EmployeeRequest as EmployeeRequestApi;
use App\Contracts\SignatureProvider;
use App\Enums\Employee\RequestStatus;
use App\Enums\Employee\RevisionStatus;
use App\Livewire\Employee\EmployeeRequestEdit;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\FakesEmployeeDictionaries;
use Tests\TestCase;

class EmployeeDocumentIssuerSigningTest extends TestCase
{
    use DatabaseTransactions;
    use FakesEmployeeDictionaries;

    public function test_each_issuer_is_required_in_the_draft_and_in_the_payload_before_signing(): void
    {
        $this->fakeEmployeeDictionaries();
        $entity = LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'is_active' => true,
            'legal_entity_type_id' => LegalEntityType::firstOrCreate(['name' => 'OUTPATIENT'])->id,
        ]);
        $this->instance('legalEntity', $entity);
        $this->actingAs(User::create([
            'email' => Str::uuid().'@example.test', 'password' => bcrypt('test'),
            'party_id' => Party::factory()->create(['tax_id' => '1234567890'])->id,
        ]));
        Gate::before(fn (): bool => true);
        Http::fake();
        $api = $this->partialMock(EmployeeRequestApi::class);
        $api->shouldNotReceive('create');
        $signature = $this->mock(SignatureProvider::class);
        $signature->shouldReceive('getCertificateAuthorities')->andReturn([]);
        $signature->shouldReceive('signData')->once()->withArgs(
            fn (array $payload) => $payload['employee_request']['party']['documents'][1]['issued_by'] === 'МВС'
        )->andReturn('signed-test');

        $position = ['employee_type' => 'HR', 'position' => config('ehealth.employee_type.HR.position')[0], 'start_date' => '2024-01-01'];
        $draft = EmployeeRequest::create($position + ['legal_entity_id' => $entity->id, 'status' => RequestStatus::NEW]);
        $draft->revision()->create(['status' => RevisionStatus::PENDING, 'data' => [
            'employee_request_data' => $position,
            'party' => [
                'first_name' => 'Іван', 'last_name' => 'Тестовий', 'birth_date' => '1990-01-01',
                'gender' => 'MALE', 'tax_id' => '1234567890', 'email' => 'issuer@example.test',
            ],
            'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
            'documents' => [
                ['type' => 'NATIONAL_ID', 'number' => '123456789', 'issued_by' => 'ДМС'],
                ['type' => 'TEMPORARY_CERTIFICATE', 'number' => 'АА123456'],
            ],
        ]]);

        $issuer = 'form.documents.1.issuedBy';
        $component = Livewire::test(EmployeeRequestEdit::class, ['legalEntity' => $entity, 'employee_request' => $draft])
            ->call('save')->assertHasErrors($issuer)->assertDispatched('validation-failed-scroll', firstErrorKey: $issuer)
            ->call('prepareForSigning')->assertHasErrors($issuer)->assertNotDispatched('open-request-preview-modal')
            ->assertSee('АА123456 — '.__('validation.custom.employee.document_issuer_required'));
        foreach ([null, '', '   ', "\u{00a0}"] as $blank) {
            $component->set($issuer, $blank)->set('showSignatureModal', true)->call('sign')
                ->assertHasErrors($issuer)->assertHasNoErrors('form.documents.0.issuedBy')
                ->assertSet('showSignatureModal', false);
        }

        $component->set($issuer, 'МВС')->call('save')->assertHasNoErrors()->call('prepareForSigning')
            ->assertHasNoErrors()->assertDispatched('open-request-preview-modal');
        $payload = $api->schemaCreate($draft->fresh()->revision->data);
        self::assertSame('МВС', $payload['employee_request']['party']['documents'][1]['issued_by']);
        $component->call('sign')->assertHasNoErrors($issuer)->assertHasErrors('form.password');

        // Even with a complete form, the exact payload passed to KEP must have an issuer on every document.
        unset($payload['employee_request']['party']['documents'][1]['issued_by']);
        $api->shouldReceive('schemaCreate')->once()->andReturn($payload);
        $component->set('form.knedp', 'test')->set('form.password', 'test')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('test.dat', 1))
            ->set('showSignatureModal', true)->call('sign')->assertHasErrors($issuer)
            ->assertHasNoErrors(['form.documents.0.issuedBy', 'form.password'])->assertSet('showSignatureModal', false);
        $signature->shouldNotHaveReceived('signData');
        Http::assertNothingSent();

        // A 422 from Create Employee Request points back to the same editable document.
        $client = new EmployeeRequestApi(Http::getFacadeRoot());
        $client->baseUrl('https://ehealth.example.test')->preventStrayRequests()->stub(fn () => Http::response([
            'error' => ['type' => 'validation_failed', 'invalid' => [[
                'entry' => '$.employee_request.party.documents[1].issued_by',
                'rules' => [['rule' => 'required', 'description' => 'required property issued_by was not present']],
            ]]],
        ], 422));
        $this->instance(EmployeeRequestApi::class, $client);
        $component->set('showSignatureModal', true)->call('sign')
            ->assertHasErrors($issuer)->assertHasNoErrors('form.documents.0.issuedBy')
            ->assertSet('showSignatureModal', false)->assertDispatched('validation-failed-scroll', firstErrorKey: $issuer)
            ->assertSee('АА123456 — Вкажіть, ким виданий документ.')
            ->assertDispatched('flashMessage', fn ($event, $params) => str_contains($params[0]['message'], 'Вкажіть, ким виданий документ.')
                && !str_contains($params[0]['message'], 'issued_by'));
        Http::assertSentCount(1);
    }
}
