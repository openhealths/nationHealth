<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Approval;
use App\Exceptions\EHealth\EHealthResponseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Approval::resendSms() must call the only endpoint documented by eHealth for this
 * action - the patient-scoped one. There is no undocumented patient-prefix-less
 * variant to fall back to (it 404s), so any error from the documented endpoint must
 * be surfaced as-is.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583403110/Resend+SMS+on+Approval
 */
class ApprovalResendSmsTest extends TestCase
{
    public function test_resend_sms_calls_the_documented_patient_scoped_endpoint(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'approval-1']], 200)]);

        $this->makeApi()->resendSms('patient-1', 'approval-1');

        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/api/patients/patient-1/approvals/approval-1/actions/resend');
        });

        Http::assertNotSent(static function (Request $request): bool {
            return str_contains($request->url(), '/api/approvals/approval-1');
        });
    }

    public function test_resend_sms_surfaces_errors_from_the_documented_endpoint(): void
    {
        Http::fake([
            '*/api/patients/patient-1/approvals/approval-1/actions/resend' => Http::response(
                ['error' => ['message' => 'ACL: No matching rule was found for path /api/patients']],
                403
            ),
        ]);

        try {
            $this->makeApi()->resendSms('patient-1', 'approval-1');
            $this->fail('Expected EHealthResponseException was not thrown.');
        } catch (EHealthResponseException $exception) {
            $this->assertSame(403, $exception->getCode());
        }
    }

    /**
     * Create an Approval instance with Http::fake() stubs properly transferred.
     *
     * Http::fake() stores stubs on the Factory instance. When a PendingRequest subclass is
     * instantiated directly (not through Factory::request()), the stubs are not copied.
     * This helper transfers them via PendingRequest::stub().
     */
    private function makeApi(): Approval
    {
        $factory = Http::getFacadeRoot();
        $api = new Approval($factory);

        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs);

        return $api;
    }
}
