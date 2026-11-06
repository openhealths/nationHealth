<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Enums\JobStatus;
use App\Jobs\DivisionSync;
use App\Jobs\EmployeeDetailsUpsert;
use App\Jobs\EmployeeRoleSync;
use App\Livewire\Employee\EmployeeIndex;
use App\Livewire\EmployeeRole\EmployeeRoleIndex;
use App\Livewire\LegalEntity\LegalEntityDetails;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\User;
use Closure;
use Illuminate\Bus\BatchRepository;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EHealthJobFirstLoginContextTest extends TestCase
{
    private LegalEntity $legalEntity;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Notification::fake();

        $this->legalEntity = Mockery::mock(LegalEntity::class)->makePartial();
        $this->legalEntity->forceFill([
            'id' => 2,
            'sync_status' => JobStatus::PROCESSING->value,
            'division_sync_status' => JobStatus::COMPLETED->value,
            'employee_sync_status' => JobStatus::PROCESSING->value,
        ]);
        // Keep model status checks real; replace only schema access and persistence.
        $this->legalEntity->shouldReceive('setEntityStatus')->andReturnUsing(
            fn (JobStatus $status, string $entityType = '') => $this->legalEntity->setAttribute(
                $entityType . 'sync_status',
                $status->value
            )
        );
        $this->app->instance('legalEntity', $this->legalEntity);
        $this->user = new User();
        $this->user->id = 1;
    }

    public function test_exhausted_detail_job_can_be_resumed_from_employees_without_relogin(): void
    {
        $job = new EmployeeDetailsUpsert(new Employee(), $this->legalEntity);
        $job->withFakeBatch(options: ['user' => $this->user, 'sync_entity' => '']);

        // The worker can call failed() before middleware() or handle() on the last attempt.
        $job->failed(new MaxAttemptsExceededException('Attempted too many times'));

        $this->assertSame(JobStatus::FAILED->value, $this->legalEntity->getEntityStatus());
        $this->assertSame(JobStatus::FAILED->value, $this->legalEntity->getEntityStatus(LegalEntity::ENTITY_EMPLOYEE));
        $this->assertFalse((new EmployeeIndex())->isSync());
        $this->assertFalse((new LegalEntityDetails())->isSync());
        $this->assertTrue((new EmployeeRoleIndex())->isSync());

        $this->actingAs($this->user);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'fresh-token');
        $failedBatch = (object)['id' => 'failed-batch', 'name' => EmployeeDetailsUpsert::BATCH_NAME];
        $component = Mockery::mock(EmployeeIndex::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $component->shouldReceive('findFailedBatchesByLegalEntity')->once()->with(2, 'ASC')
            ->andReturn(collect([$failedBatch]));
        $component->shouldReceive('restartBatch')->once()->with(
            $failedBatch,
            $this->user,
            Mockery::on(fn (string $token): bool => Crypt::decryptString($token) === 'fresh-token'),
            $this->legalEntity,
            Mockery::type(Closure::class)
        )->andReturnTrue();

        $component->sync();

        $this->assertNull(session('error'));
    }

    public function test_rate_limited_detail_job_pauses_the_overall_first_login_sync(): void
    {
        $job = Mockery::mock(EmployeeDetailsUpsert::class . '[getUserWithActiveSession]', [new Employee(), $this->legalEntity])
            ->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('getUserWithActiveSession')->once()->andReturnNull();
        $job->withFakeBatch(options: ['user' => $this->user, 'sync_entity' => '']);

        $job->failed(new RuntimeException('Too many requests', 429));

        $this->assertSame(JobStatus::PAUSED->value, $this->legalEntity->getEntityStatus());
        $this->assertSame(JobStatus::PAUSED->value, $this->legalEntity->getEntityStatus(LegalEntity::ENTITY_EMPLOYEE));
        $this->assertFalse((new EmployeeIndex())->isSync());
        Bus::assertNothingBatched();
    }

    #[DataProvider('manualBatchOptions')]
    public function test_manual_detail_sync_failure_keeps_overall_sync_completed(array $options): void
    {
        $this->legalEntity->setAttribute('sync_status', JobStatus::COMPLETED->value);
        $job = new EmployeeDetailsUpsert(new Employee(), $this->legalEntity);
        $job->withFakeBatch(options: ['user' => $this->user, ...$options]);

        $job->failed(new MaxAttemptsExceededException('Attempted too many times'));

        $this->assertSame(JobStatus::COMPLETED->value, $this->legalEntity->getEntityStatus());
        $this->assertSame(JobStatus::FAILED->value, $this->legalEntity->getEntityStatus(LegalEntity::ENTITY_EMPLOYEE));
    }

    public static function manualBatchOptions(): array
    {
        return [
            'explicit entity' => [['sync_entity' => LegalEntity::ENTITY_EMPLOYEE]],
            'no sync entity' => [[]],
        ];
    }

    public function test_first_login_flag_still_applies_without_batch_sync_entity(): void
    {
        $job = new DivisionSync($this->legalEntity, isFirstLogin: true);
        $job->withFakeBatch(options: ['user' => $this->user]);

        $job->failed(new MaxAttemptsExceededException('Attempted too many times'));

        $this->assertSame(JobStatus::FAILED->value, $this->legalEntity->getEntityStatus());
    }

    public function test_detail_job_restores_first_login_context_before_handling(): void
    {
        $nextJob = new EmployeeRoleSync($this->legalEntity, isFirstLogin: true);
        $job = Mockery::mock(EmployeeDetailsUpsert::class . '[sendRequest,processResponse]', [new Employee(), $this->legalEntity, $nextJob])
            ->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('sendRequest')->once()->with('token')->andReturnNull();
        $job->shouldReceive('processResponse')->once()->with(null);
        $job->withFakeBatch(options: [
            'user' => $this->user,
            'token' => Crypt::encryptString('token'),
            'sync_entity' => '',
        ]);
        $job->middleware();
        $this->expectOutputRegex('/EmployeeDetailsSync Page: 1 is First Login: Yes/');

        $job->handle();

        Bus::assertBatched(fn ($batch): bool => $batch->jobs[0] === $nextJob && $batch->options['sync_entity'] === '');
        $this->assertSame(JobStatus::PROCESSING->value, $this->legalEntity->getEntityStatus());
    }

    public function test_automatic_retry_preserves_first_login_context_and_remaining_chain(): void
    {
        $nextJob = new EmployeeRoleSync($this->legalEntity, isFirstLogin: true);
        $job = Mockery::mock(EmployeeDetailsUpsert::class . '[getUserWithActiveSession,getToken]', [new Employee(), $this->legalEntity, $nextJob])
            ->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('getUserWithActiveSession')->once()->andReturn($this->user);
        $job->shouldReceive('getToken')->once()->andReturn('fresh-token');
        [, $oldBatch] = $job->withFakeBatch(options: ['user' => $this->user, 'sync_entity' => '']);
        $this->mock(BatchRepository::class)->shouldReceive('delete')->once()->with($oldBatch->id);

        $job->failed(new RuntimeException('Too many requests', 429));

        Bus::assertBatched(function ($batch) use ($nextJob): bool {
            $remainingChain = (function () {
                return $this->nextEntity;
            })->call($batch->jobs[0]);

            return $batch->name === 'FirstLoginSync_retry_EmployeeDetailsSync'
                && $batch->options['sync_entity'] === ''
                && Crypt::decryptString($batch->options['token']) === 'fresh-token'
                && $remainingChain === $nextJob;
        });
    }
}
