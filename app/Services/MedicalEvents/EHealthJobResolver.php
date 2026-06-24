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
            } while (($finalResponse['status'] ?? null) === 'pending' && $attempts < $maxAttempts);
        }

        return $finalResponse;
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
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
