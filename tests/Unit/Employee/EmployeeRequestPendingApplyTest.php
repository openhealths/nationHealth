<?php

declare(strict_types=1);

namespace Tests\Unit\Employee;

use App\Classes\eHealth\EHealthResponse;
use App\Enums\Employee\RequestStatus;
use App\Enums\Employee\RevisionStatus;
use App\Jobs\EmployeeRequestPendingApply;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Revision;
use App\Services\Employee\EmployeeRequestProcessor;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeRequestPendingApplyTest extends TestCase
{
    private function makeRequest(): EmployeeRequest
    {
        $request = new EmployeeRequest([
            'uuid' => (string) Str::uuid(),
            'status' => RequestStatus::NEW,
            'employee_id' => 1,
        ]);
        $request->id = 99;
        $request->setRelation('revision', new Revision([
            'status' => RevisionStatus::PENDING,
            'data' => ['party' => ['tax_id' => '1234567890']],
        ]));
        $request->setRelation('employee', null);
        $request->setRelation('party', null);
        $request->setRelation('division', null);

        return $request;
    }

    #[Test]
    public function process_response_skips_apply_when_remote_still_new(): void
    {
        $request = $this->makeRequest();
        $job = new EmployeeRequestPendingApply(
            employeeRequest: $request,
            legalEntity: new LegalEntity(),
            standalone: true,
        );

        $processor = Mockery::mock(EmployeeRequestProcessor::class);
        $processor->shouldNotReceive('applyApprovedRequest');
        $this->instance(EmployeeRequestProcessor::class, $processor);

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([
            'uuid' => $request->uuid,
            'status' => 'NEW',
            'employee_id' => (string) Str::uuid(),
        ]);

        $method = new ReflectionMethod(EmployeeRequestPendingApply::class, 'processResponse');
        $method->invoke($job, $response);
    }

    #[Test]
    public function process_response_applies_when_remote_approved(): void
    {
        $request = $this->makeRequest();
        $legalEntity = new LegalEntity();
        $legalEntity->uuid = (string) Str::uuid();
        $employeeUuid = (string) Str::uuid();

        $job = new EmployeeRequestPendingApply(
            employeeRequest: $request,
            legalEntity: $legalEntity,
            standalone: true,
        );

        $processor = Mockery::mock(EmployeeRequestProcessor::class);
        $processor->shouldReceive('applyApprovedRequest')->once()->withArgs(
            function (EmployeeRequest $req, array $payload) use ($request, $employeeUuid): bool {
                return $req->is($request)
                    && ($payload['employee_id'] ?? null) === $employeeUuid
                    && ($payload['status'] ?? null) === 'APPROVED';
            }
        );
        $this->instance(EmployeeRequestProcessor::class, $processor);

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([
            'uuid' => $request->uuid,
            'status' => 'APPROVED',
            'employee_id' => $employeeUuid,
        ]);

        $method = new ReflectionMethod(EmployeeRequestPendingApply::class, 'processResponse');
        $method->invoke($job, $response);
    }
}
