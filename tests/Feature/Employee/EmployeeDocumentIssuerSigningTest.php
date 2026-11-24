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
        $this->actingAs(User::create(['email' => Str::uuid().'@example.test', 'password' => bcrypt('test')]));
        Gate::before(fn (): bool => true);
        Http::fake();
        $api = $this->partialMock(EmployeeRequestApi::class);
        $api->shouldNotReceive('create');
        $signature = $this->mock(SignatureProvider::class);
        $signature->shouldReceive('getCertificateAuthorities')->andReturn([]);
        $signature->shouldNotReceive('signData');

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
            ->call('prepareForSigning')->assertHasErrors($issuer)->assertNotDispatched('open-request-preview-modal')
            ->assertSee('АА123456 — '.__('validation.required', ['attribute' => __('forms.document_issued_by')]));
        foreach ([null, '', '   ', "\u{00a0}"] as $blank) {
            $component->set($issuer, $blank)->set('showSignatureModal', true)->call('sign')
                ->assertHasErrors($issuer)->assertHasNoErrors('form.documents.0.issuedBy')
                ->assertSet('showSignatureModal', false);
        }

        $component->set($issuer, 'МВС')->call('prepareForSigning')
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
        Http::assertNothingSent();
    }
}
