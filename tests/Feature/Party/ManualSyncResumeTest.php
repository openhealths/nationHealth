<?php

declare(strict_types=1);

namespace Tests\Feature\Party;

use App\Enums\JobStatus;
use App\Jobs\DivisionSync;
use App\Livewire\Declaration\DeclarationIndex;
use App\Livewire\Division\DivisionIndex;
use App\Livewire\Division\HealthcareService\HealthcareServiceIndex;
use App\Livewire\Employee\EmployeeIndex;
use App\Livewire\EmployeeRequest\EmployeeRequestIndex;
use App\Livewire\EmployeeRole\EmployeeRoleIndex;
use App\Livewire\Equipment\EquipmentIndex;
use App\Livewire\LegalEntity\LegalEntityDetails;
use App\Models\LegalEntity;
use App\Models\User;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class ManualSyncResumeTest extends TestCase
{
    use DatabaseTransactions;

    private LegalEntity $legalEntity;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'pagination.per_page' => 10]);
        Notification::fake();
        Http::preventStrayRequests();
        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'status' => 'ACTIVE',
            'legal_entity_type_id' => DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id'),
            'sync_status' => JobStatus::COMPLETED->value,
        ]);
        $this->app->instance('legalEntity', $this->legalEntity);
        $this->user = User::create(['uuid' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.com', 'password' => 'unused']);
        $this->actingAs($this->user, 'ehealth');
        $this->withSession([config('ehealth.api.oauth.bearer_token') => 'new-token']);
    }

    public static function manualComponents(): array
    {
        $components = [
            [DivisionIndex::class, LegalEntity::ENTITY_DIVISION],
            [HealthcareServiceIndex::class, LegalEntity::ENTITY_HEALTHCARE_SERVICE],
            [EquipmentIndex::class, LegalEntity::ENTITY_EQUIPMENT],
            [EmployeeRoleIndex::class, LegalEntity::ENTITY_EMPLOYEE_ROLE],
            [DeclarationIndex::class, LegalEntity::ENTITY_DECLARATION],
            [LegalEntityDetails::class, ''],
            [EmployeeIndex::class, LegalEntity::ENTITY_EMPLOYEE],
            [EmployeeRequestIndex::class, LegalEntity::ENTITY_EMPLOYEE_REQUEST],
        ];
        $cases = [];
        foreach ($components as [$component, $entity]) {
            foreach (['invalid', 'missing', 'empty', 'valid'] as $payload) {
                $cases[$component . ' ' . $payload] = [$component, $entity, $payload];
            }
        }

        return $cases;
    }

    #[DataProvider('manualComponents')]
    public function testManualResumeOnlyChangesStatusAfterCreatingTheReplacement(string $componentClass, string $entity, string $payload): void
    {
        $this->legalEntity->setEntityStatus(JobStatus::PAUSED, $entity);
        [$batchId, $failedUuid] = $this->failedBatch((new ReflectionClass($componentClass))->getConstant('BATCH_NAME'), $payload);

        $resumed = (new ReflectionMethod($componentClass, 'resumeSynchronization'))->invoke(new $componentClass(), $this->user, 'new-token');

        $this->assertSame($payload === 'valid', $resumed);
        $this->assertSame($payload === 'valid' ? JobStatus::PROCESSING->value : JobStatus::PAUSED->value, $this->legalEntity->fresh()->getEntityStatus($entity));
        $this->assertSame($payload === 'valid' ? 1 : 0, DB::table('jobs')->where('queue', 'sync')->count());
        if ($payload === 'valid') {
            $this->assertDatabaseMissing('job_batches', ['id' => $batchId]);
            $this->assertDatabaseMissing('failed_jobs', ['uuid' => $failedUuid]);
        } else {
            $this->assertDatabaseHas('job_batches', ['id' => $batchId]);
            if ($payload === 'invalid') {
                $this->assertDatabaseHas('failed_jobs', ['uuid' => $failedUuid]);
            }
        }
        Notification::assertNothingSent();
    }

    public function testPatientResumeFailureDoesNotAnnounceSuccessOrChangeStatus(): void
    {
        $this->legalEntity->setEntityStatus(JobStatus::PAUSED, LegalEntity::ENTITY_OBSERVATION);
        $this->failedBatch('ObservationSync_patient-fixture', 'invalid');
        $component = new PatientResumeProbe();

        $component->resume();

        $this->assertSame(__('forms.errors.sync_resume_failed'), session('error'));
        $this->assertNull(session('success'));
        $this->assertFalse($component->statusChanged);
        $this->assertSame(JobStatus::PAUSED->value, $this->legalEntity->fresh()->getEntityStatus(LegalEntity::ENTITY_OBSERVATION));
        $this->assertSame(0, DB::table('jobs')->where('queue', 'sync')->count());
        Notification::assertNothingSent();
    }

    public function testDivisionButtonRemainsUsableAfterFailureAndShowsSuccessOnlyAfterRetry(): void
    {
        Gate::before(static fn () => true);
        $this->legalEntity->setEntityStatus(JobStatus::PAUSED, LegalEntity::ENTITY_DIVISION);
        [, $failedUuid] = $this->failedBatch('DivisionSync', 'invalid');
        $component = Livewire::test(DivisionResumeProbe::class)->call('sync')
            ->assertSee(__('forms.errors.sync_resume_failed'))
            ->assertDontSee(__('forms.success.sync_resumed'));
        $this->assertStringContainsString('wire:click="sync"', $component->html());
        $this->assertStringNotContainsString('button-sync-disabled', $component->html());
        Notification::assertNothingSent();

        DB::table('failed_jobs')->where('uuid', $failedUuid)->update([
            'payload' => json_encode(['data' => ['command' => serialize(new DivisionSync($this->legalEntity))]]),
        ]);
        $component->call('sync')->assertSee(__('forms.success.sync_resumed'))
            ->assertDontSee(__('forms.errors.sync_resume_failed'));
        $this->assertStringContainsString('button-sync-disabled', $component->html());
        $this->assertSame(JobStatus::PROCESSING->value, $this->legalEntity->fresh()->divisionSyncStatus);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'sync')->count());
    }

    private function failedBatch(string $name, string $payload): array
    {
        $batch = app(BatchRepository::class)->store(Bus::batch([])->name($name)->withOption('legal_entity_id', $this->legalEntity->id));
        $uuid = (string) Str::uuid();
        if (in_array($payload, ['invalid', 'valid'], true)) {
            DB::table('failed_jobs')->insert([
                'uuid' => $uuid, 'connection' => 'database', 'queue' => 'sync', 'exception' => 'Fixture failure', 'failed_at' => now(),
                'payload' => json_encode(['data' => ['command' => serialize($payload === 'valid' ? new DivisionSync($this->legalEntity) : new \stdClass())]]),
            ]);
        }
        DB::table('job_batches')->where('id', $batch->id)->update([
            'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 1,
            'failed_job_ids' => json_encode($payload === 'empty' ? [] : [$uuid]), 'cancelled_at' => now()->timestamp,
        ]);

        return [$batch->id, $uuid];
    }
}

class DivisionResumeProbe extends DivisionIndex
{
    public function mount(?LegalEntity $legalEntity = null): void
    {
        $this->dictionaries = ['DIVISION_TYPE' => [], 'SETTLEMENT_TYPE' => [], 'PHONE_TYPE' => []];
        $this->syncStatus = $this->getSyncStatus();
    }
}

class PatientResumeProbe
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;

    public string $uuid = 'patient-fixture';
    public bool $statusChanged = false;

    public function resume(): void
    {
        $this->handleResumeLogic('observation');
    }
    protected function getSyncStatus(string $entityType): ?string
    {
        return JobStatus::PAUSED->value;
    }
    protected function getBatchName(string $entityType): string
    {
        return 'ObservationSync';
    }
    protected function getJobClass(string $entityType): string
    {
        return DivisionSync::class;
    }
    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_OBSERVATION;
    }
    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void
    {
        $this->statusChanged = true;
    }
}
