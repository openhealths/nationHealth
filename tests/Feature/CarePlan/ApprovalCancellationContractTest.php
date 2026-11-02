<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Approval;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApprovalCancellationContractTest extends TestCase
{
    public function test_cancellation_uses_the_patient_endpoint_and_preserves_the_caller_token(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        Http::fake(['*' => Http::response(['data' => ['id' => $id, 'is_verified' => true]], 201)]);
        $result = $this->api()->withToken('test-caller')->cancelApproval('patient', $id)->validate();

        $this->assertSame($id, $result['id']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_ends_with($request->url(), "/api/patients/patient/approvals/{$id}/actions/cancel")
            && $request->data() === []
            && $request->hasHeader('Authorization', 'Bearer test-caller'));
    }

    public function test_cancellation_accepts_an_async_receipt_without_an_approval_resource(): void
    {
        $data = ['status' => 'pending', 'links' => [['entity' => 'job', 'href' => '/Jobs/remote-job']]];
        Http::fake(['*' => Http::response(['data' => $data], 202)]);

        $this->assertSame($data, $this->api()->cancelApproval('patient', 'approval')->validate());
    }

    #[DataProvider('invalidResponses')]
    public function test_cancellation_rejects_verification_acknowledgements_and_malformed_resources(int $status, array $body): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        $this->expectException(ValidationException::class);

        $this->api()->cancelApproval('patient', 'approval')->validate();
    }

    public static function invalidResponses(): array
    {
        return [[200, ['data' => []]], [204, []], [201, ['data' => ['id' => 'not-a-uuid', 'is_verified' => true]]]];
    }

    private function api(): Approval
    {
        $factory = Http::getFacadeRoot();
        $api = new Approval($factory);
        $api->stub((function () {
            return $this->stubCallbacks;
        })->call($factory));

        return $api;
    }
}
