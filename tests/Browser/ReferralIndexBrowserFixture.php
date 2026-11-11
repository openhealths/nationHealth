<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Classes\eHealth\Api\Patient\ServiceRequest as PatientServiceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Livewire\Drawer\Utils;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use ReflectionMethod;
use Tests\Feature\ReferralTest;
use Tests\Support\ReferralIndexFixture;

/** Real component HTML and updates; full native resources pass the production validator. */
class ReferralIndexBrowserFixture extends ReferralTest
{
    public function testExportBrowserFixture(): void
    {
        $this->actingAs($this->user);
        $this->grantMedicalEventAbilities($this->user, ['service_request:recall', 'service_request:cancel']);
        Cache::forever(MedicalProgramDictionary::KEY, []);
        Cache::forever(MedicalProgramDictionary::KEY.':fresh', true);
        $this->partialMock(ServiceRequestRequestRepository::class)->shouldReceive('completionStates')->andReturn([]);
        $ids = [];
        $rows = [];
        $patientUuid = '10050000-0000-4000-8000-000000000099';
        foreach ([
            'working' => ['in_progress', ''], 'program' => ['active', 'in_progress'],
            'new_program' => ['new', 'in_progress'], 'queued' => ['in_queue', ''],
            'active_queue' => ['active', 'in_queue'], 'completed' => ['completed', 'in_progress'],
            'draft' => ['draft', 'in_progress'], 'error' => ['entered_in_error', 'in_progress'],
            'recalled' => ['recalled', 'in_progress'], 'ready' => ['active', 'new'],
        ] as $alias => [$status, $programStatus]) {
            $ids[$alias] = sprintf('10050000-0000-4000-8000-%012d', count($rows) + 1);
            $rows[] = [
                'id' => $ids[$alias], 'status' => $status, 'status_history' => [], 'intent' => 'order',
                'program_processing_status' => $programStatus, 'requisition' => 'ABCD-1234-EFGH-5678',
                'category' => ['coding' => [['system' => 'eHealth/SNOMED/service_request_categories', 'code' => 'diagnostic_procedure']]],
                'identity' => ['last_name' => 'Шевченко', 'first_name' => 'Тарас', 'second_name' => 'Григорович', 'gender' => 'MALE', 'age' => 54],
                'subject' => $this->reference($patientUuid, 'patient', 'Шевченко Т. Г.'),
                'requester_employee' => $this->reference($this->employee->uuid, 'employee'),
                'requester_legal_entity' => $this->reference($this->legalEntity->uuid, 'legal_entity'),
                'used_by_employee' => ['display_value' => ''], 'used_by_legal_entity' => ['display_value' => ''],
                'code' => $this->reference('10050000-0000-4000-8000-000000000098', 'service', 'Консультація Ендокринолога'),
                'reason_reference' => [], 'note' => '', 'patient_instruction' => '',
                'expiration_date' => '2027-11-11T00:00:00Z', 'occurrence_period' => ['start' => '2026-11-11T00:00:00Z'],
            ];
        }
        $validator = new ReflectionMethod(PatientServiceRequestApi::class, 'validateMany');
        $nativeResponse = new EHealthResponse(
            new Response(200, [], json_encode(['data' => $rows, 'paging' => ['page' => 1, 'page_size' => 100, 'total_entries' => 10, 'total_pages' => 1]], JSON_THROW_ON_ERROR)),
            static fn (EHealthResponse $response): array => $validator->invoke(new PatientServiceRequestApi(), $response)
        );
        $api = Mockery::mock(PatientServiceRequestApi::class);
        $api->shouldReceive('searchForServiceRequestsByParams')->times(4)
            ->with(Mockery::on(static fn (array $query): bool => $query['requisition'] === 'ABCD-1234-EFGH-5678'
                && in_array($query['status'], ['active', 'completed', 'entered_in_error', 'recalled'], true)
                && $query['page'] === 1 && $query['page_size'] === 100))->andReturn($nativeResponse);
        $this->instance(PatientServiceRequestApi::class, $api);

        foreach (['recall', 'error'] as $action) {
            $this->assertTrue($this->user->can('registeredAction', [ServiceRequestRequest::class, $rows[9], $action]));
        }

        $component = Livewire::test(ReferralIndexFixture::class, ['legalEntity' => $this->legalEntity]);
        $fixtures = ['ids' => $ids, 'patientUuid' => $patientUuid, 'executorId' => $this->employee->id, 'initial' => $this->capture($component)];
        $component->set('requisition', 'ABCD-1234-EFGH-5678')->call('search')->assertHasNoErrors()->assertSet('searchResults', $rows);
        $fixtures['search'] = $this->capture($component);
        $component->call('$refresh');
        $fixtures['refresh'] = $this->capture($component);
        $component->call('process', $ids['ready'], $patientUuid)->assertSet('showUseModal', true);
        $fixtures['use'] = $this->capture($component);
        $component->call('confirmUse')->assertHasErrors(['selectedExecutorId']);
        $fixtures['useInvalid'] = $this->capture($component);
        $fixtures['executorError'] = $component->instance()->getErrorBag()->first('selectedExecutorId');

        $service = Mockery::mock(ReferralRequestLifecycleService::class);
        $service->shouldReceive('takeIntoWork')->once()
            ->with($ids['ready'], Mockery::on(fn ($employee): bool => $employee->id === $this->employee->id), $patientUuid, ['program_id' => null])
            ->andReturn(['status' => 'in_progress', 'used_by_employee' => $this->reference($this->employee->uuid, 'employee'), 'used_by_legal_entity' => $this->reference($this->legalEntity->uuid, 'legal_entity')]);
        $this->instance(ReferralRequestLifecycleService::class, $service);
        $component->set('selectedExecutorId', $this->employee->id)->call('confirmUse')->assertHasNoErrors()->assertSet('searchResults.9.status', 'in_progress')->assertSet('showUseModal', false);
        $fixtures['process'] = $this->capture($component);
        $component->call('seedResults', [$rows[9]]);
        $fixtures['single'] = $this->capture($component);
        $this->assertNotFalse(file_put_contents(storage_path('referral-browser-fixtures.json'), json_encode($fixtures, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }

    private function reference(string $uuid, string $type, string $display = ''): array
    {
        return ['display_value' => $display, 'identifier' => ['value' => $uuid, 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]]]];
    }

    private function capture(Testable $component): array
    {
        $html = preg_replace_callback('/^<[^>]+>/', static fn (array $match): string => preg_replace('/\s+wire:(snapshot|effects)="[^"]*"/', '', $match[0]), $component->html());
        $initialEffects = $component->effects;
        unset($initialEffects['html']);

        return [
            'html' => Utils::insertAttributesIntoHtmlRoot($html, ['wire:snapshot' => json_encode($component->snapshot, JSON_THROW_ON_ERROR), 'wire:effects' => json_encode($initialEffects, JSON_THROW_ON_ERROR)]),
            'snapshot' => $component->snapshot, 'effects' => $component->effects,
        ];
    }
}
