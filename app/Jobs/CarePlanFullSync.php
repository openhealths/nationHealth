<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Core\PatientEHealthJob;
use App\Enums\CarePlanStatus;
use App\Models\LegalEntity;
use App\Classes\eHealth\EHealth;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use App\Exceptions\EHealth\EHealthResponseException;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;
use App\Core\Arr;
use App\Repositories\Repository;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use Illuminate\Validation\ValidationException;
use App\Traits\SynchronizesCarePlans;
use App\Enums\JobStatus;

class CarePlanFullSync extends PatientEHealthJob
{
    use SynchronizesCarePlans;

    public const string BATCH_NAME = 'CarePlanFullSync';

    public const string SCOPE_REQUIRED = 'care_plan:read';

    public const string ENTITY = LegalEntity::ENTITY_CARE_PLAN;

    public $queue = 'care-plans';

    public ?string $resourceUuid = null;

    // Keep patient identity in the job payload when a failed batch is recreated after login.
    public array $patientContext = [];

    public int $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHour();
    }

    public function __construct(
        ?LegalEntity $legalEntity = null,
        ?EHealthJob $nextEntity = null,
        bool $isFirstLogin = false,
        int $page = 1,
        bool $standalone = false,
        ?string $resourceUuid = null,
        array $patientContext = [],
    ) {
        parent::__construct($legalEntity, $nextEntity, $isFirstLogin, $page, $standalone);
        $this->resourceUuid = $resourceUuid;
        $this->patientContext = $patientContext;
    }

    public static function detailJobs(array $summaries, LegalEntity $legalEntity, array $patientContext = []): array
    {
        $jobs = [];
        foreach (collect($summaries)->sortBy(static fn (array $summary): int => match (CarePlanStatus::fromStored($summary['status'] ?? null)) {
            CarePlanStatus::ACTIVE => 0,
            CarePlanStatus::PENDING => 1,
            CarePlanStatus::ON_HOLD => 2,
            default => 3,
        }) as $summary) {
            $jobs[] = new self($legalEntity, standalone: true, resourceUuid: Arr::toCamelCase($summary)['id'], patientContext: $patientContext);
        }

        return $jobs;
    }

    public function handle(): void
    {
        if (!$this->isPatientRegistrySync()) {
            parent::handle();

            return;
        }
        if ($this->batch()?->cancelled()) {
            return;
        }

        $options = ($this->batch()?->options ?? []) + $this->patientContext;
        $this->patientContext = array_intersect_key($options, array_flip([
            'patient_uuid', 'person_id', 'patient_care_plan_registry', 'care_plan_approvals',
        ]));
        $this->patientContext['patient_care_plan_registry'] = true;
        $this->patientUuid = $options['patient_uuid'] ?? null;
        $this->personId = $options['person_id'] ?? null;
        $this->token = Crypt::decryptString($options['token'] ?? '');
        $response = $this->sendRequest($this->token);
        $this->processResponse($response);

        if ($this->resourceUuid === null && $response?->isNotLast()) {
            $this->batch()->add(new self(
                $this->legalEntity,
                page: $this->page + 1,
                standalone: true,
                patientContext: $this->patientContext,
            )->delay(now()->addSeconds(self::RATE_LIMIT_DELAY)));
        }
    }

    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        if ($this->patientUuid === null || $this->patientUuid === '') {
            throw ValidationException::withMessages([
                'patient_uuid' => 'Care Plan synchronization requires patient context. Discard the incomplete batch and start synchronization from the patient record.',
            ]);
        }

        $api = EHealth::carePlan()->withToken($token);
        if ($this->isPatientRegistrySync() && $this->resourceUuid !== null) {
            try {
                return $api->getDetails($this->patientUuid, $this->resourceUuid);
            } catch (EHealthResponseException $exception) {
                if ($exception->getCode() !== 403) {
                    throw $exception;
                }
                $key = 'care-plan-sync-denied:'.$this->batch()->id;
                Cache::add($key, 0, now()->addDay());
                Cache::increment($key);
                Log::warning('Care Plan detail access denied during synchronization', ['batchId' => $this->batch()->id, 'resourceUuid' => $this->resourceUuid]);

                return null;
            }
        }
        $query = ['page' => $this->page];
        if (!$this->isPatientRegistrySync()) {
            $query['managing_organization_id'] = $this->legalEntity->uuid;
        }

        return $api->getBySearchParams($this->patientUuid, $query);
    }

    /**
     * @throws Throwable
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        if ($response === null) {
            return;
        }
        if ($this->isPatientRegistrySync() && $this->resourceUuid === null) {
            // Queue one full aggregate per job; a long patient history never runs in one HTTP request.
            $summaries = $response->validate();
            Repository::carePlan()->storeSummaries($summaries, $this->personId, $this->legalEntity->id);
            $jobs = self::detailJobs($summaries, $this->legalEntity, $this->patientContext);
            if ($jobs !== []) {
                $this->batch()->add($jobs);
            }

            return;
        }
        $validatedData = $response?->validate();
        if (empty($validatedData)) {
            return;
        }
        if ($this->isPatientRegistrySync() && $this->resourceUuid !== null) {
            if (($validatedData['id'] ?? null) !== $this->resourceUuid) {
                throw ValidationException::withMessages(['id' => 'Care Plan details do not match the requested resource.']);
            }
            $patient = Repository::carePlan()->resolvePatientContext($this->personId, $this->patientUuid);
            try {
                $activities = $this->fetchAllActivityResources($patient['uuid'], $this->resourceUuid, token: $this->token);
                $plan = Repository::carePlan()->storeAggregate($validatedData, $activities, $patient['id'], $this->legalEntity->id);
                if ((($this->batch()?->options ?? []) + $this->patientContext)['care_plan_approvals'] ?? false) {
                    MedicalEventsRepository::approval()->syncApprovals(
                        $plan,
                        'care_plan',
                        [],
                        $this->token
                    );
                }
            } catch (EHealthResponseException $exception) {
                if ($exception->getCode() !== 403) {
                    throw $exception;
                }
                Repository::carePlan()->storeResource($validatedData, $patient['id'], $this->legalEntity->id);
                $key = 'care-plan-sync-denied:'.$this->batch()->id;
                Cache::add($key, 0, now()->addDay());
                Cache::increment($key);
                Log::warning('Care Plan related record access denied during synchronization', ['batchId' => $this->batch()->id, 'resourceUuid' => $this->resourceUuid]);
            }

            return;
        }
        $this->synchronizeResources($validatedData, $this->personId, $this->legalEntity->id, $this->patientUuid, $this->token);
    }

    private function isPatientRegistrySync(): bool
    {
        $batch = $this->batch();
        $options = ($batch?->options ?? []) + $this->patientContext;
        if (array_key_exists('patient_care_plan_registry', $options)) {
            return $options['patient_care_plan_registry'] === true;
        }

        // Registry batches queued before the explicit option retain their original behavior.
        return $this->standalone && isset($options['patient_uuid'], $options['person_id'])
            && $batch?->name === self::BATCH_NAME.'_'.$options['patient_uuid'];
    }

    protected function setEntityStatus(JobStatus $status): void
    {
        if (!$this->isPatientRegistrySync()) {
            parent::setEntityStatus($status);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (!$this->isPatientRegistrySync()) {
            parent::failed($exception);
        }
        // The patient's registry batch reports its outcome independently of the clinic synchronization.
    }

    protected function getNextEntityJob(): ?EHealthJob
    {
        if ($this->isPatientRegistrySync()) {
            return null;
        }

        return $this->standalone || !$this->nextEntity
            ? new CompleteSync(legalEntity: $this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }

    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-encounter-get') // Or a specific rate limiter if we have one for care plans
        ];
    }
}
