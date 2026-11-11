<?php

declare(strict_types=1);

namespace Tests\Feature\Encounter;

use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\Api\Patient\Episode as EpisodeApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\EncounterPackage\EncounterPackageCreate;
use App\Livewire\EncounterPackage\Forms\EncounterForm;
use App\Models\User;
use App\Services\MedicalEvents\EncounterPackageBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\NativeClinicalFixtures;
use Tests\TestCase;
use Throwable;

class EncounterEpisodeSubmissionBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['ehealth.jobs.max_attempts' => 1, 'ehealth.jobs.interval_seconds' => 0]);
        app()->setLocale('uk');
    }

    public function test_invalid_episode_receipt_blocks_package_persistence_and_keeps_signature_feedback_visible(): void
    {
        $component = $this->submissionComponent();
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andThrow(ValidationException::withMessages(['status' => 'Invalid receipt']));
        $this->mock(EpisodeApi::class)->shouldReceive('create')->once()->andReturn($response);
        $this->mock(Job::class)->shouldNotReceive('getDetails');

        $component->sign();

        $this->assertSame(0, $component->persistCalls);
        $this->assertTrue($component->showSignatureModal);
        $this->assertSame(__('errors.ehealth.messages.validation_error'), session('error'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('jobOutcomes')]
    public function test_package_is_persisted_only_after_episode_job_confirmation(string $status, int $expectedWrites): void
    {
        $component = $this->submissionComponent();
        $receipt = Mockery::mock(EHealthResponse::class);
        $receipt->shouldReceive('validate')->once()->andReturn([
            'status' => 'pending', 'eta' => '2026-11-11T10:00:00Z',
            'links' => [['entity' => 'job', 'href' => '/api/jobs/episode-job']],
        ]);
        $this->mock(EpisodeApi::class)->shouldReceive('create')->once()->andReturn($receipt);
        $result = Mockery::mock(EHealthResponse::class);
        $result->shouldReceive('validate')->once()->andReturn(['status' => $status, 'links' => []]);
        $this->mock(Job::class)->shouldReceive('getDetails')->once()->with('episode-job')->andReturn($result);
        $episodeApi = app(EpisodeApi::class);
        if ($expectedWrites === 1) {
            $detail = Mockery::mock(EHealthResponse::class);
            $detail->shouldReceive('validate')->once()->andReturn($this->episodeDetail());
            $episodeApi->shouldReceive('getById')->once()->with(NativeClinicalFixtures::UUID, NativeClinicalFixtures::UUID)->andReturn($detail);
        } else {
            $episodeApi->shouldNotReceive('getById');
        }

        $component->sign();

        $this->assertSame($expectedWrites, $component->persistCalls);
        $this->assertSame($expectedWrites === 1, $component->stoppedBeforeSigning);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function jobOutcomes(): array
    {
        return [['processing', 0], ['failed', 0], ['processed', 1]];
    }

    #[DataProvider('invalidEpisodeIdentity')]
    public function test_confirmed_job_cannot_persist_an_invalid_or_mismatched_episode_detail(?string $field): void
    {
        $component = $this->submissionComponent();
        $receipt = Mockery::mock(EHealthResponse::class);
        $receipt->shouldReceive('validate')->once()->andReturn(['status' => 'processed']);
        $detail = Mockery::mock(EHealthResponse::class);
        if ($field === null) {
            $detail->shouldReceive('validate')->once()->andThrow(ValidationException::withMessages(['period' => 'Invalid period']));
        } else {
            $data = $this->episodeDetail();
            data_set($data, $field, $field === 'status' ? 'cancelled' : 'ae4aa5d7-819d-4f3e-9b57-b2d94e9253c5');
            $detail->shouldReceive('validate')->once()->andReturn($data);
        }
        $api = $this->mock(EpisodeApi::class);
        $api->shouldReceive('create')->once()->andReturn($receipt);
        $api->shouldReceive('getById')->once()->andReturn($detail);

        $component->sign();

        $this->assertSame(0, $component->persistCalls);
        $this->assertTrue($component->showSignatureModal);
        $this->assertSame(__('errors.ehealth.messages.validation_error'), session('error'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function invalidEpisodeIdentity(): array
    {
        return [[null], ['uuid'], ['status'], ['managing_organization.identifier.value'], ['care_manager.identifier.value']];
    }

    public function test_policy_denial_stops_before_episode_submission_and_local_persistence(): void
    {
        $component = $this->submissionComponent(allowed: false);
        $this->mock(EpisodeApi::class)->shouldNotReceive('create');

        $component->sign();

        $this->assertSame(0, $component->persistCalls);
        $this->assertSame(__('encounters.policy.create'), session('error'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function submissionComponent(bool $allowed = true): EncounterEpisodeSubmissionHarness
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('cannot')->with('create', Mockery::any())->once()->andReturn(!$allowed);
        Auth::shouldReceive('user')->andReturn($user);
        $component = new EncounterEpisodeSubmissionHarness();
        $component->patientUuid = NativeClinicalFixtures::UUID;
        $component->showSignatureModal = true;
        $form = Mockery::mock(EncounterForm::class);
        $form->shouldReceive('signingRules')->andReturn([]);
        $form->shouldReceive('validate')->with([])->andReturn([]);
        $component->form = $form;
        $builder = Mockery::mock(EncounterPackageBuilder::class);
        $builder->shouldReceive('build')->andReturn(['episode' => [
            'id' => NativeClinicalFixtures::UUID,
            'managingOrganization' => NativeClinicalFixtures::reference('legal_entity'),
            'careManager' => NativeClinicalFixtures::reference('employee'),
        ]]);
        (new ReflectionProperty(EncounterPackageCreate::class, 'packageBuilder'))->setValue($component, $builder);

        return $component;
    }

    private function episodeDetail(): array
    {
        return [
            'uuid' => NativeClinicalFixtures::UUID,
            'status' => 'active',
            'managing_organization' => NativeClinicalFixtures::reference('legal_entity'),
            'care_manager' => NativeClinicalFixtures::reference('employee'),
        ];
    }
}

/** Exercise the real signing orchestration and stop immediately after its persistence boundary. */
class EncounterEpisodeSubmissionHarness extends EncounterPackageCreate
{
    public int $persistCalls = 0;

    public bool $stoppedBeforeSigning = false;

    public function syncEncounterParticipants(): void
    {
    }

    public function validate($rules = null, $messages = [], $attributes = [])
    {
        return [];
    }

    protected function resolveAllReferrals(array &$validated, bool $requirePreparation = false): void
    {
    }

    protected function syncReferencedRecords(): void
    {
        // This fixture has no referenced records; keep the real episode receipt, job and detail boundaries in sign().
    }

    protected function syncSelectedEpisode(?string $episodeId): void
    {
    }

    protected function storeValidatedData(array $formattedData): int
    {
        $this->persistCalls++;
        throw new RuntimeException('Stop before external signing.');
    }

    protected function handleDatabaseErrors(Throwable $exception, string $logMessage, ?string $flashMessage = null): void
    {
        // Only the persistence sentinel ends this test; a broken fixture must fail instead of hiding an earlier error.
        if (!$exception instanceof RuntimeException || $exception->getMessage() !== 'Stop before external signing.') {
            throw $exception;
        }

        $this->stoppedBeforeSigning = true;
    }
}
