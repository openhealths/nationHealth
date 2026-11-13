<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Classes\eHealth\Api\Contract as ContractApi;
use App\Classes\eHealth\Api\ContractRequest as ContractRequestApi;
use App\Enums\JobStatus;
use App\Jobs\CompleteSync;
use App\Jobs\ContractDetailsUpsert;
use App\Jobs\ContractRequestDetailsUpsert;
use App\Jobs\ContractRequestSync;
use App\Jobs\ContractSync;
use App\Jobs\DivisionSync;
use App\Livewire\Contract\ContractIndex;
use App\Livewire\Contract\ContractShow;
use App\Livewire\ContractRequest\ContractRequestIndex;
use App\Livewire\ContractRequest\ContractRequestShow;
use App\Models\LegalEntity;
use App\Models\User;
use App\Repositories\Repository;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class ContractReadComplianceTest extends TestCase
{
    use DatabaseTransactions;

    protected LegalEntity $legalEntity;

    protected array $allowedScopes = ['contract:read', 'contract_request:read'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $typeId = DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);
        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_type_id' => $typeId,
            'status' => 'ACTIVE',
            'sync_status' => JobStatus::COMPLETED,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);
        $this->actingAs(User::factory()->create());
        Gate::before(fn ($user, $ability) => str_contains($ability, ':') ? in_array($ability, $this->allowedScopes, true) : null);
        Cache::put(MedicalProgramDictionary::KEY, [], now()->addHour());
        Cache::put(MedicalProgramDictionary::KEY . ':fresh', true, now()->addHour());
    }

    public function test_documented_list_and_detail_variants_use_typed_paths_and_validate(): void
    {
        foreach (['contracts' => ContractApi::class, 'contract_requests' => ContractRequestApi::class] as $resource => $class) {
            foreach (['capitation', 'reimbursement'] as $type) {
                $list = $this->fixture($resource, $type, 'list');
                $client = $this->client($class, $list);
                $response = $resource === 'contracts'
                    ? $client->getMany(['page' => 1], $type)
                    : $client->getMany($type, ['page' => 1]);
                $this->assertSame($list['data'][0]['status'], $response->validate()[0]['status']);
                Http::assertSent(fn ($request) => str_contains($request->url(), '/api/' . $resource . '/' . $type . '?'));

                $details = $this->fixture($resource, $type, 'details');
                $client = $this->client($class, $details);
                $response = $resource === 'contracts'
                    ? $client->getDetails($details['data']['id'], $type)
                    : $client->getDetails($type, $details['data']['id']);
                $this->assertSame($details['data']['status_reason'], $response->validate()['status_reason']);
            }
        }
    }

    public function test_malformed_list_items_required_fields_and_paging_are_rejected(): void
    {
        foreach (['contracts' => ContractApi::class, 'contract_requests' => ContractRequestApi::class] as $resource => $class) {
            foreach (['missing_status', 'unknown_status', 'bad_reason', 'bad_date', 'bad_item', 'missing_paging', 'bad_page', 'bad_owner', 'bad_type'] as $case) {
                $payload = $this->fixture($resource, 'capitation', 'list');
                match ($case) {
                    'missing_status' => $payload['data'][0]['status'] = null,
                    'unknown_status' => $payload['data'][0]['status'] = 'NOT_A_STATUS',
                    'bad_reason' => $payload['data'][0]['status_reason'] = ['wrong'],
                    'bad_date' => $payload['data'][0]['start_date'] = 'not-a-date',
                    'bad_item' => $payload['data'][] = 'not-an-object',
                    'missing_paging' => $payload['paging'] = null,
                    'bad_page' => $payload['paging']['page_number'] = 2,
                    'bad_owner' => $resource === 'contracts'
                        ? $payload['data'][0]['contractor_owner']['id'] = 'wrong'
                        : $payload['data'][0]['contractor_owner_id'] = 'wrong',
                    'bad_type' => $payload['data'][0]['contract_type'] = 'REIMBURSEMENT',
                };
                $client = $this->client($class, $payload);
                try {
                    ($resource === 'contracts' ? $client->getMany() : $client->getMany('capitation'))->validate();
                    $this->fail($resource . ': accepted ' . $case);
                } catch (ValidationException $exception) {
                    $this->assertNotEmpty($exception->errors(), $case);
                }
            }
        }
    }

    public function test_details_reject_wrong_identity_and_nested_data_before_persistence(): void
    {
        foreach (['contracts' => ContractApi::class, 'contract_requests' => ContractRequestApi::class] as $resource => $class) {
            foreach (['id', 'contractor_owner.id', 'contractor_payment_details.payer_account', 'contractor_divisions.0.id'] as $field) {
                $payload = $this->fixture($resource, 'capitation', 'details');
                $requestedId = $payload['data']['id'];
                data_set($payload['data'], $field, 'invalid');
                if ($field === 'contractor_payment_details.payer_account') {
                    data_set($payload['data'], $field, ['invalid']);
                }
                $client = $this->client($class, $payload);
                try {
                    ($resource === 'contracts'
                        ? $client->getDetails($requestedId)
                        : $client->getDetails('capitation', $requestedId))->validate();
                    $this->fail('Accepted malformed details: ' . $field);
                } catch (ValidationException $exception) {
                    $this->assertNotEmpty($exception->errors());
                }
            }
        }
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('contract_requests', 0);
    }

    public function test_printout_uses_documented_id_and_printout_content_envelope(): void
    {
        $id = (string) Str::uuid();
        foreach ([ContractApi::class, ContractRequestApi::class] as $class) {
            $client = $this->client($class, ['data' => ['id' => $id, 'printout_content' => '<p>Contract</p>']]);
            $this->assertSame('<p>Contract</p>', $client->getPrintoutContent($id, 'capitation')->validate()['printout_content']);
        }
    }

    public function test_summary_updates_status_and_reason_without_wiping_full_details(): void
    {
        foreach (['contracts', 'contract_requests'] as $resource) {
            $details = $this->fixture($resource, 'capitation', 'details')['data'];
            $summary = $this->fixture($resource, 'capitation', 'list')['data'][0];
            $details['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
            $summary['contractor_legal_entity_id'] = $this->legalEntity->uuid;
            $repository = $resource === 'contracts' ? Repository::contract() : Repository::contractRequest();
            $model = $resource === 'contracts' ? $repository->saveFromEHealth($details) : $repository->saveFromEHealth($details, 'CAPITATION');
            $summary['status'] = 'TERMINATED';
            $summary['status_reason'] = 'Порушено умови договору';
            $model = $resource === 'contracts' ? $repository->saveFromEHealth($summary) : $repository->saveFromEHealth($summary, 'CAPITATION');
            $this->assertSame('TERMINATED', $model->getRawOriginal('status'));
            $this->assertSame($summary['status_reason'], $model->statusReason);
            $this->assertEquals($details['contractor_payment_details'], $model->contractorPaymentDetails);
            $this->assertEquals($details['contractor_legal_entity'], $model->data['contractor_legal_entity']);
            $summary['status'] = $resource === 'contracts' ? 'VERIFIED' : 'NEW';
            unset($summary['status_reason']);
            $model = $resource === 'contracts' ? $repository->saveFromEHealth($summary) : $repository->saveFromEHealth($summary, 'CAPITATION');
            $this->assertNull($model->statusReason);
            $this->assertArrayNotHasKey('status_reason', $model->data);
        }
    }

    public function test_foreign_facility_payload_is_rejected_without_reassigning_records(): void
    {
        foreach (['contracts', 'contract_requests'] as $resource) {
            $data = $this->fixture($resource, 'capitation', 'details')['data'];
            try {
                $resource === 'contracts'
                    ? Repository::contract()->saveFromEHealth($data)
                    : Repository::contractRequest()->saveFromEHealth($data, 'CAPITATION');
                $this->fail('Accepted foreign facility');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('ehealth_error', $exception->errors());
            }
        }
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('contract_requests', 0);
    }

    public function test_sync_denial_has_visible_feedback_and_no_queue_or_api_side_effects(): void
    {
        Bus::fake();
        Http::fake();
        Gate::before(static fn ($user, $ability) => $ability === 'sync' ? false : null);
        foreach ([ContractIndex::class, ContractRequestIndex::class] as $component) {
            Livewire::test($component)->call('sync')->assertSee(__('contracts.policy.sync_denied'));
        }
        Bus::assertNothingBatched();
        Http::assertNothingSent();
        $this->assertNotSame(JobStatus::PROCESSING, $this->legalEntity->fresh()->contractSyncStatus);
    }

    public function test_allowed_sync_schedules_one_sequential_chain_and_waits_for_confirmation(): void
    {
        Bus::fake();
        Http::fake();
        session()->put(config('ehealth.api.oauth.bearer_token'), 'fake-test-token');
        Livewire::test(ContractIndex::class)->call('sync')->assertSet('isSyncing', true);
        $this->assertSame(JobStatus::PROCESSING, $this->legalEntity->fresh()->contractSyncStatus);
        Bus::assertBatchCount(1);
        Livewire::test(ContractIndex::class)->call('sync');
        Bus::assertBatchCount(1);
        Http::assertNothingSent();
    }

    public function test_missing_token_does_not_leave_sync_processing(): void
    {
        Bus::fake();
        session()->forget(config('ehealth.api.oauth.bearer_token'));
        Livewire::test(ContractRequestIndex::class)->call('sync')->assertSee(__('contracts.sync_token_missing'));
        Bus::assertNothingBatched();
        $this->assertNotSame(JobStatus::PROCESSING, $this->legalEntity->fresh()->contractRequestSyncStatus);
    }

    public function test_details_outage_preserves_last_saved_data_and_shows_warning(): void
    {
        foreach (['contracts' => ContractShow::class, 'contract_requests' => ContractRequestShow::class] as $resource => $component) {
            $data = $this->fixture($resource, 'capitation', 'details')['data'];
            $data['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
            $model = $resource === 'contracts'
                ? Repository::contract()->saveFromEHealth($data)
                : Repository::contractRequest()->saveFromEHealth($data, 'CAPITATION');
            $this->client($resource === 'contracts' ? ContractApi::class : ContractRequestApi::class, ['error' => ['message' => 'Unavailable']], 503);
            $parameters = ['legalEntity' => $this->legalEntity, $resource === 'contracts' ? 'contract' : 'contractRequest' => $model];
            $before = $model->fresh()->getAttributes();
            Livewire::test($component, $parameters)->assertSee(__('contracts.details_unavailable'))->call('$refresh')->assertSee($data['status_reason']);
            $this->assertSame($before, $model->fresh()->getAttributes());
        }
    }

    public function test_next_page_keeps_contract_type_page_and_standalone_context(): void
    {
        foreach ([new ContractSync($this->legalEntity, page: 2, standalone: true, contractType: 'REIMBURSEMENT'),
            new ContractRequestSync($this->legalEntity, contractType: 'CAPITATION', page: 2, standalone: true)] as $job) {
            $next = (new ReflectionMethod($job, 'nextPageJob'))->invoke($job);
            $resource = $job instanceof ContractSync ? 'contracts' : 'contract_requests';
            $class = $job instanceof ContractSync ? ContractApi::class : ContractRequestApi::class;
            $type = $job instanceof ContractSync ? 'reimbursement' : 'capitation';
            $body = $this->fixture($resource, $type, 'list');
            $body['paging']['page_number'] = 3;
            $this->client($class, $body);
            (new ReflectionMethod($next, 'sendRequest'))->invoke($next, 'fake-token');
            Http::assertSent(fn ($request) => str_contains($request->url(), '/api/' . $resource . '/' . $type . '?') && str_contains($request->url(), 'page=3'));
            $this->assertTrue($next->standalone);
        }
    }

    public function test_shared_pagination_preserves_existing_non_contract_job_context(): void
    {
        $completion = new CompleteSync($this->legalEntity);
        $job = new DivisionSync($this->legalEntity, $completion, isFirstLogin: true, page: 2, standalone: true);
        $next = (new ReflectionMethod($job, 'nextPageJob'))->invoke($job);
        $this->assertInstanceOf(DivisionSync::class, $next);
        $this->assertSame(3, (new ReflectionProperty($next, 'page'))->getValue($next));
        $this->assertTrue((new ReflectionProperty($next, 'isFirstLogin'))->getValue($next));
        $this->assertSame($completion, (new ReflectionProperty($next, 'nextEntity'))->getValue($next));
        $this->assertSame($this->legalEntity, $next->legalEntity);
        $this->assertTrue($next->standalone);
    }

    public function test_predeployment_queued_contract_jobs_default_to_capitation(): void
    {
        $job = new ContractSync($this->legalEntity);
        (function (): void {
            unset($this->contractType);
        })->call($job);
        $restored = unserialize(serialize($job));
        $this->client(ContractApi::class, $this->fixture('contracts', 'capitation', 'list'));
        (new ReflectionMethod($restored, 'sendRequest'))->invoke($restored, 'fake-token');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/contracts/capitation?'));
    }

    public function test_read_denial_and_foreign_detail_links_never_call_ehealth(): void
    {
        Http::fake();
        foreach (['contracts' => ContractShow::class, 'contract_requests' => ContractRequestShow::class] as $resource => $component) {
            $data = $this->fixture($resource, 'capitation', 'details')['data'];
            $data['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
            $model = $resource === 'contracts'
                ? Repository::contract()->saveFromEHealth($data)
                : Repository::contractRequest()->saveFromEHealth($data, 'CAPITATION');
            if ($resource === 'contracts') {
                $model->legalEntityId = $this->legalEntity->id + 1;
            } else {
                $model->contractorLegalEntityId = (string) Str::uuid();
            }
            Livewire::test($component, ['legalEntity' => $this->legalEntity,
                $resource === 'contracts' ? 'contract' : 'contractRequest' => $model])->assertForbidden();
        }
        $this->allowedScopes = [];
        Livewire::test(ContractIndex::class)->assertForbidden();
        Livewire::test(ContractRequestIndex::class)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_detail_job_rejects_malformed_payload_without_mutating_record(): void
    {
        $data = $this->fixture('contract_requests', 'capitation', 'details')['data'];
        $data['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
        $model = Repository::contractRequest()->saveFromEHealth($data, 'CAPITATION');
        $before = $model->fresh()->getAttributes();
        unset($data['status']);
        $client = $this->client(ContractRequestApi::class, ['data' => $data, 'urgent' => []]);
        $response = $client->getDetails('capitation', $model->uuid);
        $job = new ContractRequestDetailsUpsert($model, $this->legalEntity);
        try {
            (new ReflectionMethod($job, 'processResponse'))->invoke($job, $response);
            $this->fail('Persisted malformed detail response');
        } catch (ValidationException $exception) {
            $this->assertSame($before, $model->fresh()->getAttributes());
        }
    }

    public function test_successful_detail_read_updates_status_reason_and_survives_hydration(): void
    {
        foreach (['contracts' => ContractShow::class, 'contract_requests' => ContractRequestShow::class] as $resource => $component) {
            $data = $this->fixture($resource, 'capitation', 'details')['data'];
            $data['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
            $model = $resource === 'contracts'
                ? Repository::contract()->saveFromEHealth($data)
                : Repository::contractRequest()->saveFromEHealth($data, 'CAPITATION');
            $data['status'] = 'TERMINATED';
            $data['status_reason'] = 'Оновлена причина припинення';
            $this->client($resource === 'contracts' ? ContractApi::class : ContractRequestApi::class, ['data' => $data, 'urgent' => []]);
            Livewire::test($component, ['legalEntity' => $this->legalEntity,
                $resource === 'contracts' ? 'contract' : 'contractRequest' => $model])
                ->assertSee($data['status_reason'])->call('$refresh')->assertSee($data['status_reason']);
            $this->assertSame('TERMINATED', $model->fresh()->getRawOriginal('status'));
            $this->assertSame(JobStatus::COMPLETED, $model->fresh()->syncStatus);
        }
    }

    public function test_long_status_reason_is_persisted_without_truncation(): void
    {
        $data = $this->fixture('contracts', 'capitation', 'details')['data'];
        $data['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
        $data['status_reason'] = str_repeat('Повна причина припинення. ', 100);
        $model = Repository::contract()->saveFromEHealth($data);
        $this->assertSame($data['status_reason'], $model->fresh()->statusReason);
    }

    public function test_full_detail_envelopes_preserve_every_resource_field_and_document(): void
    {
        foreach (['contracts' => ContractApi::class, 'contract_requests' => ContractRequestApi::class] as $resource => $class) {
            foreach (['capitation', 'reimbursement'] as $type) {
                $payload = $this->fixture($resource, $type, 'details');
                $payload['data']['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
                $client = $this->client($class, $payload);
                $validated = ($resource === 'contracts'
                    ? $client->getDetails($payload['data']['id'], $type)
                    : $client->getDetails($type, $payload['data']['id']))->validate();
                $model = $resource === 'contracts'
                    ? Repository::contract()->saveFromEHealth($validated, details: true)
                    : Repository::contractRequest()->saveFromEHealth($validated, strtoupper($type), details: true);
                foreach ($payload['data'] as $key => $value) {
                    $this->assertEquals($value, $model->fresh()->data[$key], $resource . ': ' . $key);
                }
                $this->assertEquals($payload['urgent'], $model->fresh()->data['urgent']);
            }
        }
    }

    public function test_invalid_document_envelopes_and_strict_wire_types_are_rejected(): void
    {
        foreach (['contracts' => ContractApi::class, 'contract_requests' => ContractRequestApi::class] as $resource => $class) {
            foreach (['missing_urgent', 'bad_document', 'unsafe_url', 'boolean_string', 'number_string', 'program_object'] as $case) {
                $payload = $this->fixture($resource, 'capitation', 'details');
                match ($case) {
                    'missing_urgent' => $payload = array_diff_key($payload, ['urgent' => true]),
                    'bad_document' => $payload['urgent']['documents'] = [['url' => 'https://example.com/doc']],
                    'unsafe_url' => $payload['urgent']['documents'] = [['type' => 'SIGNED_CONTENT', 'url' => 'javascript:alert(1)']],
                    'boolean_string' => $payload['data']['external_contractor_flag'] = 'false',
                    'number_string' => $payload['data']['contractor_rmsp_amount'] = '50000',
                    'program_object' => $payload['data']['medical_programs'] = [['id' => (string) Str::uuid()]],
                };
                $client = $this->client($class, $payload);
                try {
                    ($resource === 'contracts' ? $client->getDetails($payload['data']['id'])
                        : $client->getDetails('capitation', $payload['data']['id']))->validate();
                    $this->fail('Accepted invalid ' . $resource . ': ' . $case);
                } catch (ValidationException $exception) {
                    $this->assertNotEmpty($exception->errors());
                }
            }
        }
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('contract_requests', 0);
    }

    public function test_printout_rejects_wrong_identity_legacy_envelope_and_invalid_content(): void
    {
        $id = (string) Str::uuid();
        foreach ([ContractApi::class, ContractRequestApi::class] as $class) {
            foreach ([['id' => (string) Str::uuid(), 'printout_content' => 'Text'],
                ['id' => $id, 'content' => 'Legacy text'],
                ['id' => $id, 'printout_content' => ['not text']]] as $data) {
                $client = $this->client($class, ['data' => $data]);
                try {
                    $client->getPrintoutContent($id, 'capitation')->validate();
                    $this->fail('Accepted malformed printout response');
                } catch (ValidationException $exception) {
                    $this->assertNotEmpty($exception->errors());
                }
            }
        }
    }

    public function test_full_details_replace_obsolete_optional_fields_while_summary_preserves_details(): void
    {
        foreach (['contracts', 'contract_requests'] as $resource) {
            $data = $this->fixture($resource, 'capitation', 'details')['data'];
            $data['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
            $repository = $resource === 'contracts' ? Repository::contract() : Repository::contractRequest();
            $save = fn (array $payload) => $resource === 'contracts'
                ? $repository->saveFromEHealth($payload, details: true)
                : $repository->saveFromEHealth($payload, 'CAPITATION', details: true);
            $save($data);
            unset($data['status_reason'], $data['issue_city'], $data['external_contractor_flag'],
                $data['external_contractors'], $data['nhs_signer']);
            $model = $save($data)->fresh();
            $this->assertNull($model->statusReason);
            $this->assertNull($model->issueCity);
            $this->assertArrayNotHasKey('status_reason', $model->data);
            $this->assertArrayNotHasKey('issue_city', $model->data);
            $this->assertFalse($model->externalContractorFlag);
            $this->assertSame([], $model->externalContractors);
            $this->assertNull($model->nhsSignerId);
        }
    }

    public function test_suspension_from_list_and_details_uses_json_without_schema_changes(): void
    {
        $data = $this->fixture('contracts', 'capitation', 'list')['data'][0];
        $data['contractor_legal_entity_id'] = $this->legalEntity->uuid;
        foreach (['false' => false, 'true' => true] as $wire => $expected) {
            $data['is_suspended'] = $wire;
            $model = Repository::contract()->saveFromEHealth($data)->fresh();
            $this->assertSame($expected, $model->isSuspended);
            $this->assertSame($wire, $model->data['is_suspended']);
        }
    }

    public function test_reason_migration_preserves_existing_values_and_long_reasons_on_rollback(): void
    {
        Schema::table('contracts', fn (Blueprint $table) => $table->string('status_reason')->nullable()->change());
        $data = $this->fixture('contracts', 'capitation', 'details')['data'];
        $data['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
        $model = Repository::contract()->saveFromEHealth($data);
        $migration = require database_path('migrations/update/0_1/2026_11_12_170000_expand_contract_status_reason.php');
        $migration->up();
        $this->assertSame($data['status_reason'], $model->fresh()->statusReason);
        $reason = str_repeat('Повний текст причини. ', 100);
        $model->update(['status_reason' => $reason]);
        $migration->down();
        $this->assertSame($reason, $model->fresh()->statusReason);
    }

    public function test_full_data_view_preserves_empty_false_zero_and_escapes_remote_text(): void
    {
        $html = view('livewire.contract-request.partials.ehealth-data-tree', ['data' => [
            'contractor_employee_divisions' => [['staff_units' => 0, 'speciality_officio' => false]],
            'addresses' => [], 'reason' => null, 'new_ehealth_field' => '<script>alert(1)</script>',
        ]])->render();
        $this->assertStringContainsString(__('contracts.ehealth_fields.contractor_employee_divisions'), $html);
        $this->assertStringContainsString(__('contracts.empty_collection'), $html);
        $this->assertStringContainsString(__('contracts.not_specified'), $html);
        $this->assertStringContainsString(__('forms.no'), $html);
        $this->assertStringContainsString('>0</span>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_both_detail_pages_show_complete_nested_data_documents_and_printout(): void
    {
        foreach (['contracts' => ContractShow::class, 'contract_requests' => ContractRequestShow::class] as $resource => $component) {
            foreach (['capitation', 'reimbursement'] as $type) {
                $payload = $this->fixture($resource, $type, 'details');
                $payload['data']['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
                $model = $resource === 'contracts'
                    ? Repository::contract()->saveFromEHealth($payload['data'])
                    : Repository::contractRequest()->saveFromEHealth($payload['data'], strtoupper($type));
                $this->client(
                    $resource === 'contracts' ? ContractApi::class : ContractRequestApi::class,
                    $payload,
                    printout: ['data' => ['id' => $model->uuid, 'printout_content' => '<p>Full printout</p>']]
                );
                Livewire::test($component, ['legalEntity' => $this->legalEntity,
                    $resource === 'contracts' ? 'contract' : 'contractRequest' => $model])
                    ->assertSee(__('contracts.all_ehealth_details'))
                    ->assertSee(__('contracts.ehealth_fields.addresses'))
                    ->assertSee($payload['urgent']['documents'][0]['url'])
                    ->assertSee('&lt;p&gt;Full printout&lt;/p&gt;', false)
                    ->call('$refresh')->assertSee(__('contracts.all_ehealth_details'));
                Http::assertSentCount(2);
            }
        }
    }

    public function test_document_envelope_and_local_aliases_never_enter_signing_resource(): void
    {
        $payload = $this->fixture('contract_requests', 'capitation', 'details');
        $payload['data']['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
        $model = Repository::contractRequest()->saveFromEHealth($payload['data'] + [
            'urgent' => $payload['urgent'], 'sync_status' => JobStatus::COMPLETED->value,
        ], 'CAPITATION', details: true);
        $component = new ContractRequestShow();
        (new ReflectionProperty($component, 'contractRequest'))->setValue($component, $model);
        $signedResource = (new ReflectionMethod($component, 'buildJsonDataToSign'))->invoke($component);
        $this->assertEquals($payload['data'], $signedResource);
        foreach (['urgent', 'uuid', 'type', 'sync_status'] as $localKey) {
            $this->assertArrayNotHasKey($localKey, $signedResource);
        }
    }

    public function test_documented_employee_speciality_and_staff_details_are_validated_and_displayed(): void
    {
        $employeeDivision = json_decode(file_get_contents(base_path('tests/Fixtures/Contracts/contractor-employee-division.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach (['contracts' => ContractShow::class, 'contract_requests' => ContractRequestShow::class] as $resource => $component) {
            $payload = $this->fixture($resource, 'capitation', 'details');
            $payload['data']['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
            $payload['data']['contractor_employee_divisions'] = [$employeeDivision];
            $model = $resource === 'contracts'
                ? Repository::contract()->saveFromEHealth($payload['data'])
                : Repository::contractRequest()->saveFromEHealth($payload['data'], 'CAPITATION');
            $class = $resource === 'contracts' ? ContractApi::class : ContractRequestApi::class;
            $this->client($class, $payload, printout: ['data' => ['id' => $model->uuid, 'printout_content' => 'Printout']]);
            Livewire::test($component, ['legalEntity' => $this->legalEntity,
                $resource === 'contracts' ? 'contract' : 'contractRequest' => $model])
                ->assertSee($employeeDivision['employee']['speciality']['certificate_number'])
                ->assertSee(__('contracts.ehealth_fields.staff_units'));
            $this->assertEquals([$employeeDivision], $model->fresh()->data['contractor_employee_divisions']);
            unset($payload['data']['contractor_employee_divisions'][0]['employee']['speciality']);
            $client = $this->client($class, $payload);
            try {
                ($resource === 'contracts' ? $client->getDetails($model->uuid)
                    : $client->getDetails('capitation', $model->uuid))->validate();
                $this->fail('Accepted employee without required speciality');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_sync_and_poll_denial_after_mount_has_no_side_effects(): void
    {
        Bus::fake();
        Http::fake();
        foreach (['contracts' => ContractIndex::class, 'contract_requests' => ContractRequestIndex::class] as $resource => $class) {
            $this->allowedScopes = ['contract:read', 'contract_request:read'];
            $data = $this->fixture($resource, 'capitation', 'details')['data'];
            $data['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
            $data['contract_number'] = 'HIDDEN-AFTER-SCOPE-LOSS';
            $resource === 'contracts'
                ? Repository::contract()->saveFromEHealth($data)
                : Repository::contractRequest()->saveFromEHealth($data, 'CAPITATION');
            $component = Livewire::test($class)->assertSee($data['contract_number']);
            $before = $this->legalEntity->fresh()->getAttributes();
            $this->allowedScopes = [];
            $component->call('sync')->assertSee(__('contracts.policy.sync_denied'))
                ->assertDontSee($data['contract_number'])
                ->call('checkSyncStatus')->assertSee(__('contracts.policy.sync_denied'))
                ->call('$refresh')->assertDontSee($data['contract_number']);
            $this->assertSame($before, $this->legalEntity->fresh()->getAttributes());
        }
        Bus::assertNothingBatched();
        Http::assertNothingSent();
    }

    public function test_write_action_denial_after_mount_keeps_signing_and_remote_state_untouched(): void
    {
        $payload = $this->fixture('contract_requests', 'capitation', 'details');
        $payload['data']['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
        $model = Repository::contractRequest()->saveFromEHealth($payload['data'], 'CAPITATION');
        $this->client(ContractRequestApi::class, $payload, printout: ['data' => ['id' => $model->uuid, 'printout_content' => 'Printout']]);
        $component = Livewire::test(ContractRequestShow::class, ['legalEntity' => $this->legalEntity, 'contractRequest' => $model]);
        $before = $model->fresh()->getAttributes();
        $component->call('openApproveModal')->assertSee(__('contracts.policy.approve_denied'))
            ->assertSet('showSignatureModal', false)->call('approve')->assertSee(__('contracts.policy.approve_denied'))
            ->call('openSignModal')->assertSee(__('contracts.policy.sign_denied'))
            ->assertSet('showSignatureModal', false)->call('sign')->assertSee(__('contracts.policy.sign_denied'));
        Http::assertSentCount(2);
        $this->assertSame($before, $model->fresh()->getAttributes());
    }

    public function test_standalone_sync_fetches_both_types_and_all_details_before_completion(): void
    {
        foreach (['contracts' => ContractApi::class, 'contract_requests' => ContractRequestApi::class] as $resource => $class) {
            $job = $resource === 'contracts'
                ? new ContractSync($this->legalEntity, standalone: true)
                : new ContractRequestSync($this->legalEntity, contractType: 'CAPITATION', standalone: true);
            foreach (['capitation', 'reimbursement'] as $type) {
                $payload = $this->fixture($resource, $type, 'list');
                $payload['data'][0]['contractor_legal_entity_id'] = $this->legalEntity->uuid;
                $payload['data'][0]['id'] = (string) Str::uuid();
                $client = $this->client($class, $payload);
                $response = $resource === 'contracts' ? $client->getMany(contractType: $type) : $client->getMany($type);
                (new ReflectionMethod($job, 'processResponse'))->invoke($job, $response);
                $job = (new ReflectionMethod($job, 'getNextEntityJob'))->invoke($job);
            }
            $this->assertDatabaseHas($resource, ['sync_status' => JobStatus::PARTIAL->value]);
            $count = 0;
            while ($job instanceof ContractDetailsUpsert || $job instanceof ContractRequestDetailsUpsert) {
                $model = $resource === 'contracts' ? $job->contractModel : $job->contractRequestModel;
                $type = strtolower($model->type);
                $payload = $this->fixture($resource, $type, 'details');
                $payload['data']['id'] = $model->uuid;
                $payload['data']['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
                $client = $this->client($class, $payload);
                $response = $resource === 'contracts' ? $client->getDetails($model->uuid, $type) : $client->getDetails($type, $model->uuid);
                (new ReflectionMethod($job, 'processResponse'))->invoke($job, $response);
                $this->assertSame(JobStatus::COMPLETED, $model->fresh()->syncStatus);
                $job = (new ReflectionMethod($job, 'getNextEntityJob'))->invoke($job);
                $count++;
            }
            $this->assertSame(2, $count);
            $this->assertInstanceOf(CompleteSync::class, $job);
            $this->assertDatabaseMissing($resource, ['sync_status' => JobStatus::PARTIAL->value]);
        }
    }

    public function test_invalid_second_list_item_cannot_partially_persist_the_page(): void
    {
        foreach (['contracts' => ContractApi::class, 'contract_requests' => ContractRequestApi::class] as $resource => $class) {
            $payload = $this->fixture($resource, 'capitation', 'list');
            $payload['data'][0]['contractor_legal_entity_id'] = $this->legalEntity->uuid;
            $payload['data'][] = array_replace($payload['data'][0], ['id' => (string) Str::uuid(), 'status' => null]);
            $client = $this->client($class, $payload);
            $response = $resource === 'contracts' ? $client->getMany() : $client->getMany('capitation');
            $job = $resource === 'contracts' ? new ContractSync($this->legalEntity)
                : new ContractRequestSync($this->legalEntity, contractType: 'CAPITATION');
            try {
                (new ReflectionMethod($job, 'processResponse'))->invoke($job, $response);
                $this->fail('Persisted an invalid page');
            } catch (ValidationException $exception) {
                $this->assertDatabaseCount($resource, 0);
            }
        }
    }

    public function test_primary_care_lists_show_the_status_labels_and_reasons_required_by_acceptance(): void
    {
        Http::fake();
        foreach ([
            'contracts' => [ContractIndex::class, 'TERMINATED', 'Завершений'],
            'contract_requests' => [ContractRequestIndex::class, 'SIGNED', 'Підписана'],
        ] as $resource => [$component, $status, $label]) {
            $data = $this->fixture($resource, 'capitation', 'details')['data'];
            $data['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
            $data['contract_number'] = 'ACCEPTANCE-' . $resource;
            $data['status'] = $status;
            $data['status_reason'] = 'Причина статусу ' . $resource;
            $resource === 'contracts'
                ? Repository::contract()->saveFromEHealth($data, details: true)
                : Repository::contractRequest()->saveFromEHealth($data, 'CAPITATION', details: true);
            Livewire::test($component)->assertSeeInOrder([$data['contract_number'], $label, $data['status_reason']]);
        }
        Http::assertNothingSent();
    }

    public function test_held_sync_locks_prevent_dispatch_and_state_changes(): void
    {
        Bus::fake();
        Http::fake();
        session()->put(config('ehealth.api.oauth.bearer_token'), 'fake-test-token');
        foreach ([ContractIndex::class => 'contract-sync:', ContractRequestIndex::class => 'contract-request-sync:'] as $component => $prefix) {
            $lock = Cache::lock($prefix . $this->legalEntity->id, 120);
            $this->assertTrue($lock->get());
            $before = $this->legalEntity->fresh()->getAttributes();
            try {
                Livewire::test($component)->call('sync')->assertSee(__('forms.errors.sync_already_running'));
                $this->assertSame($before, $this->legalEntity->fresh()->getAttributes());
            } finally {
                $lock->release();
            }
        }
        Bus::assertNothingBatched();
        Http::assertNothingSent();
    }

    public function test_single_request_sync_shares_full_sync_reservation(): void
    {
        Bus::fake();
        Http::fake();
        session()->put(config('ehealth.api.oauth.bearer_token'), 'fake-test-token');
        $data = $this->fixture('contract_requests', 'capitation', 'details')['data'];
        $data['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
        $model = Repository::contractRequest()->saveFromEHealth($data, 'CAPITATION');
        $before = $model->fresh()->getAttributes();
        $lock = Cache::lock('contract-request-sync:' . $this->legalEntity->id, 120);
        $this->assertTrue($lock->get());
        try {
            Livewire::test(ContractRequestIndex::class)->call('syncOne', $model->uuid)
                ->assertSee(__('forms.errors.sync_already_running'));
            $this->assertSame($before, $model->fresh()->getAttributes());
            Bus::assertNothingBatched();
        } finally {
            $lock->release();
        }
        Livewire::test(ContractRequestIndex::class)->call('syncOne', $model->uuid)->assertSet('isSyncing', true);
        $this->assertSame(JobStatus::PROCESSING, $this->legalEntity->fresh()->contractRequestSyncStatus);
        $this->assertSame(JobStatus::PROCESSING, $model->fresh()->syncStatus);
        Bus::assertBatchCount(1);
        Livewire::test(ContractRequestIndex::class)->call('sync')->assertSee(__('forms.errors.sync_already_running'));
        Livewire::test(ContractRequestIndex::class)->call('syncOne', $model->uuid)->assertSee(__('forms.errors.sync_already_running'));
        Bus::assertBatchCount(1);
        Http::assertNothingSent();
    }

    public function test_local_upload_objects_are_excluded_while_remote_checksums_remain_in_snapshot(): void
    {
        $data = $this->fixture('contract_requests', 'capitation', 'details')['data'];
        $data['contractor_legal_entity']['id'] = $this->legalEntity->uuid;
        $data['statute_md5'] = UploadedFile::fake()->create('statute.pdf', 1);
        $data['additional_document_md5'] = UploadedFile::fake()->create('additional.pdf', 1);
        $model = Repository::contractRequest()->saveFromEHealth($data, 'CAPITATION');
        $this->assertArrayNotHasKey('statute_md5', $model->fresh()->data);
        $this->assertArrayNotHasKey('additional_document_md5', $model->fresh()->data);
        $data['statute_md5'] = str_repeat('a', 32);
        $data['additional_document_md5'] = str_repeat('b', 32);
        $model = Repository::contractRequest()->saveFromEHealth($data, 'CAPITATION', details: true);
        $this->assertSame($data['statute_md5'], $model->fresh()->data['statute_md5']);
        $this->assertSame($data['additional_document_md5'], $model->fresh()->data['additional_document_md5']);
    }

    protected function fixture(string $resource, string $type, string $kind): array
    {
        $fixture = json_decode(file_get_contents(base_path("tests/Fixtures/Contracts/{$resource}-{$type}-{$kind}.json")), true, 512, JSON_THROW_ON_ERROR);
        if ($kind === 'list') {
            $fixture['paging'] = ['page_number' => 1, 'page_size' => 50, 'total_entries' => 1, 'total_pages' => 1];
        }

        return $fixture;
    }

    protected function client(string $class, array $payload, int $status = 200, ?array $printout = null): ContractApi|ContractRequestApi
    {
        $factory = new Factory();
        $factory->preventStrayRequests();
        $responses = ['*' => Http::response($payload, $status)];
        if ($printout !== null) {
            $responses = ['*printout_content' => Http::response($printout)] + $responses;
        }
        $factory->fake($responses);
        Http::swap($factory);
        $client = new $class($factory);
        $client->stub((function () {
            return $this->stubCallbacks;
        })->call($factory));
        $this->instance($class, $client);

        return $client;
    }
}
