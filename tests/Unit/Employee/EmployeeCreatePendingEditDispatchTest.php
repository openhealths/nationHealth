<?php

declare(strict_types=1);

namespace Tests\Unit\Employee;

use App\Enums\Employee\RequestStatus;
use App\Events\EHealthUserLogin;
use App\Jobs\EmployeeRequestPendingApply;
use App\Listeners\eHealth\EmployeeCreate;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeCreatePendingEditDispatchTest extends TestCase
{
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
