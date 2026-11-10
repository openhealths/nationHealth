<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Classes\eHealth\Api\Approval;
use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Psr7\Response;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class PatientApprovalResponseValidationTest extends TestCase
{
    private function resource(): array
    {
        $identifier = ['identifier' => ['value' => '22222222-2222-4222-8222-222222222222',
            'type' => ['coding' => [['code' => 'employee', 'system' => 'eHealth/resources']]]]];

        return [
            'id' => '11111111-1111-4111-8111-111111111111', 'is_verified' => false,
            'granted_resources' => [], 'granted_resource_types' => [],
            'granted_to' => $identifier, 'created_by' => $identifier,
            'access_level' => 'read', 'expires_at' => '2030-01-01T00:00:00Z',
            'authentication_method_current' => ['type' => 'OTP', 'number' => '+38093*****85'],
        ];
    }

    public function test_complete_patient_responses_are_validated_for_creation_details_and_cancellation(): void
    {
        foreach (['validateCreate' => 201, 'validateApproval' => 200, 'validateCancellation' => 201] as $method => $status) {
            $resource = $this->resource();
            $result = (new ReflectionMethod(Approval::class, $method))->invoke(new Approval(), new EHealthResponse(new Response($status, [], json_encode(['data' => $resource]))));
            $this->assertEquals($resource, $result);
        }
    }

    public static function malformedResources(): array
    {
        $cases = [];
        foreach (['validateCreate', 'validateApproval', 'validateCancellation'] as $method) {
            foreach (['id', 'is_verified', 'granted_resources', 'granted_resource_types', 'granted_to', 'access_level', 'expires_at', 'authentication_method_current'] as $field) {
                $cases[$method.' missing '.$field] = [$method, $field, null, true];
            }
            $cases[$method.' string verification'] = [$method, 'is_verified', 'true', false];
            $cases[$method.' invalid access'] = [$method, 'access_level', 'all', false];
            $cases[$method.' malformed resource'] = [$method, 'granted_resources', [['identifier' => ['value' => 'invalid']]], false];
            $cases[$method.' invalid method'] = [$method, 'authentication_method_current', ['type' => 'UNKNOWN'], false];
        }

        return $cases;
    }

    #[DataProvider('malformedResources')]
    public function test_malformed_resources_are_rejected(string $method, string $field, mixed $value, bool $remove): void
    {
        $resource = $this->resource();
        if ($remove) {
            unset($resource[$field]);
        } else {
            $resource[$field] = $value;
        }
        $this->expectException(ValidationException::class);
        (new ReflectionMethod(Approval::class, $method))->invoke(new Approval(), new EHealthResponse(new Response($method === 'validateApproval' ? 200 : 201, [], json_encode(['data' => $resource]))));
    }

    public static function pendingReceipts(): array
    {
        $cases = [];
        foreach (['validateCreate', 'validateVerification', 'validateCancellation'] as $method) {
            $cases[$method.' absent status'] = [$method, ['links' => [['href' => '/api/jobs/pending']]]];
            $cases[$method.' absent link'] = [$method, ['status' => 'pending']];
            $cases[$method.' unrelated link'] = [$method, ['status' => 'pending', 'links' => [['href' => '/api/patients/unrelated']]]];
        }

        return $cases;
    }

    #[DataProvider('pendingReceipts')]
    public function test_untrackable_accepted_requests_are_rejected(string $method, array $data): void
    {
        $this->expectException(ValidationException::class);
        (new ReflectionMethod(Approval::class, $method))->invoke(new Approval(), new EHealthResponse(new Response(202, [], json_encode(['data' => $data]))));
    }

    public function test_accepted_requests_remain_pending_for_all_approval_actions(): void
    {
        $receipt = ['status' => 'pending', 'links' => [['entity' => 'job', 'href' => '/api/jobs/pending']]];
        foreach (['validateCreate', 'validateVerification', 'validateCancellation'] as $method) {
            $this->assertSame($receipt, (new ReflectionMethod(Approval::class, $method))->invoke(new Approval(), new EHealthResponse(new Response(202, [], json_encode(['data' => $receipt])))));
        }
    }
}
