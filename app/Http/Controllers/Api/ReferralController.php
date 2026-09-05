<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Classes\eHealth\Api\ServiceRequest;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Http\Controllers\Controller;
use App\Models\Employee\Employee;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class ReferralController extends Controller
{
    /**
     * Electronic medical record types a referral may be completed against.
     */
    private const array COMPLETION_RESOURCE_TYPES = ['encounter', 'procedure', 'diagnostic_report'];

    /**
     * Search for a ServiceRequest by requisition number.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'requisition' => ['required', 'string', 'max:255'],
        ]);

        return $this->respond(
            'search',
            fn (): array => \App\Classes\eHealth\EHealth::serviceRequest()->searchForServiceRequestsByParams(
                ['requisition' => $validated['requisition']]
            )->getData(),
            ['requisition' => $validated['requisition']]
        );
    }

    /**
     * Take a ServiceRequest into work (process).
     */
    public function process(Request $request, ReferralRequestLifecycleService $lifecycleService): JsonResponse
    {
        $uuid = $this->referralUuid($request);

        $validated = $request->validate([
            'patient_uuid' => ['required_without:patient_id', 'string', 'max:255'],
            'patient_id' => ['required_without:patient_uuid', 'string', 'max:255'],
            'payload' => ['sometimes', 'array'],
        ]);

        $employee = $this->currentEmployee();

        if (!$employee instanceof Employee) {
            return response()->json([
                'success' => false,
                'message' => __('care-plan.referral_api_no_active_employee'),
            ], 403);
        }

        $patientUuid = $validated['patient_uuid'] ?? $validated['patient_id'];

        return $this->respond(
            'process',
            fn (): array => $lifecycleService->takeIntoWork(
                $uuid,
                $employee,
                $patientUuid,
                $validated['payload'] ?? []
            ),
            ['referral_uuid' => $uuid, 'employee_id' => $employee->id],
            __('care-plan.referral_api_processed')
        );
    }

    /**
     * Complete (redeem) a ServiceRequest against an electronic medical record.
     */
    public function complete(Request $request, ReferralRequestLifecycleService $lifecycleService): JsonResponse
    {
        $uuid = $this->referralUuid($request);

        $validated = $request->validate([
            'resource_uuid' => ['required_without:encounter_uuid', 'string', 'max:255'],
            'encounter_uuid' => ['required_without:resource_uuid', 'string', 'max:255'],
            'resource_type' => ['sometimes', Rule::in(self::COMPLETION_RESOURCE_TYPES)],
            'payload' => ['sometimes', 'array'],
        ]);

        $resourceUuid = $validated['resource_uuid'] ?? $validated['encounter_uuid'];
        $resourceType = $validated['resource_type'] ?? 'encounter';

        return $this->respond(
            'complete',
            fn (): array => $lifecycleService->completeReferral(
                $uuid,
                $resourceUuid,
                $resourceType,
                $validated['payload'] ?? []
            ),
            ['referral_uuid' => $uuid, 'resource_type' => $resourceType],
            __('care-plan.referral_api_completed')
        );
    }

    /**
     * Cancel usage of a ServiceRequest.
     */
    public function cancelUsage(Request $request, ReferralRequestLifecycleService $lifecycleService): JsonResponse
    {
        $uuid = $this->referralUuid($request);

        $validated = $request->validate([
            'patient_id' => ['required_without:patient_uuid', 'string', 'max:255'],
            'patient_uuid' => ['required_without:patient_id', 'string', 'max:255'],
            'explanatory_letter' => ['sometimes', 'string', 'max:2000'],
            'payload' => ['sometimes', 'array'],
        ]);

        $payload = $validated['payload'] ?? [];

        if (isset($validated['explanatory_letter']) && empty($payload['explanatory_letter'])) {
            $payload['explanatory_letter'] = $validated['explanatory_letter'];
        }

        $patientId = $validated['patient_id'] ?? $validated['patient_uuid'];

        return $this->respond(
            'cancel-usage',
            fn (): array => $lifecycleService->cancelUsage($uuid, $patientId, $payload),
            ['referral_uuid' => $uuid],
            __('care-plan.referral_api_usage_cancelled')
        );
    }

    /**
     * Read the referral UUID from the route.
     *
     * The route also carries {legalEntity}, so positional controller arguments would
     * bind the wrong value.
     */
    private function referralUuid(Request $request): string
    {
        return (string) $request->route('uuid');
    }

    /**
     * Resolve the acting employee within the legal entity the request is scoped to.
     */
    private function currentEmployee(): ?Employee
    {
        $legalEntity = legalEntity();

        if ($legalEntity === null) {
            return null;
        }

        return Auth::user()
            ?->employees()
            ->where('legal_entity_id', $legalEntity->id)
            ->first();
    }

    /**
     * Run an eHealth-backed action and translate its outcome into a JSON response.
     *
     * eHealth validation details are safe to surface; anything else is logged and
     * replaced with a generic message so internals never reach the client.
     *
     * @param  callable(): array<mixed>  $action
     * @param  array<string, mixed>  $logContext
     */
    private function respond(
        string $action,
        callable $callback,
        array $logContext = [],
        ?string $message = null
    ): JsonResponse {
        try {
            $data = $callback();
        } catch (EHealthValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getTranslatedMessage(),
                'errors' => $exception->getDetails(),
            ], 422);
        } catch (EHealthResponseException | EHealthConnectionException $exception) {
            Log::channel('e_health_errors')->error(
                "Referral API [{$action}] failed",
                $logContext + ['exception' => $exception->getMessage()]
            );

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 502);
        } catch (Throwable $exception) {
            Log::channel('e_health_errors')->error(
                "Referral API [{$action}] failed unexpectedly",
                $logContext + ['exception' => $exception->getMessage()]
            );

            return response()->json([
                'success' => false,
                'message' => __('care-plan.unexpected_error'),
            ], 500);
        }

        return response()->json(array_filter([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], static fn ($value): bool => $value !== null));
    }
}
