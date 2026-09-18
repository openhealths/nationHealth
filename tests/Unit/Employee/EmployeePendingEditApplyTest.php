<?php

declare(strict_types=1);

namespace Tests\Unit\Employee;

use App\Events\EHealthUserLogin;
use App\Listeners\eHealth\EmployeePendingEditApply;
use App\Models\LegalEntity;
use App\Models\User;
use App\Services\Employee\EmployeeRequestProcessor;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeePendingEditApplyTest extends TestCase
{
    #[Test]
    public function skips_processor_when_user_lacks_employee_request_read(): void
    {
        $processor = Mockery::mock(EmployeeRequestProcessor::class);
        $processor->shouldNotReceive('syncSinglePendingRequest');

        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 10;
        $user->email = 'owner@example.com';
        $user->shouldReceive('loadMissing')->andReturnSelf();
        $user->shouldReceive('can')->with('employee_request:read')->andReturn(false);

        $legalEntity = new LegalEntity();
        $legalEntity->id = 1;

        $event = new EHealthUserLogin(
            $user,
            $legalEntity,
            (string) Str::uuid(),
            ['employee:read']
        );

        (new EmployeePendingEditApply($processor))->handle($event);
    }
}
