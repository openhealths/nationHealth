<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Core\EHealthJob;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
// use App\Models\EHealthJob as EHealthJobModel;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use App\Models\EhealthLink;
use Illuminate\Queue\Middleware\RateLimited;
use InvalidArgumentException;
use Throwable;
use App\Models\Approval;
use App\Models\Person\Person;

class RemoteEHealthLinksProcessing extends EHealthJob
{
    public const string BATCH_NAME = 'RemoteEHealthLinksProcessing';

    public const string SCOPE_REQUIRED = 'approval:create';

    // public const string ENTITY = LegalEntity::ENTITY_HEALTHCARE_SERVICE;

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
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        // dd($this->eHealthLink->toArray(), $token, $this->legalEntity->uuid, $this->nextEntity); // --- IGNORE ---

        $entity = $this->eHealthLink->entity;
        
        switch($entity) {
            case 'job':
                $jobUuid = basename($this->eHealthLink->href);

                return EHealth::job()->withToken($token)->getDetails($jobUuid);
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
        $responseData = $response->getData();
        $statusCode = $response->getStatusCode() ?? null;
        $status = $responseData['status'] ?? null;

        if ($statusCode === 200 || $statusCode === 303) {
            if ($this->eHealthLink->linkable_type === Approval::class) {
                $approval = $this->eHealthLink->linkable;
                $approvalData = $responseData['response_data'] ?? $responseData['data'];

                $this->eHealthLink->job()->update(['status' => strtoupper($status)]);
                $this->eHealthLink->update(['status' => strtoupper($status)]);
                $this->eHealthLink->processingData()->create(['response_data' => $approvalData]);
                $this->eHealthLink->refresh();
                dump($approvalData);
                if ($approval->approvable_type === Person::class) {
                    $person = $approval->approvable;
                    dump($person->id);
                    // $grantedToValue = $approvalData['granted_to']['identifier']['value'] ?? null;
                    // $grantedToCode = $approvalData['granted_to']['identifier']['type']['coding'][0]['code'] ?? 'legal_entity';

                    // // Map to SQL
                    // Approval::updateOrCreate(
                    //     [
                    //         'uuid' => $approvalData['id'],
                    //     ],
                    //     [
                    //         'granted_to_id' => $this->resolveGrantedTo($grantedToValue, $grantedToCode),
                    //         'granted_to_type' => $grantedToCode,
                    //         'status' => $approvalData['status'] ?? 'active',
                    //     ]
                    // );
                }
            }
        } else if($status === 'pending' && !$statusCode) {
            // Handle pending status (it means the request is still being processed)
            $this->release(5); // Release the job back to the queue to be retried after 5 seconds
            
            return; // Exit the method to avoid further processing
        } else {
            // Handle error responses or unexpected status codes
            dump('Error Response:', $statusCode, $status);
        }
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
