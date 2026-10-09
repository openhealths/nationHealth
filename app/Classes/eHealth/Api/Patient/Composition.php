<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Enums\Composition\CompositionJobStatus;
use App\Exceptions\EHealth\EHealthException;
use Illuminate\Support\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Composition API — Medical Conclusions (МВН / МВТН).
 *
 * Paths follow the SwaggerHub contract `ehealthua/compositions` 2.39.2. Note that the
 * Confluence pages contradict it in two places and must not be used as the source of
 * truth: they document search as `/patients/{patientId}/composition`, and they list a
 * `patient_id` path parameter on create. Neither matches the operation definitions —
 * search resolves the patient through the `subject` / `focus` query parameters, and
 * create carries the patient inside the unsigned payload.
 *
 * Create, sign, cancel and the ERLN resend are asynchronous: they return an async job
 * whose completion must be polled via {@see getAsyncJobStatus()}.
 *
 * @see https://app.swaggerhub.com/apis/ehealthua/compositions/2.39.2
 */
class Composition extends PatientApiBase
{
    protected const string SEGMENT_COMPOSITION = 'composition';

    protected function sanitizeOptionsForLog(array $options): array
    {
        if (isset($options['json']['type'])) {
            $options['json'] = '[composition_payload_redacted]';
        } elseif (isset($options['json']['data']) && is_string($options['json']['data'])) {
            $options['json']['data'] = '[base64_signed_content_redacted]';
        }

        return parent::sanitizeOptionsForLog($options);
    }

