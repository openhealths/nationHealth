<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EHealth\EHealthConnectionException;
use Throwable;
use App\Core\Arr;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Division;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use App\Classes\eHealth\EHealth;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use App\Classes\eHealth\EHealthResponse;
use App\Models\Employee\EmployeeRequest;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;

class EmployeeDetailsUpsert extends EHealthJob
{
    use Dispatchable;
    use SerializesModels;

    public const string BATCH_NAME = 'EmployeeDetailsSync';

    public const string SCOPE_REQUIRED = 'employee:details';

    public const string ENTITY = LegalEntity::ENTITY_EMPLOYEE;

    public function __construct(
        public Employee $employee,
        public ?LegalEntity $legalEntity,
        protected ?EHealthJob $nextEntity = null,
        public bool $standalone = false,
    ) {
        parent::__construct(legalEntity: $legalEntity, nextEntity: $nextEntity, standalone: $standalone);
    }

    // Get data from EHealth API

    /**
     * @throws EHealthConnectionException
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        return EHealth::employee()->withToken($token)->getDetails($this->employee->uuid, groupByEntities: true);
    }

    // Store or update data in the database

    /**
     * @throws Throwable
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $validatedData = $response->validate();

        Log::info('Processing EmployeeDetailsUpsert for employee:' . $this->employee->id . ', LE:' . ($this->legalEntity->id ?? 'N/A'));

        $divisionUuid = Arr::get($validatedData['division'], 'uuid');
        $divisionId = $divisionUuid ? Division::where('uuid', $divisionUuid)->value('id') : null;

        $this->employee->legalEntityUuid = $this->legalEntity?->uuid;

        $this->employee->save();

        Repository::employee()->updateDetails(
            $this->employee,
            $validatedData['party'],
            $validatedData['documents'],
            $validatedData['phones'],
            $validatedData['educations'] ?? null,
            $validatedData['specialities'] ?? null,
            $validatedData['qualifications'] ?? null,
            $validatedData['scienceDegree'] ?? null
        );

        $this->employee->setSyncStatus(JobStatus::COMPLETED);
        $this->employee->refresh();

        $roleName = $this->employee->employee_type;
        $legalEntityId = $this->employee->legal_entity_id;

        setPermissionsTeamId($legalEntityId);

        $startdate = $validatedData['employee']['start_date'] ?? null;

        $employeeEmployeeRequest = $this->resolveEmployeeRequest(
            $legalEntityId,
            $roleName,
            $divisionUuid,
            $startdate
        );

        $userID = User::where('email', $employeeEmployeeRequest?->email)->first()?->id;

        $employeeEmployeeRequest?->update(['employee_id' => $this->employee->id]);

        $updateData = [
            'division_uuid' => $divisionUuid,
            'division_id' => $divisionId,
            'user_id' => $this->employee->userId ?? $userID,
        ];

        if ($employeeEmployeeRequest?->appliedAt) {
            $updateData['inserted_at'] = Carbon::parse($employeeEmployeeRequest->appliedAt)
                ->format('Y-m-d H:i:s');
        } elseif ($ehealthInsertedAt = Arr::get($validatedData, 'employee.inserted_at')) {
            $updateData['inserted_at'] = Carbon::parse($ehealthInsertedAt)
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d H:i:s');
        }

        $this->employee->update($updateData);

        if ($this->employee->party) {
            Repository::party()->syncUserEmployeesAndRoles($this->employee->party, $this->legalEntity);
        }
    }

    /**
     * Prefer an already-linked request, then fall back to type/position/start_date (+ optional email).
     */
    protected function resolveEmployeeRequest(
        int $legalEntityId,
        string $roleName,
        ?string $divisionUuid,
        ?string $startdate
    ): ?EmployeeRequest {
        $byEmployeeId = EmployeeRequest::query()
            ->where('legal_entity_id', $legalEntityId)
            ->where('employee_id', $this->employee->id)
            ->latest('applied_at')
            ->first();

        if ($byEmployeeId) {
            return $byEmployeeId;
        }

        return EmployeeRequest::query()
            ->where('legal_entity_id', $legalEntityId)
            ->where('employee_type', $roleName)
            ->where('position', $this->employee->position)
            ->when(
                $divisionUuid === null,
                fn ($query) => $query->whereNull('division_uuid'),
                fn ($query) => $query->where('division_uuid', $divisionUuid)
            )
            ->when(
                $startdate === null,
                fn ($query) => $query->whereNull('start_date'),
                fn ($query) => $query->where('start_date', $startdate)
            )
            ->latest('applied_at')
            ->first();
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-employee-get')
        ];
    }

    // Get next entity job if needed
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone || !$this->nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }
}
