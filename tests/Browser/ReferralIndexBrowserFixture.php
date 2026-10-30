<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Classes\eHealth\Api\Patient\ServiceRequest as PatientServiceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\Referral\ReferralIndex;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Livewire\Drawer\Utils;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use Tests\Feature\ReferralTest;

/** Real component HTML and update responses; all fixtures roll back in the testing DB. */
class ReferralIndexBrowserFixture extends ReferralTest
{
    public function testExportBrowserFixture(): void
    {
        $this->actingAs($this->user);
        $rows = [];
        foreach ([
            'working' => ['in_progress', ''],
            'program' => ['active', 'in_progress'],
            'new_program' => ['new', 'in_progress'],
            'queued' => ['in_queue', ''],
            'active_queue' => ['active', 'in_queue'],
            'completed' => ['completed', 'in_progress'],
            'draft' => ['draft', 'in_progress'],
            'error' => ['entered-in-error', 'in_progress'],
            'recalled' => ['recalled', 'in_progress'],
            'ready' => ['active', 'new'],
        ] as $id => [$status, $programStatus]) {
            $rows[] = [
                'id' => $id,
                'status' => $status,
                'program_processing_status' => $programStatus,
                'requisition' => 'ABCD-1234-EFGH-5678',
                'identity' => ['last_name' => 'Шевченко', 'first_name' => 'Тарас', 'second_name' => 'Григорович', 'age' => 54],
                'subject' => ['identifier' => ['value' => 'patient-id'], 'display' => 'Шевченко Т. Г.'],
                'requesterLegalEntity' => ['identifier' => ['value' => $this->legalEntity->uuid]],
                'code' => ['text' => 'Консультація Ендокринолога'],
            ];
        }

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->once()->andReturn(['data' => $rows]);
        $api = Mockery::mock(PatientServiceRequestApi::class);
        $api->shouldReceive('searchForServiceRequestsByParams')->once()
            ->with(['requisition' => 'ABCD-1234-EFGH-5678'])->andReturn($response);
        $this->instance(PatientServiceRequestApi::class, $api);

        $component = Livewire::test(ReferralIndex::class, ['legalEntity' => $this->legalEntity]);
        $fixtures = ['initial' => $this->capture($component)];
        $component->set('requisition', 'ABCD-1234-EFGH-5678')->call('search')->assertHasNoErrors();
        $fixtures['search'] = $this->capture($component);
        $component->call('$refresh');
        $fixtures['refresh'] = $this->capture($component);

        $service = Mockery::mock(ReferralRequestLifecycleService::class);
        $service->shouldReceive('takeIntoWork')->once()
            ->with('ready', Mockery::any(), 'patient-id', [])->andReturn(['status' => 'in_progress']);
        $this->instance(ReferralRequestLifecycleService::class, $service);
        $component->call('process', 'ready', 'patient-id')->assertSet('searchResults.9.status', 'in_progress');
        $fixtures['process'] = $this->capture($component);

        // Also exercise the short table where a dropdown cannot fit inside its scroll container.
        $component->set('searchResults', [$rows[9]]);
        $fixtures['single'] = $this->capture($component);
        $this->assertNotFalse(file_put_contents(storage_path('referral-browser-fixtures.json'), json_encode($fixtures, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }

    private function capture(Testable $component): array
    {
        $html = preg_replace_callback('/^<[^>]+>/', static fn (array $match): string =>
            preg_replace('/\s+wire:(snapshot|effects)="[^"]*"/', '', $match[0]), $component->html());
        $initialEffects = $component->effects;
        unset($initialEffects['html']);

        return [
            'html' => Utils::insertAttributesIntoHtmlRoot($html, [
                'wire:snapshot' => json_encode($component->snapshot, JSON_THROW_ON_ERROR),
                'wire:effects' => json_encode($initialEffects, JSON_THROW_ON_ERROR),
            ]),
            'snapshot' => $component->snapshot,
            'effects' => $component->effects,
        ];
    }
}
