<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Composition API — Medical Conclusions (МВН / МВТН).
 *
 * Paths follow the SwaggerHub contract `ehealthua/compositions` 2.39.2. Note that the
 * Confluence pages contradict it in two places and must not be used as the source of
 * truth: they document search as `/patients/{patientId}/composition`, and they list a
 * `patient_id` path parameter on create. Neither matches the operation definitions —
 * search resolves the patient through the `subject` / `focus` query parameters, and
 * create carries the patient inside the signed payload.
 *
 * Create, sign, cancel and the ERLN resend are asynchronous: they return an async job
 * whose completion must be polled via {@see getAsyncJobStatus()}.
 *
 * @see https://app.swaggerhub.com/apis/ehealthua/compositions/2.39.2
 */
class Composition extends PatientApiBase
{
    protected const string SEGMENT_COMPOSITION = 'composition';

    /**
     * Create a Composition — МВН or МВТН (API-006-009-0003).
     *
     * The whole conclusion is transported as a detached digital signature, so the
     * subject, focus, author and encounter all live inside the signed content rather
     * than in the URL.
     *
     * @param  array{data: string}  $payload  Base64-encoded PKCS#7 signed payload.
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(array $payload): PromiseInterface|EHealthResponse
    {
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
}
