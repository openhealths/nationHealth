<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Classes\eHealth\Api\Patient\ServiceRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Models\Person\Person;
use App\Services\MedicalEvents\PatientApprovalService;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\NativeClinicalFixtures;
use Tests\TestCase;

class PatientApprovalReferralBoundaryTest extends TestCase
{
    public function test_malformed_referral_registry_is_rejected_before_paging_or_approval_selection(): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andThrow(ValidationException::withMessages(['data.0' => 'Invalid referral.']));
        $response->shouldNotReceive('getData');
        $response->shouldNotReceive('isNotLast');
        $api = $this->mock(ServiceRequest::class);
        $api->shouldReceive('getBySearchParams')->once()->with(NativeClinicalFixtures::UUID, ['page' => 1])->andReturn($response);
        $this->expectException(ValidationException::class);

        app(PatientApprovalService::class)->patientReferrals($this->patient());
    }

    public function test_malformed_later_page_returns_no_partial_approval_selection(): void
    {
        $first = Mockery::mock(EHealthResponse::class);
        $first->shouldReceive('validate')->once()->andReturn([['id' => NativeClinicalFixtures::UUID, 'status' => 'active', 'permitted_resources' => []]]);
        $first->shouldReceive('isNotLast')->once()->andReturnTrue();
        $first->shouldNotReceive('getData');
        $second = Mockery::mock(EHealthResponse::class);
        $second->shouldReceive('validate')->once()->andThrow(ValidationException::withMessages(['data.0' => 'Invalid second page.']));
        $second->shouldNotReceive('isNotLast');
        $second->shouldNotReceive('getData');
        $api = $this->mock(ServiceRequest::class);
        $api->shouldReceive('getBySearchParams')->once()->with(NativeClinicalFixtures::UUID, ['page' => 1])->andReturn($first);
        $api->shouldReceive('getBySearchParams')->once()->with(NativeClinicalFixtures::UUID, ['page' => 2])->andReturn($second);
        $this->expectException(ValidationException::class);

        app(PatientApprovalService::class)->patientReferrals($this->patient());
    }

    public function test_referral_detail_is_validated_before_permitted_resources_are_used(): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andThrow(ValidationException::withMessages(['permitted_resources' => 'Invalid resource reference.']));
        $response->shouldNotReceive('getData');
        $api = $this->mock(ServiceRequest::class);
        $api->shouldReceive('getById')->once()->with(NativeClinicalFixtures::UUID, NativeClinicalFixtures::UUID)->andReturn($response);
        $this->expectException(ValidationException::class);

        app(PatientApprovalService::class)->referralDetails($this->patient(), NativeClinicalFixtures::UUID);
    }

    public function test_validated_referral_registry_preserves_approval_eligibility(): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([['id' => NativeClinicalFixtures::UUID, 'status' => 'active', 'requisition' => 'ABCD-1234-5678-9012', 'permitted_resources' => [['identifier' => ['value' => NativeClinicalFixtures::UUID]]]]]);
        $response->shouldReceive('isNotLast')->once()->andReturnFalse();
        $response->shouldNotReceive('getData');
        $api = $this->mock(ServiceRequest::class);
        $api->shouldReceive('getBySearchParams')->once()->andReturn($response);

        $result = app(PatientApprovalService::class)->patientReferrals($this->patient());

        $this->assertTrue($result[NativeClinicalFixtures::UUID]['selectable']);
        $this->assertSame('ABCD-1234-5678-9012', $result[NativeClinicalFixtures::UUID]['requisition']);
    }

    private function patient(): Person
    {
        return (new Person())->forceFill(['uuid' => NativeClinicalFixtures::UUID]);
    }
}