    /**
     * Create a Composition — МВН or МВТН (API-006-009-0003).
     *
     * The HTTP body is the unsigned compositionRequest (type, category, subject, …).
     * Patient, author and encounter identifiers travel inside that JSON, not in the URL.
     * Detached PKCS#7 under `{data}` is used only by {@see sign()} / {@see cancel()}.
     *
     * @param  array<string, mixed>  $payload  compositionRequest body from the Livewire form.
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateScheduledJob(...));

        return $this->post(self::URL . '/' . self::SEGMENT_COMPOSITION, $payload);
    }

    /**
     * Poll the async job created by create, sign, cancel or ERLN resend (API-006-009-0001).
     *
     * Job status is one of PENDING, DONE or FAILED.
     *
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getAsyncJobStatus(string $asyncJobId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateJob(...));

        return $this->get(self::URL . '/' . self::SEGMENT_COMPOSITION . "/job/$asyncJobId");
    }

    /**
     * Get one Composition with full details (API-006-009-0006).
     *
     * Reading a conclusion requires the whole medical-record context, not just its own
     * id: eHealth authorises the request against the episode and encounter it was
     * built on.
     *
     * @param  string  $patientId  Person UUID, or Preperson UUID for a newborn conclusion.
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getById(
        string $patientId,
        string $compositionId,
        string $episodeId,
        string $encounterId
    ): PromiseInterface|EHealthResponse {
        $this->setValidator($this->validateOne(...));

        return $this->get($this->contextUrl($patientId, $compositionId, $episodeId, $encounterId));
    }

    /**
     * Search Compositions (API-006-009-0007).
     *
     * `subject` and `focus` are mutually exclusive: `subject` finds conclusions issued
     * for a patient, `focus` finds those issued about an incapacitated person.
     *
     * @param  array{
     *     subject?: string,
     *     focus?: string,
     *     type?: string,
     *     episodeOfCare?: string,
     *     encounter?: string,
     *     status?: string,
     *     offset?: int,
     *     limit?: int
     * }  $query
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function search(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));

        return $this->get(self::URL . '/searchComposition', $query);
    }

    /**
     * Sign a Composition with the author's qualified electronic signature (API-006-009-0004).
     *
     * @param  array{data: string}  $payload  Base64-encoded PKCS#7 signed payload.
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function sign(string $compositionId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateScheduledJob(...));

        return $this->patch(self::URL . '/' . self::SEGMENT_COMPOSITION . "/$compositionId/sign", $payload);
    }

    /**
     * Mark a Composition as entered in error (API-006-009-0005).
     *
     * The signed payload must carry the cancellation reason; eHealth rejects the
     * request when the caller is not the author.
     *
     * @param  array{data: string}  $payload  Base64-encoded PKCS#7 signed payload.
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function cancel(string $compositionId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateScheduledJob(...));

        return $this->patch(self::URL . '/' . self::SEGMENT_COMPOSITION . "/$compositionId/cancel", $payload);
    }

    /**
     * Get the print form rendered by eHealth (API-006-009-0008).
     *
     * The returned document must be shown to the user as-is. Adding logos, adverts or
     * any other content to it is prohibited by TV 3.8.1.1.5.1 and 3.8.2.8.3.1.
     *
     * @param  string|null  $templateId  Value from the COMPOSITION_TEMPLATE_ID dictionary.
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getPrintForm(
        string $patientId,
        string $compositionId,
        string $episodeId,
        string $encounterId,
        ?string $templateId = null
    ): PromiseInterface|EHealthResponse {
        return $this->get(
            $this->contextUrl($patientId, $compositionId, $episodeId, $encounterId) . '/printForm',
            $templateId === null ? [] : ['templateId' => $templateId]
        );
    }

    /**
     * Get integration data for a Composition (API-006-009-0009).
     *
     * Carries the ERLN outcome for a МВТН and the DRACS outcome for a МВН, including
     * the failure message that the user needs before retrying.
     *
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getIntegrationData(
        string $patientId,
        string $compositionId,
        string $episodeId,
        string $encounterId
    ): PromiseInterface|EHealthResponse {
        $this->setValidator($this->validateIntegration(...));

        return $this->get(
            $this->contextUrl($patientId, $compositionId, $episodeId, $encounterId) . '/integrationData'
        );
    }

    /**
     * Retry registering a МВТН in the ERLN registry (API-006-009-0002).
     *
     * Permitted only while the conclusion is FINAL and its CREATE_ERLN_RECORD task
     * failed, per TV 3.8.2.14.1.
     *
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function resendErln(string $compositionId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateScheduledJob(...));

        return $this->patch(self::URL . '/' . self::SEGMENT_COMPOSITION . "/$compositionId/erln");
    }

    /**
     * Build the medical-record context path shared by the read-side endpoints.
     */
    private function contextUrl(
        string $patientId,
        string $compositionId,
        string $episodeId,
        string $encounterId
    ): string {
        return self::URL . "/$patientId/" . self::SEGMENT_COMPOSITION
            . "/$compositionId/episode/$episodeId/encounter/$encounterId";
    }

    /**
     * The detail endpoint returns the document directly; some gateways wrap it in data.
     */
    protected function validateOne(EHealthResponse $response): array
    {
        $body = $response->json();
        $data = is_array($body) && array_key_exists('data', $body) ? $body['data'] : $body;
        $validator = Validator::make(is_array($data) ? $data : [], $this->compositionRules());

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Composition validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        $validator->validate();

        return $data;
    }

