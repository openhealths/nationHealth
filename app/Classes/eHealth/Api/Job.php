<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\MedicalEventsErrorTranslator;
use App\Contracts\ApiErrorTranslator;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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
        $this->setValidator($this->validateDetails(...));

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
        $path = parse_url($href, PHP_URL_PATH);
        if (!is_string($path) || preg_match('#^/?(?:api/)?jobs/([A-Za-z0-9_-]+)/?$#i', $path, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid eHealth job link.');
        }

        // Canonicalize the link and reuse getDetails(), which installs validateDetails() before sending the request.
        return $this->getDetails($matches[1], $query);
    }

    /**
     * Get the error eHealth rejected the job with. The job details come with a successful response, so the error is
     * not raised by the request itself and is translated here.
     *
     * @param  array  $job  Job details with the error, described by an error object or by a bare message
     * @return EHealthValidationException
     */
    public function jobError(array $job): EHealthValidationException
    {
        return new EHealthValidationException(
            ['error' => is_array($job['error']) ? $job['error'] : ['message' => $job['error']]],
            $this->errorTranslator()
        );
    }

    /**
     * Validate the async job processing details from eHealth API.
     *
     * @param  EHealthResponse  $response
     * @return array
     * @throws ValidationException
     */
    protected function validateDetails(EHealthResponse $response): array
    {
        $data = $response->getData();
        $urgent = $response->getUrgent();
        if ($urgent !== [] && !array_key_exists('urgent', $data)) {
            $data['urgent'] = $urgent;
        }
        $validator = Validator::make($data, array_replace(self::resultRules(), [
            'status' => ['required', 'string'],
        ]));

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Job details validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        $validator->validate();

        return $data;
    }

    /** Validate operation receipts in the API client before tracking or applying their result. */
    public static function validateSubmissionResponse(EHealthResponse $response): array
    {
        $data = $response->getData();
        Validator::make($data, self::resultRules())->validate();

        return $data;
    }

    protected static function resultRules(): array
    {
        return [
            'id' => ['nullable', 'string'],
            'job_id' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'status_code' => ['nullable', 'integer'],
            'eta' => ['nullable', 'string'],
            'error' => ['nullable'],
            'links' => ['nullable', 'array'],
            'links.*' => ['required', 'array'],
            'links.*.entity' => ['nullable', 'string'],
            'links.*.href' => ['required', 'string'],
            'response_data' => ['nullable', 'array'],
            'result' => ['nullable', 'array'],
            'data' => ['nullable', 'array'],
            'response' => ['nullable', 'array'],
            'response.body' => ['nullable', 'array'],
            'urgent' => ['nullable', 'array'],
        ];
    }

    /**
     * Get the translator of the errors the medical events resources return, as the jobs process their packages.
     *
     * @return ApiErrorTranslator
     */
    protected function errorTranslator(): ApiErrorTranslator
    {
        return new MedicalEventsErrorTranslator();
    }
}
