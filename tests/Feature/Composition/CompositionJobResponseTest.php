<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\Api\Patient\Composition as CompositionApi;
use App\Models\MedicalEvents\Sql\Composition;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompositionJobResponseTest extends TestCase
{
    use RefreshCompositionDatabase;
    private const string COMPOSITION_ID = '89678f60-4cdc-4fe3-ae83-e8b3ebd35c59';
    private const string PATIENT_ID = '7075e0e2-6b57-47fd-aff7-324806efa7e5';

    public function test_create_returns_the_scheduled_job_and_writes_nothing_locally(): void
    {
        $this->fakeApi([
            'data' => ['id' => 'job-1', 'eta' => '2026-08-13T12:35:49.956Z', 'status' => 'PENDING'],
        ]);

        $job = EHealth::composition()->create(['type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'NEWBORN']]]])->validate();

        $this->assertSame('job-1', $job['id']);
        $this->assertSame('PENDING', $job['status']);
        $this->assertSame(
            0,
            Composition::count(),
            'A conclusion must not be mirrored locally before eHealth has assigned it an id.'
        );
    }

    public function test_create_sends_the_composition_request_body_not_a_signature_wrapper(): void
    {
        $this->fakeApi(['data' => ['id' => 'job-1', 'status' => 'PENDING']]);

        $payload = [
            'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'TEMP_DISABILITY']]],
            'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => 'SICKNESS']]],
        ];

        EHealth::composition()->create($payload);

        Http::assertSent(static function ($request) use ($payload): bool {
            return str_contains($request->url(), '/composition')
                && $request->data() === $payload;
        });
    }

    public function test_cancel_and_erln_retry_return_the_job_they_scheduled(): void
    {
        $this->fakeApi(['data' => ['id' => 'job-cancel', 'status' => 'PENDING']]);
        $this->assertSame('job-cancel', EHealth::composition()->cancel(self::COMPOSITION_ID, ['data' => 'signed'])->validate()['id']);

        $this->fakeApi(['data' => ['id' => 'job-erln', 'status' => 'PENDING']]);
        $this->assertSame('job-erln', EHealth::composition()->resendErln(self::COMPOSITION_ID)->validate()['id']);
    }

    public function test_job_status_reports_pending_without_a_composition_id(): void
    {
        $this->fakeApi([
            'data' => ['status' => 'PENDING', 'links' => [['entity' => 'eHealth/resources']]],
        ]);

        $status = EHealth::composition()->getAsyncJobStatus('job-1')->validate();

        $this->assertSame('PENDING', $status['status']);
        $this->assertNull($status['compositionUuid']);
        $this->assertSame([], $status['errors']);
    }

    public function test_job_status_extracts_the_composition_id_from_a_link_href(): void
    {
        $this->fakeApi([
            'data' => [
                'status' => 'DONE',
                'links' => [[
                    'entity' => 'eHealth/resources',
                    'href' => '/api/patients/' . self::PATIENT_ID . '/composition/' . self::COMPOSITION_ID,
                ]],
            ],
        ]);

        $status = EHealth::composition()->getAsyncJobStatus('job-1')->validate();

        $this->assertSame('DONE', $status['status']);
        $this->assertSame(self::COMPOSITION_ID, $status['compositionUuid']);
    }

    public function test_job_status_surfaces_failure_messages(): void
    {
        $this->fakeApi([
            'data' => [
                'status' => 'FAILED',
                'error' => [['message' => 'Invalid period']],
            ],
        ]);

        $status = EHealth::composition()->getAsyncJobStatus('job-1')->validate();

        $this->assertSame('FAILED', $status['status']);
        $this->assertSame(['Invalid period'], $status['errors']);
    }

    public function test_job_status_translates_treatment_violation_date_rule_1172(): void
    {
        $this->fakeApi([
            'data' => [
                'status' => 'FAILED',
                'error' => [[
                    'message' => '1172: Treatment violation date should be >= composition start && <= now',
                ]],
            ],
        ]);

        $status = EHealth::composition()->getAsyncJobStatus('job-1')->validate();

        $this->assertSame('FAILED', $status['status']);
        $this->assertSame([
            __('errors.ehealth.rules.1172'),
        ], $status['errors']);
    }

    private function fakeApi(array $response, int $status = 200): void
    {
        $factory = Http::getFacadeRoot();

        // Http::fake() appends, so the previous catch-all would keep answering instead.
        (function (): void {
            $this->stubCallbacks = collect();
        })->call($factory);

        Http::fake(['*' => Http::response($response, $status)]);

        $api = new CompositionApi($factory);
        $api->stub((function () {
            return $this->stubCallbacks;
        })->call($factory));

        $this->instance(CompositionApi::class, $api);
    }
}
