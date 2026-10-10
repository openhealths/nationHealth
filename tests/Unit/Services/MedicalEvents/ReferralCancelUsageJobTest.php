<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Classes\eHealth\Api\Patient\ServiceRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;
use App\Services\MedicalEvents\EHealthJobResolver;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class ReferralCancelUsageJobTest extends TestCase
{
    public function test_cancel_usage_resolves_job_before_writing_local_status(): void
    {
        $pending = ['links' => [['href' => '/api/jobs/fixture-job']], 'status' => 'pending'];
        $this->mock(ServiceRequest::class)->shouldReceive('cancelUsage')->once()
            ->with('fixture-referral', 'fixture-person', ['explanatory_letter' => 'fixture reason'])
            ->andReturn(new EHealthResponse(new Response(200, [], json_encode(['data' => $pending]))));
        $resolver = $this->mock(EHealthJobResolver::class);
        $resolver->shouldReceive('resolve')->once()->with($pending)->andThrow(new \RuntimeException('failed job'));
        $this->mock(ServiceRequestRequestRepository::class)->shouldNotReceive('findByUuid');
        $this->expectException(\RuntimeException::class);
        app(ReferralRequestLifecycleService::class)->cancelUsage('fixture-referral', 'fixture-person', ['explanatory_letter' => 'fixture reason']);
    }
}
