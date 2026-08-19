<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Classes\eHealth\Api\Patient\Composition as CompositionApi;
use App\Enums\Person\CompositionStatus;
use App\Enums\Person\CompositionType;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\Person\Person;
use App\Services\MedicalEvents\CompositionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompositionLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
            ],
            '--realpath' => true,
        ]);
    }

    private const string COMPOSITION_ID = '89678f60-4cdc-4fe3-ae83-e8b3ebd35c59';

    private const string ENCOUNTER_ID = 'e39ee5ae-2644-4f04-8e64-bb359866e907';

    private const string EPISODE_ID = 'c7c41d7e-f0e5-4118-b5be-fedfb5a1e8ed';

    private const string PATIENT_ID = '7075e0e2-6b57-47fd-aff7-324806efa7e5';

    public function test_create_returns_the_scheduled_job_and_writes_nothing_locally(): void
    {
        $this->fakeApi([
            'data' => ['id' => 'job-1', 'eta' => '2026-08-13T12:35:49.956Z', 'status' => 'PENDING'],
        ]);

        $job = $this->service()->create(['type' => 'TEMP_DISABILITY']);

        $this->assertSame('job-1', $job['id']);
        $this->assertSame('PENDING', $job['status']);
        $this->assertSame(
            0,
            Composition::count(),
            'A conclusion must not be mirrored locally before eHealth has assigned it an id.'
        );
    }

    public function test_job_status_reports_pending_without_a_composition_id(): void
    {
        $this->fakeApi([
            'data' => ['status' => 'PENDING', 'links' => [['entity' => 'eHealth/resources']]],
        ]);

        $status = $this->service()->jobStatus('job-1');

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

        $status = $this->service()->jobStatus('job-1');

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

        $status = $this->service()->jobStatus('job-1');

        $this->assertSame('FAILED', $status['status']);
        $this->assertSame(['Invalid period'], $status['errors']);
    }

    /**
     * The documented payload exposes no id, so the conclusion has to be found by the
     * encounter it was built on.
     */
    public function test_a_finished_job_without_a_usable_link_falls_back_to_searching_by_encounter(): void
    {
        $this->fakeApi([
            'data' => [[
                'identifier' => ['value' => self::COMPOSITION_ID],
                'date' => '2026-08-13T10:00:00Z',
                'status' => 'PRELIMINARY',
            ]],
        ]);

        $resolved = $this->service()->resolveCreatedComposition(
            [['entity' => 'eHealth/resources']],
            self::PATIENT_ID,
            self::ENCOUNTER_ID,
            CompositionType::TEMP_DISABILITY
        );

        $this->assertSame(self::COMPOSITION_ID, $resolved);

        Http::assertSent(static function (Request $request): bool {
            return str_contains($request->url(), '/api/patients/searchComposition')
                && $request['encounter'] === self::ENCOUNTER_ID
                && $request['type'] === 'TEMP_DISABILITY';
        });
    }

    public function test_store_local_mirrors_the_conclusion_with_its_real_identifier(): void
    {
        $person = $this->person();

        $composition = $this->service()->storeLocal(
            $this->details(),
            $person,
            self::EPISODE_ID,
            'job-1'
        );

        $this->assertNotNull($composition);
        $this->assertSame(self::COMPOSITION_ID, $composition->uuid);
        $this->assertSame($person->id, $composition->personId);
        $this->assertSame(CompositionType::TEMP_DISABILITY, $composition->type);
        $this->assertSame(CompositionStatus::PRELIMINARY, $composition->status);
        $this->assertSame(self::ENCOUNTER_ID, $composition->encounterUuid);
        $this->assertSame(self::EPISODE_ID, $composition->episodeOfCareUuid);
        $this->assertSame('ТН-0001', $composition->title);
    }

    public function test_store_local_flattens_extensions_into_their_columns(): void
    {
        $composition = $this->service()->storeLocal($this->details([
            'extension' => [
                ['valueCode' => 'INFORM_WITH', 'valueUuid' => 'aaaaaaaa-0000-4000-8000-000000000001'],
                ['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => true],
                ['valueCode' => 'TREATMENT_VIOLATION', 'valueString' => 'reject_recommendation'],
                ['valueCode' => 'TREATMENT_VIOLATION_DATE', 'valueDate' => '2026-08-15'],
            ],
        ]), $this->person());

        $this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', $composition->informWithUuid);
        $this->assertTrue($composition->isAccident);
        $this->assertSame('reject_recommendation', $composition->treatmentViolation);
        $this->assertSame('2026-08-15', $composition->treatmentViolationDate->format('Y-m-d'));
    }

    /**
     * getComposition carries far more than searchComposition does, so a subsequent narrow
     * refresh must not blank out what was captured here.
     */
    public function test_a_later_narrower_refresh_does_not_erase_stored_details(): void
    {
        $person = $this->person();
        $service = $this->service();

        $service->storeLocal($this->details(), $person, self::EPISODE_ID);
        $service->storeLocal([
            'identifier' => ['value' => self::COMPOSITION_ID],
            'status' => 'FINAL',
        ], $person);

        $composition = Composition::whereUuid(self::COMPOSITION_ID)->first();

        $this->assertSame(CompositionStatus::FINAL, $composition->status);
        $this->assertSame('ТН-0001', $composition->title, 'Title must survive a narrower refresh.');
        $this->assertSame(self::ENCOUNTER_ID, $composition->encounterUuid);
    }

    public function test_a_response_without_an_identifier_is_ignored(): void
    {
        $this->assertNull($this->service()->storeLocal(['status' => 'FINAL'], $this->person()));
        $this->assertSame(0, Composition::count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function details(array $overrides = []): array
    {
        return array_merge([
            'identifier' => ['value' => self::COMPOSITION_ID],
            'status' => 'PRELIMINARY',
            'title' => 'ТН-0001',
            'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'TEMP_DISABILITY']]],
            'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => 'SICKNESS']]],
            'date' => '2026-08-13T10:00:00Z',
            'encounter' => ['value' => self::ENCOUNTER_ID],
            'author' => ['value' => '43cc2161-1c2b-481b-a618-77e35817f850'],
            'custodian' => ['value' => 'bbbbbbbb-0000-4000-8000-000000000001'],
            'subject' => ['value' => self::PATIENT_ID],
            'section' => ['focus' => ['value' => self::PATIENT_ID]],
            'event' => [['period' => ['start' => '2026-08-01T00:00:01Z', 'end' => '2026-08-10T20:59:59Z']]],
        ], $overrides);
    }

    private function person(): Person
    {
        return Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Пацієнт',
            'last_name' => 'Якийсь',
            'birth_date' => '2001-02-23',
            'gender' => 'MALE',
        ]);
    }

    private function service(): CompositionLifecycleService
    {
        return new CompositionLifecycleService();
    }

    /**
     * Point the API client at the faked HTTP factory.
     *
     * `EHealth::composition()` resolves a fresh client with no factory of its own, so it
     * would otherwise bypass `Http::fake()` and attempt a real request. Must be called
     * after the fake is registered, since faking replaces the recorded stubs.
     */
    private function fakeApi(array $response, int $status = 200): void
    {
        Http::fake(['*' => Http::response($response, $status)]);

        $factory = Http::getFacadeRoot();
        $api = new CompositionApi($factory);
        $api->stub((function () {
            return $this->stubCallbacks;
        })->call($factory));

        $this->instance(CompositionApi::class, $api);
    }
}
