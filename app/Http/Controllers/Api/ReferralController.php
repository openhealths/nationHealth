<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Classes\eHealth\Api\ServiceRequest;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ReferralController extends Controller
{
    /**
     * Search for ServiceRequest by requisition number.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'requisition' => 'required|string',
        ]);

        try {
            $params = ['requisition' => $request->input('requisition')];
            $results = ServiceRequest::searchForServiceRequestsByParams($params);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            Log::error('Search ServiceRequest failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Take a ServiceRequest into work (process).
     */
    public function process(string $uuid, Request $request, ReferralRequestLifecycleService $lifecycleService): JsonResponse
    {
        try {
            /** @var Employee $employee */
            $employee = Auth::user()?->activeDoctorEmployee();
            if (!$employee) {
                return response()->json(['success' => false, 'message' => 'Не знайдено активного лікаря'], 403);
            }

            $response = $lifecycleService->takeIntoWork($uuid, $employee, $request->input('payload', []));

            return response()->json([
                'success' => true,
                'data' => $response,
                'message' => 'Направлення взято в роботу',
            ]);
        } catch (\Exception $e) {
            Log::error('Process ServiceRequest failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Complete a ServiceRequest.
     */
    public function complete(string $uuid, Request $request, ReferralRequestLifecycleService $lifecycleService): JsonResponse
    {
        $request->validate([
            'encounter_uuid' => 'required|string',
        ]);

        try {
            $response = $lifecycleService->completeReferral(
                $uuid,
                $request->input('encounter_uuid'),
                $request->input('payload', [])
            );

            return response()->json([
                'success' => true,
                'data' => $response,
                'message' => 'Направлення погашено',
            ]);
        } catch (\Exception $e) {
            Log::error('Complete ServiceRequest failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Cancel usage of a ServiceRequest (відміна використання).
     */
    public function cancelUsage(string $uuid, Request $request, ReferralRequestLifecycleService $lifecycleService): JsonResponse
    {
        try {
            $response = $lifecycleService->cancelUsage(
                $uuid,
                $request->input('payload', [])
            );

            return response()->json([
                'success' => true,
                'data' => $response,
                'message' => 'Використання направлення відмінено',
            ]);
        } catch (\Exception $e) {
            Log::error('Cancel usage ServiceRequest failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
