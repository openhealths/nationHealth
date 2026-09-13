<?php

declare(strict_types=1);

namespace Tests\Unit\Employee;

use App\Classes\eHealth\Api\EmployeeRequest as EmployeeRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Employee\RequestStatus;
use App\Events\EHealthUserLogin;
use App\Jobs\EmployeeRequestPendingApply;
use App\Listeners\eHealth\EmployeeCreate;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeCreatePendingEditDispatchTest extends TestCase
{
    #[Test]
    #[DataProvider('pendingEditActionProvider')]
    public function resolve_pending_edit_action_matches_list_status(?string $remoteStatus, string $expected): void
    {
        $listener = new EmployeeCreate();
        $method = new ReflectionMethod(EmployeeCreate::class, 'resolvePendingEditAction');

        $this->assertSame($expected, $method->invoke($listener, $remoteStatus));
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function pendingEditActionProvider(): array
    {
        return [
            'missing from list' => [null, 'queue'],
            'empty status' => ['', 'queue'],
            'still new' => ['NEW', 'skip'],
            'legacy signed' => ['SIGNED', 'skip'],
            'approved' => ['APPROVED', 'apply'],
            'rejected' => ['REJECTED', 'reject'],
            'expired' => ['EXPIRED', 'expire'],
            'unknown' => ['SOMETHING_ELSE', 'skip'],
        ];
    }

    #[Test]
    public function fetch_remote_request_status_map_indexes_uuid_to_status(): void
    {
        $legalEntity = new LegalEntity([
            'edrpou' => '12345678',
        ]);
        $legalEntity->id = 10;

        $firstUuid = (string) Str::uuid();
        $secondUuid = (string) Str::uuid();

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([
            ['uuid' => $firstUuid, 'status' => 'APPROVED'],
            ['uuid' => $secondUuid, 'status' => 'NEW'],
        ]);

        $api = Mockery::mock(EmployeeRequestApi::class);
        $api->shouldReceive('getMany')
            ->once()
            ->withArgs(function (array $filters, ?int $page): bool {
                return ($filters['edrpou'] ?? null) === '12345678'
                    && isset($filters['page_size'])
                    && $page === 1;
            })
            ->andReturn($response);
        $this->instance(EmployeeRequestApi::class, $api);

        $listener = new EmployeeCreate();
        $method = new ReflectionMethod(EmployeeCreate::class, 'fetchRemoteRequestStatusMap');
        $map = $method->invoke($listener, $legalEntity);

        $this->assertSame([
            $firstUuid => 'APPROVED',
            $secondUuid => 'NEW',
        ], $map->all());
    }

    #[Test]
    public function fetch_remote_request_status_map_returns_empty_on_api_failure(): void
    {
        $legalEntity = new LegalEntity(['edrpou' => '12345678']);
        $legalEntity->id = 10;

        $api = Mockery::mock(EmployeeRequestApi::class);
        $api->shouldReceive('getMany')->once()->andThrow(new \RuntimeException('eHealth down'));
        $this->instance(EmployeeRequestApi::class, $api);

        $listener = new EmployeeCreate();
        $method = new ReflectionMethod(EmployeeCreate::class, 'fetchRemoteRequestStatusMap');

        $this->assertTrue($method->invoke($listener, $legalEntity)->isEmpty());
    }

    #[Test]
    public function dispatch_pending_edit_apply_jobs_builds_rate_limited_chain(): void
    {
        Bus::fake();
        $this->withSession([
            config('ehealth.api.oauth.bearer_token') => 'test-ehealth-token',
        ]);

        $user = new User();
        $user->id = 1;
        $user->email = 'doc@example.com';

        $legalEntity = new LegalEntity();
        $legalEntity->id = 10;
        $legalEntity->uuid = (string) Str::uuid();

        $first = new EmployeeRequest([
            'uuid' => (string) Str::uuid(),
            'status' => RequestStatus::NEW,
            'employee_id' => 5,
        ]);
        $first->id = 1;

        $second = new EmployeeRequest([
            'uuid' => (string) Str::uuid(),
            'status' => RequestStatus::NEW,
            'employee_id' => 6,
        ]);
        $second->id = 2;

        $event = new EHealthUserLogin($user, $legalEntity, (string) Str::uuid(), []);

        $listener = new EmployeeCreate();
        $method = new ReflectionMethod(EmployeeCreate::class, 'dispatchPendingEditApplyJobs');
        $method->invoke($listener, collect([$first, $second]), $event);

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 1
                && $batch->jobs->first() instanceof EmployeeRequestPendingApply
                && $batch->name === EmployeeRequestPendingApply::BATCH_NAME;
        });
    }

    #[Test]
    public function dispatch_pending_edit_apply_jobs_skips_empty_collection(): void
    {
        Bus::fake();
        $this->withSession([
            config('ehealth.api.oauth.bearer_token') => 'test-ehealth-token',
        ]);

        $user = new User();
        $user->id = 1;
        $legalEntity = new LegalEntity();
        $legalEntity->id = 10;

        $event = new EHealthUserLogin($user, $legalEntity, (string) Str::uuid(), []);

        $listener = new EmployeeCreate();
        $method = new ReflectionMethod(EmployeeCreate::class, 'dispatchPendingEditApplyJobs');
        $method->invoke($listener, collect(), $event);

        Bus::assertNothingBatched();
    }
}
