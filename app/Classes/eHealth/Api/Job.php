<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\EHealth\JobStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthJobTimeoutException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;

class Job extends Request
{
    protected const string URL = '/api/jobs';

    /**
     * Used to get the processing status of the async job.
     *
     * @param  string  $uuid
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/encounter-data-package/get-async-job-processing-details
     */
    public function getDetails(string $uuid, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/$uuid", $query);
    }

    /**
     * Fetch job details by raw href returned by ESOZ links.
     * Supports both "/jobs/{id}" and "/api/jobs/{id}" style links.
     *
     * @param  string  $href
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getDetailsByHref(string $href, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get($href, $query);
    }

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

        $attempts = 0;
        $status = null;
        $finalResponse = $responseData;

        do {
            sleep($intervalSeconds);

            try {
                $finalResponse = $this->getDetails($jobId)->getData();
            } catch (EHealthResponseException $exception) {
                // Some domains return links as "/jobs/{id}" instead of "/api/jobs/{id}".
                if ($exception->response->status() !== 404) {
                    throw $exception;
                }

                $finalResponse = $this->getDetailsByHref($jobHref)->getData();
            }

            $attempts++;
            $status = strtolower((string) ($finalResponse['status'] ?? ''));

            Log::info('eHealth job polled', [
                'job_id' => $jobId,
                'attempt' => $attempts,
                'status' => $status !== '' ? $status : '(empty)',
            ]);
        } while (JobStatus::tryFrom($status)?->isPending() === true && $attempts < $maxAttempts);

        if (JobStatus::tryFrom($status)?->isPending() === true) {
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

        if (JobStatus::tryFrom($status)?->isSuccessful() === true) {
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
