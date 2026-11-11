<?php

declare(strict_types=1);

namespace Tests\Feature\Encounter;

use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\Api\Patient\Episode as EpisodeApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Episode\Status;
use App\Livewire\Episode\EpisodeCreate;
use App\Livewire\Episode\Forms\EpisodeForm;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\Person\Person;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeClinicalFixtures as Native;
use Tests\TestCase;
use Throwable;

class EpisodeCreateResponseBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    private array $requestedMethods = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ehealth.api.domain' => 'https://ehealth.test', 'ehealth.jobs.max_attempts' => 1, 'ehealth.jobs.interval_seconds' => 0]);
        Http::preventStrayRequests();
        app()->setLocale('uk');
    }

    private function creationHarness(bool $allowed = true): EpisodeCreateBoundaryHarness
    {
        $person = Person::create(['uuid' => Native::UUID, 'birth_date' => '1990-01-01', 'gender' => 'MALE']);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('cannot')->once()->with('create', Episode::class)->andReturn(!$allowed);
        Auth::shouldReceive('user')->andReturn($user);
        $component = new EpisodeCreateBoundaryHarness();
        $component->uuid = $person->uuid;
        $component->personId = $person->id;
        $form = Mockery::mock(EpisodeForm::class);
        $form->shouldReceive('rulesForCreate')->andReturn([]);
        $form->shouldReceive('validate')->with([])->andReturn([]);
        $component->form = $form;

        return $component;
    }

    private function nativeEpisode(): array
    {
        $native = json_decode(file_get_contents(base_path('tests/Fixtures/shared-official-responses.json')), true, flags: JSON_THROW_ON_ERROR)['GET /api/patients/{patient_id}/episodes/{episode_id}']['data'];
        $native['id'] = Native::UUID;
        $native['name'] = 'Authoritative episode';
        $native['managing_organization'] = Native::reference('legal_entity');
        $native['care_manager'] = Native::reference('employee');
        // A new active Episode can omit these optional histories and closing fields.
        unset($native['current_diagnoses'], $native['diagnoses_history'], $native['status_history'], $native['status_reason'], $native['closing_summary'], $native['explanatory_letter'], $native['period']['end']);

        return $native;
    }

    private function client(array $native): EpisodeApi
    {
        $factory = new Factory();
        $factory->fake(['ehealth.test/*' => function (Request $request) use ($native) {
            $this->requestedMethods[] = $request->method();

            return Http::response(['data' => $request->method() === 'POST'
                ? ['status' => 'pending', 'eta' => '2026-11-11T10:00:00Z', 'links' => [['entity' => 'job', 'href' => '/api/jobs/episode-create']]]
                : $native]);
        }]);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api = new EpisodeApi($factory);
        $api->stub($stubs)->preventStrayRequests();
        $this->instance(EpisodeApi::class, $api);

        return $api;
    }

    private function job(string $status): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(['status' => $status, 'links' => []]);
        $this->mock(Job::class)->shouldReceive('getDetails')->once()->with('episode-create')->andReturn($response);
    }

    public function test_processed_job_imports_the_validated_full_episode_with_optional_histories_absent(): void
    {
        $component = $this->creationHarness();
        $native = $this->nativeEpisode();
        $this->client($native);
        $this->job('processed');

        $component->create();

        $episode = Episode::whereUuid(Native::UUID)->firstOrFail();
        $this->assertSame($component->personId, $episode->person_id);
        $this->assertSame($native['name'], $episode->name);
        $this->assertSame(Carbon::parse($native['period']['start'])->toIso8601ZuluString(), Carbon::parse($episode->period->getRawOriginal('start'), 'UTC')->toIso8601ZuluString());
        $this->assertNotNull($episode->ehealth_inserted_at);
        $this->assertNull($episode->status_reason_id);
        $this->assertCount(0, $episode->statusHistory);
        $this->assertTrue($component->redirected);
        $this->assertSame(__('episodes.messages.created'), session('success'));
    }

    #[DataProvider('invalidDetails')]
    public function test_processed_job_cannot_write_a_malformed_or_mismatched_episode(string $field): void
    {
        $component = $this->creationHarness();
        $native = $this->nativeEpisode();
        if ($field === 'missing-period') {
            unset($native['period']);
        } elseif ($field === 'status') {
            $native['status'] = 'closed';
        } else {
            data_set($native, $field, 'c7d52544-0bd4-4129-97b0-2d72633e0490');
        }
        $this->client($native);
        $this->job('processed');
        $identifiers = DB::table('identifiers')->count();

        $component->create();

        $this->assertDatabaseMissing('episodes', ['uuid' => Native::UUID]);
        $this->assertSame($identifiers, DB::table('identifiers')->count());
        $this->assertFalse($component->redirected);
        $this->assertSame(__('errors.ehealth.messages.validation_error'), session('error'));
    }

    public static function invalidDetails(): array
    {
        return [['missing-period'], ['id'], ['status'], ['managing_organization.identifier.value'], ['care_manager.identifier.value']];
    }

    #[DataProvider('unsuccessfulJobs')]
    public function test_pending_or_failed_job_has_no_detail_read_or_local_write(string $status): void
    {
        $component = $this->creationHarness();
        $this->client($this->nativeEpisode());
        $this->job($status);

        $component->create();

        $this->assertSame(['POST'], $this->requestedMethods);
        $this->assertDatabaseMissing('episodes', ['uuid' => Native::UUID]);
        $this->assertFalse($component->redirected);
    }

    public static function unsuccessfulJobs(): array
    {
        return [['processing'], ['failed']];
    }

    public function test_policy_denial_stops_api_and_database_effects(): void
    {
        $component = $this->creationHarness(false);
        $this->mock(EpisodeApi::class)->shouldNotReceive('create')->shouldNotReceive('getById');
        $this->mock(Job::class)->shouldNotReceive('getDetails');

        $component->create();

        $this->assertDatabaseMissing('episodes', ['uuid' => Native::UUID]);
        $this->assertSame(__('episodes.policy.create'), session('error'));
        Http::assertNothingSent();
    }
}

/** Retain the real API, job, identity and database boundaries while isolating form UI setup. */
class EpisodeCreateBoundaryHarness extends EpisodeCreate
{
    public bool $redirected = false;

    protected function formatEpisode(array $validated, Status $status): array
    {
        return ['id' => Native::UUID, 'status' => $status->value, 'name' => 'Submitted name',
            'managingOrganization' => Native::reference('legal_entity'), 'careManager' => Native::reference('employee')];
    }

    protected function redirectToEpisodes(): void
    {
        $this->redirected = true;
    }

    protected function handleDatabaseErrors(Throwable $exception, string $logMessage, ?string $flashMessage = null): void
    {
        throw $exception;
    }
}
