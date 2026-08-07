<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthValidationException;

class EHealthJobResolver
{
    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string, mixed>
     */
    public function resolve(array $responseData, int $maxAttempts = 15): array
    {
        $finalResponse = $responseData;

        if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
            $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
            $jobApi = EHealth::job();
            $attempts = 0;

            do {
                sleep(2);
                $finalResponse = $jobApi->getDetails($jobId)->getData();
                $attempts++;
                $status = strtolower((string) ($finalResponse['status'] ?? ''));
            } while (in_array($status, ['pending', 'processing'], true) && $attempts < $maxAttempts);
        }

        return $finalResponse;
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    /**
     * @param  array<string, mixed>  $finalResponse
     */
    public function assertSuccessful(array $finalResponse): void
    {
        $status = strtolower((string) ($finalResponse['status'] ?? ''));
        if (!in_array($status, ['error', 'failed'], true)) {
            return;
        }

        $error = $finalResponse['error'] ?? [];
        if (is_array($error) && (isset($error['invalid']) || isset($error['message']))) {
            throw new EHealthValidationException(['error' => $error]);
        }

        throw new EHealthValidationException([
            'error' => [
                'message' => is_string($error)
                    ? $error
                    : ($error['message'] ?? __('errors.ehealth.messages.request_error')),
            ],
        ]);
    }

    public function assertPrequalifyValid(array $responseData): void
    {
        $results = $responseData['data'] ?? $responseData;

        if (!is_array($results)) {
            return;
        }

        if (isset($results['status']) && !array_is_list($results)) {
            $results = [$results];
        }

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            if (strtoupper((string) ($result['status'] ?? '')) === 'INVALID') {
                throw new EHealthValidationException([
                    'error' => [
                        'message' => $result['rejection_reason']
                            ?? __('care-plan.referral_prequalify_failed'),
                    ],
                ]);
            }
        }
    }
}
