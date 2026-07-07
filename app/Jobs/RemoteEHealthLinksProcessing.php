<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use App\Core\Arr;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Models\LegalEntity;
use App\Models\EhealthLink;
use App\Models\Person\Person;
use InvalidArgumentException;
use App\Classes\eHealth\EHealth;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use App\Models\MedicalEvents\Sql\Approval;
use Illuminate\Queue\Middleware\RateLimited;
use App\Repositories\MedicalEvents\Repository;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Notifications\RemoteEHealthLinksNotification;
use App\Exceptions\EHealth\EHealthValidationException;

class RemoteEHealthLinksProcessing extends EHealthJob
{
    public const string BATCH_NAME = 'RemoteEHealthLinksProcessing';

    public const string SCOPE_REQUIRED = 'approval:create';

    protected const int TIME_TO_RETRY = 5; // Time in seconds to wait before retrying the job

    public function __construct(
        public EhealthLink $eHealthLink,
        public ?LegalEntity $legalEntity,
        protected ?EHealthJob $nextEntity = null,
        public bool $standalone = false,
    ) {
        parent::__construct(legalEntity: $legalEntity, nextEntity: $nextEntity, standalone: $standalone);
    }

    /**
     * Get data from EHealth API.
     *
     * @param  string  $token
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthResponseException|EHealthValidationException
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        $entity = $this->eHealthLink->entity;

        switch($entity) {
            case 'job':
                $jobUuid = basename($this->eHealthLink->href);

                return EHealth::job()->withToken($token)->getDetails($jobUuid);
            // TODO: fill the entities below with the correct API calls and data processing logic
            case 'encounter':
                return null;
            case 'condition':
                return null;
            case 'observation':
                return null;
            case 'allergy_intolerance':
                return null;
            case 'immunization':
                return null;
            case 'risk_assessment':
                return null;
            case 'device':
                return null;
            case 'medication_statement':
                return null;
            case 'medication_administration':
                return null;
            case 'diagnostic_report':
                return null;
            case 'procedure':
                return null;
            case 'specimen':
                return null;
            case 'device_dispense':
                return null;
            case 'device_association':
                return null;
            case 'detected_issue':
                return null;
            default:
                throw new InvalidArgumentException("Unsupported entity type: $entity");
        }
    }

    /**
     * Store or update data in the database.
     *
     * @param  EHealthResponse|null  $response
     * @return void
     * @throws Throwable
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        if (\is_null($response)) {
           return;
        }

        $responseData = $response->getData();
        // Status code can be in the response body or in the HTTP response itself, so we check both
        $statusCode = $responseData['status_code'] ?? $response->getStatusCode() ?? null;
        $status = $responseData['status'] ?? null;

        // Priority is given to the status code in the response body, if available, otherwise we use the HTTP status code
        // This is because the API might return a 200 OK HTTP status code even if the operation failed
        if ($statusCode === 200) {
            if ($this->eHealthLink->linkable_type === Approval::class) {
                $approval = $this->eHealthLink->linkable;
                $approvalData = $responseData['response_data'] ?? $responseData['data'];

                $this->eHealthLink->job()->update(['status' => strtoupper($status)]);
                $this->eHealthLink->update(['status' => strtoupper($status)]);
                $this->eHealthLink->processingData()->create(['response_data' => $approvalData]);
                $this->eHealthLink->refresh();

                Repository::approval()->sync(modelData: $approvalData, approvableModel: $approval->approvable, approvalModel: $approval);
            }

            return;
        } else if($status === 'pending' && (!$statusCode || $statusCode === 202)) {
            // Handle pending status (it means the request is still being processed)
            $this->release(self::TIME_TO_RETRY); // Release the job back to the queue to be retried after specified time

            return; // Exit the method to avoid further processing
        } else if (strtolower($status) === strtolower(JobStatus::FAILED->value)) {
            if ($this->eHealthLink->linkable_type === Approval::class) {
                $this->eHealthLink->linkable->update(['status' => JobStatus::FAILED->value]);
            }

            $this->eHealthLink->job()->update(['status' => strtoupper($status)]);

            $this->eHealthLink->update(
                [
                    'status' => strtoupper($status),
                    'error' => $responseData['error'] ?? null,
                    'error_code' => $statusCode ?? null
                ]
            );

            Log::error('EHealthLink: EHealth reject an link request', [
                'eHealthLinkId' => $this->eHealthLink->id,
                'statusCode' => $statusCode,
                'error' => $responseData['error'] ?? null
            ]);
        }

        // Send notification to the user about the failed request but job will not be retried, so we don't need to release it back to the queue
        $this->user->notify(new RemoteEHealthLinksNotification(__('EhealthLink: система eHealth відхилила запит'), (string)$statusCode));
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-remote-job-get')
        ];
    }

    /**
     * Get next entity job if needed.
     *
     * @return EHealthJob|null
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone || !$this->nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }
}
