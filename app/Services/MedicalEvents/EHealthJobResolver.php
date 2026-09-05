<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthJobTimeoutException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use Illuminate\Support\Facades\Log;

/**
 * Resolves asynchronous eHealth jobs.
 *
 * eHealth answers write requests with a link to a job that is polled until it
 * reaches a final state. Every caller pairs this with a local database write, so
 * an unresolved or failed job must raise rather than return — otherwise the local
 * record is marked as accepted while eHealth rejected it.
 */
class EHealthJobResolver
{
    private const array PENDING_STATUSES = ['pending', 'processing', 'accepted', 'queued'];

    private const array SUCCESS_STATUSES = ['processed', 'completed', 'success', 'active'];

    /**
     * Poll a job to completion.
     *
     * Responses without a job link are returned untouched — not every eHealth
     * endpoint is asynchronous.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string, mixed>
     * @throws EHealthJobTimeoutException when the job is still pending after the last attempt
     * @throws EHealthValidationException when the job finished in a failed state
     */
    public function resolve(array $responseData, ?int $maxAttempts = null, ?int $intervalSeconds = null): array
    {
        $jobHref = $responseData['links'][0]['href'] ?? null;

        if ((!is_string($jobHref) || !str_contains($jobHref, '/jobs/')) && isset($responseData['job_id']) && is_string($responseData['job_id']) && $responseData['job_id'] !== '') {
            $jobHref = '/api/jobs/'.$responseData['job_id'];
            $responseData['links'][0]['href'] = $jobHref;
        }

        if (!is_string($jobHref) || !str_contains($jobHref, '/jobs/')) {
            return $responseData;
        }

        $maxAttempts ??= (int) config('ehealth.jobs.max_attempts', 15);
        $intervalSeconds ??= (int) config('ehealth.jobs.interval_seconds', 2);

        $jobId = basename($jobHref);
        $jobApi = EHealth::job();
        $attempts = 0;
        $status = null;
        $finalResponse = $responseData;

        do {
            sleep($intervalSeconds);

            try {
                $finalResponse = $jobApi->getDetails($jobId)->getData();
            } catch (EHealthResponseException $exception) {
                // Some domains return links as "/jobs/{id}" instead of "/api/jobs/{id}".
                if ($exception->response->status() !== 404) {
                    throw $exception;
                }

                $finalResponse = $jobApi->getDetailsByHref($jobHref)->getData();
            }

            $attempts++;
            $status = strtolower((string) ($finalResponse['status'] ?? ''));

            Log::info('EHealthJobResolver: polled job', [
                'job_id' => $jobId,
                'attempt' => $attempts,
                'status' => $status !== '' ? $status : '(empty)',
            ]);
        } while (in_array($status, self::PENDING_STATUSES, true) && $attempts < $maxAttempts);

        if (in_array($status, self::PENDING_STATUSES, true)) {
            throw new EHealthJobTimeoutException($jobId, $attempts, $status);
        }

        $this->assertSuccessful($finalResponse);

        return $finalResponse;
    }

    /**
     * @param  array<string, mixed>  $finalResponse
     * @throws EHealthValidationException
     */
    public function assertSuccessful(array $finalResponse): void
    {
        $status = strtolower((string) ($finalResponse['status'] ?? ''));

        if (in_array($status, self::SUCCESS_STATUSES, true)) {
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

    /**
     * @param  array<string, mixed>  $responseData
     * @throws EHealthValidationException
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
