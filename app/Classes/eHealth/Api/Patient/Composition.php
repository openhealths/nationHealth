<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\Api\Patient\PatientApiBase;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\CompositionErrorTranslator;
use App\Contracts\ApiErrorTranslator;
use App\Enums\Composition\CompositionJobStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Exceptions\MedicalEvents\CompositionGuardException;
use DateTimeImmutable;
use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

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
    /** Parsed native schemas are cached only on this API client instance. */
    protected array $responseContracts = [];

    protected const string SEGMENT_COMPOSITION = 'composition';

    protected function errorTranslator(): ApiErrorTranslator
    {
        return new CompositionErrorTranslator();
    }

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

    /** Read every validated offset page before making a duplicate or linked-case decision. */
    public function searchAll(array $query): array
    {
        $items = [];
        $seen = [];
        $limit = 100;
        for ($offset = 0; ; $offset += $limit) {
            $page = $this->search(array_replace($query, compact('offset', 'limit')))->validate();
            foreach ($page as $item) {
                $uuid = data_get($item, 'identifier.value');
                if (isset($seen[$uuid])) {
                    throw ValidationException::withMessages(['data' => __('compositions.errors.invalid_response')]);
                }
                $seen[$uuid] = true;
                $items[] = $item;
            }
            if (count($page) < $limit) {
                return $items;
            }
        }
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
        $this->setValidator($this->validatePrintForm(...));

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
        $this->validateContract($data, 'detail');
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
        $this->validateContract($response->getData(), 'search');
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
        $this->validateContract($response->getData(), 'job');
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
            'id' => $data['id'] ?? ($data['uuid'] ?? null),
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
        $this->validateContract($response->getData(), 'integration');
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

    /** The native print document is plain text, preserved byte for byte after validation. */
    protected function validatePrintForm(EHealthResponse $response): array
    {
        $mediaType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        Validator::make(['mediaType' => $mediaType], [
            'mediaType' => ['required', Rule::in(['text/plain'])],
        ])->validate();
        $this->validateSchemaData($response->body(), ['type' => 'string']);

        return ['content' => $response->body()];
    }

    /** Validate the complete native document before applying the existing projection. */
    private function validateContract(mixed $data, string $operation): void
    {
        try {
            $contract = json_decode(file_get_contents(resource_path('ehealth/contracts/composition-responses.json')), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The Composition response contract is invalid.', previous: $exception);
        }
        $this->validateSchemaData($data, $contract['operations'][$operation], $contract['components']);
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
        $errors = $data['error'] ?? ($data['errors'] ?? data_get($data, 'response_data.error', []));
        if (is_array($errors) && !array_is_list($errors)) {
            $errors = [$errors];
        }
        $errors = array_merge(Arr::wrap($errors), collect($data['links'] ?? [])->pluck('error')->filter()->all());

        $translator = $this->errorTranslator();

        return collect($errors)->map(static function (mixed $error) use ($translator): string {
            if (is_array($error)) {
                $message = data_get($error, 'message') ?? (data_get($error, 'rules.0.description') ?? '');
                $code = data_get($error, 'code') ?? data_get($error, 'rules.0.code');
                if ($code !== null && !preg_match('/^\d{3,5}:/u', (string) $message)) {
                    $message = $code . ': ' . $message;
                }
            } else {
                $message = is_scalar($error) ? (string) $error : '';
            }

            return $translator->translate((string) $message) ?? EHealthException::translate((string) $message);
        })->filter()->unique()->values()->all();
    }

    /** Reject a valid document that belongs to a different patient, author or encounter. */
    public function assertDocumentContext(array $details, array $expected): void
    {
        foreach ($expected as $path => $value) {
            if (data_get($details, $path) !== $value) {
                throw ValidationException::withMessages([$path => __('compositions.errors.invalid_response')]);
            }
        }
    }

    /** Validate the complete configured EMAL mirror before a component uses any parameter. */
    public function emalConfiguration(): array
    {
        $settings = config('compositions.emal');
        try {
            if (is_string($settings)) {
                $settings = json_decode($settings, true, 512, JSON_THROW_ON_ERROR);
            }
            if (!is_array($settings) || array_is_list($settings)) {
                throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
            }
            $schema = json_decode(file_get_contents(resource_path('ehealth/contracts/composition-emal-config.schema.json')), true, flags: JSON_THROW_ON_ERROR);
            $this->validateSchemaData($settings, $schema);
        } catch (JsonException | ValidationException) {
            throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
        }

        return $settings;
    }

    public function validateNativeResponse(EHealthResponse $response, string $operation, string $group = 'composition'): array
    {
        $contracts = $this->responseContracts($group);
        $schema = $contracts['operations'][$operation]['data'] ?? throw new RuntimeException('Unknown eHealth response contract.');
        $envelope = $response->json();
        if (!is_array($envelope) || !array_key_exists('data', $envelope)) {
            throw ValidationException::withMessages(['data' => 'The eHealth response data is missing.']);
        }

        $errors = $this->schemaErrors($envelope['data'], $schema, 'data', $contracts['components']);
        foreach (['urgent'] as $field) {
            if (array_key_exists($field, $envelope) && isset($contracts['operations'][$operation][$field])) {
                $errors += $this->schemaErrors($envelope[$field], $contracts['operations'][$operation][$field], $field, $contracts['components']);
            }
        }
        if (isset($contracts['operations'][$operation]['paging']) && !array_key_exists('paging', $envelope)) {
            $errors['paging'] = 'The eHealth response paging is missing.';
        }
        // Paging is consumed by callers only after this validation, including an empty last page.
        if (array_key_exists('paging', $envelope)) {
            // Medical Events documents `page`; General MIS documents `page_number`.
            // Validate both documented spellings before the response normalizes paging.
            $paging = $envelope['paging'];
            if (is_array($paging) && isset($paging['page']) && !isset($paging['page_number'])) {
                $paging['page_number'] = $paging['page'];
            }
            if (is_array($paging) && isset($paging['page'], $paging['page_number']) && $paging['page'] !== $paging['page_number']) {
                $errors['paging.page'] = 'The eHealth response paging aliases disagree.';
            }
            $pagingSchema = $contracts['operations'][$operation]['paging'] ?? [];
            // Both official APIs use the same paging semantics with different names.
            // Adapt only that declared alias; retain any other provider constraints.
            $pagingSchema['type'] = 'object';
            $pagingSchema['required'] = ['page_number', 'page_size', 'total_entries', 'total_pages'];
            $pagingSchema['properties'] = array_replace($pagingSchema['properties'] ?? [], [
                'page' => ['type' => 'integer', 'minimum' => 1],
                'page_number' => ['type' => 'integer', 'minimum' => 1],
                'page_size' => ['type' => 'integer', 'minimum' => 1],
                'total_entries' => ['type' => 'integer', 'minimum' => 0],
                'total_pages' => ['type' => 'integer', 'minimum' => 0],
            ]);
            $errors += $this->schemaErrors($paging, $pagingSchema, 'paging', $contracts['components']);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Signing must receive the untouched documented native fields, never mapped FHIR.
        return $envelope['data'];
    }

    protected function validateSchemaData(mixed $value, array $schema, array $components = []): void
    {
        $errors = $this->schemaErrors($value, $schema, 'data', $components);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function responseContracts(string $group): array
    {
        if (!in_array($group, ['shared', 'medication', 'composition'], true)) {
            throw new RuntimeException('Unknown eHealth response contract group.');
        }
        if (!isset($this->responseContracts[$group])) {
            try {
                $this->responseContracts[$group] = json_decode(file_get_contents(resource_path('ehealth/contracts/'.$group.'-responses.json')), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('The eHealth response contract is invalid.', previous: $exception);
            }
        }

        return $this->responseContracts[$group];
    }

    private function schemaErrors(mixed $value, array $schema, string $path, array $components): array
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return [];
        }
        if (isset($schema['$ref'])) {
            $name = basename($schema['$ref']);

            return $this->schemaErrors($value, $components[$name] ?? throw new RuntimeException('Missing eHealth response schema.'), $path, $components);
        }
        $errors = [];
        if (isset($schema['not']) && $this->schemaErrors($value, $schema['not'], $path, $components) === []) {
            $errors[$path] = 'The eHealth response matches a forbidden field combination.';
        }
        foreach ($schema['allOf'] ?? [] as $part) {
            $errors += $this->schemaErrors($value, $part, $path, $components);
        }
        foreach (['oneOf', 'anyOf'] as $choice) {
            if (isset($schema[$choice])) {
                $matches = count(array_filter($schema[$choice], fn (array $part): bool => $this->schemaErrors($value, $part, $path, $components) === []));
                if ($matches === 0 || ($choice === 'oneOf' && $matches !== 1)) {
                    $errors[$path] = 'The eHealth response does not match the documented field choice.';
                }
            }
        }
        $type = $schema['type'] ?? (isset($schema['properties']) || isset($schema['required']) ? 'object' : null);
        $validType = match ($type) {
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
        if (!$validType) {
            return $errors + [$path => 'The eHealth response field has an invalid '.$type.' type.'];
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[$path] = 'The eHealth response field has an undocumented value.';
        }
        if (is_array($value) && $type === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $value)) {
                    $errors[$path.'.'.$key] = 'The required eHealth response field is missing.';
                }
            }
            foreach ($schema['properties'] ?? [] as $key => $part) {
                if (array_key_exists($key, $value)) {
                    $errors += $this->schemaErrors($value[$key], $part, $path.'.'.$key, $components);
                }
            }
            if (($schema['additionalProperties'] ?? true) === false && array_diff(array_keys($value), array_keys($schema['properties'] ?? [])) !== []) {
                $errors[$path] = 'The eHealth response contains undocumented fields.';
            }
            if (is_array($schema['additionalProperties'] ?? null)) {
                foreach (array_diff(array_keys($value), array_keys($schema['properties'] ?? [])) as $key) {
                    $errors += $this->schemaErrors($value[$key], $schema['additionalProperties'], $path.'.'.$key, $components);
                }
            }
        }
        if (is_array($value) && $type === 'array') {
            foreach ($value as $index => $item) {
                $errors += $this->schemaErrors($item, $schema['items'] ?? [], $path.'.'.$index, $components);
            }
            foreach (['minItems' => 'minimum', 'maxItems' => 'maximum'] as $limit => $label) {
                if (isset($schema[$limit]) && ($limit === 'minItems' ? count($value) < $schema[$limit] : count($value) > $schema[$limit])) {
                    $errors[$path] = 'The eHealth collection violates its documented '.$label.' size.';
                }
            }
        }
        if (is_string($value)) {
            foreach (['minLength', 'maxLength'] as $limit) {
                if (isset($schema[$limit]) && ($limit === 'minLength' ? mb_strlen($value) < $schema[$limit] : mb_strlen($value) > $schema[$limit])) {
                    $errors[$path] = 'The eHealth response string has an invalid length.';
                }
            }
            if (isset($schema['pattern']) && preg_match('~'.str_replace('~', '\\~', $schema['pattern']).'~u', $value) !== 1) {
                $errors[$path] = 'The eHealth response string has an invalid format.';
            }
            if (($schema['format'] ?? null) === 'uuid' && !Str::isUuid($value)) {
                $errors[$path] = 'The eHealth response identifier is invalid.';
            }
            if (($schema['format'] ?? null) === 'date-time') {
                $valid = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) === 1;
                if ($valid) {
                    try {
                        new DateTimeImmutable($value);
                        $diagnostics = DateTimeImmutable::getLastErrors();
                        $valid = $diagnostics === false || ($diagnostics['warning_count'] === 0 && $diagnostics['error_count'] === 0);
                    } catch (Exception) {
                        $valid = false;
                    }
                }
                if (!$valid) {
                    $errors[$path] = 'The eHealth response date-time is invalid.';
                }
            }
            $date = ($schema['format'] ?? null) === 'date' ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : null;
            if (($schema['format'] ?? null) === 'date' && ($date === false || $date->format('Y-m-d') !== $value)) {
                $errors[$path] = 'The eHealth response date is invalid.';
            }
        }
        if (is_int($value) || is_float($value)) {
            if ((isset($schema['minimum']) && $value < $schema['minimum']) || (isset($schema['maximum']) && $value > $schema['maximum'])) {
                $errors[$path] = 'The eHealth response number is outside the documented limits.';
            }
        }

        return $errors;
    }
}