    protected function validateMany(EHealthResponse $response): array
    {
        $items = Validator::make(['items' => $response->getData()], [
            'items' => ['present', 'array', 'list'],
            'items.*' => ['array'],
        ])->validate()['items'];

        $rules = collect($this->searchRules())
            ->mapWithKeys(static fn ($rule, $key) => ["*.$key" => $rule])
            ->all();

        $validator = Validator::make($items, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Composition search validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        $validator->validate();

        return $items;
    }

    protected function validateJob(EHealthResponse $response): array
    {
        $data = Validator::make($response->getData(), [
            'id' => ['nullable', 'string'],
            'uuid' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(CompositionJobStatus::class)],
            'eta' => ['nullable', 'string'],
            'links' => ['nullable', 'array'],
            'error' => ['nullable'],
            'errors' => ['nullable'],
            'response_data' => ['nullable', 'array'],
        ])->validate();

        return [
            'id' => $data['id'] ?? $data['uuid'] ?? null,
            'status' => $data['status'],
            'eta' => $data['eta'] ?? null,
            'compositionUuid' => $this->compositionUuidFromLinks($data['links'] ?? []),
            'errors' => $this->errorsFrom($data),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compositionRules(): array
    {
        return array_replace($this->searchRules(), [
            'type.coding.0.code' => ['required', 'string'],
            'type.coding.0.system' => ['required', 'string'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function searchRules(): array
    {
        $rules = [
            'identifier' => ['required', 'array'],
            'identifier.value' => ['required', 'uuid'],
            'status' => ['required', 'string'],
            'title' => ['nullable', 'string'],
            'date' => ['nullable', 'date'],
            'event' => ['nullable', 'array'],
            'event.*.period.start' => ['nullable', 'date'],
            'event.*.period.end' => ['nullable', 'date'],
            'extension' => ['nullable', 'array'],
            'extension.*.valueCode' => ['required', 'string'],
            'extension.*.valueBoolean' => ['nullable', 'boolean'],
            'extension.*.valueString' => ['nullable', 'string'],
            'extension.*.valueUuid' => ['nullable', 'uuid'],
            'extension.*.valueDate' => ['nullable', 'date'],
            'section' => ['nullable', 'array'],
            'relatesTo' => ['nullable', 'array'],
            'relatesTo.code' => ['nullable', 'string'],
        ];

        foreach (['type', 'category'] as $concept) {
            $rules[$concept] = ['nullable', 'array'];
            $rules[$concept . '.coding'] = ['nullable', 'array'];
            $rules[$concept . '.coding.*.code'] = ['required', 'string'];
            $rules[$concept . '.coding.*.system'] = ['nullable', 'string'];
        }
        foreach (['subject', 'encounter', 'author', 'custodian', 'episodeOfCare', 'section.focus', 'relatesTo.targetIdentifier'] as $reference) {
            $rules[$reference] = ['nullable', 'array'];
            $rules[$reference . '.value'] = ['nullable', 'uuid'];
        }

        return $rules;
    }

    protected function validateScheduledJob(EHealthResponse $response): array
    {
        $data = $this->validateJob($response);
        Validator::make($data, ['id' => ['required', 'string']])->validate();

        return $data;
    }

    protected function validateIntegration(EHealthResponse $response): array
    {
        Validator::make(['items' => $response->getData()], [
            'items' => ['present', 'array'],
            'items.*' => ['array'],
            'items.*.component' => ['required', 'string'],
            'items.*.type' => ['required', 'string'],
            'items.*.integrationStatus' => ['nullable', 'string'],
            'items.*.statusMessage' => ['nullable', 'string'],
            'items.*.details' => ['nullable', 'array'],
        ])->validate();

        return $response->getData();
    }

    private function compositionUuidFromLinks(mixed $links): ?string
    {
        foreach (Arr::wrap($links) as $link) {
            foreach (Arr::wrap($link) as $value) {
                if (!is_string($value)) {
                    continue;
                }

                if (preg_match('~(?:/|^)composition/([0-9a-f-]{36})~i', $value, $matches) === 1) {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    private function errorsFrom(array $data): array
    {
        $errors = $data['error'] ?? $data['errors'] ?? data_get($data, 'response_data.error', []);
        if (is_array($errors) && !array_is_list($errors)) {
            $errors = [$errors];
        }
        $errors = array_merge(Arr::wrap($errors), collect($data['links'] ?? [])->pluck('error')->filter()->all());

        return collect($errors)->map(static function (mixed $error): string {
            if (is_array($error)) {
                $message = data_get($error, 'message') ?? data_get($error, 'rules.0.description') ?? '';
                $code = data_get($error, 'code') ?? data_get($error, 'rules.0.code');
                if ($code !== null && !preg_match('/^\d{3,5}:/u', (string) $message)) {
                    $message = $code . ': ' . $message;
                }
            } else {
                $message = is_scalar($error) ? (string) $error : '';
            }

            return EHealthException::translate((string) $message);
        })->filter()->unique()->values()->all();
    }
}
