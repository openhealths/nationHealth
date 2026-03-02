<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use App\Classes\eHealth\EHealth;
use App\Models\Employee\Employee;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Queue\SerializesModels;
use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Http\Client\ConnectionException;

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
     * @throws ConnectionException
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

        $users = $this->employee->party->users;

        if ($users->isEmpty()) {
            Log::info('Employee sync: no any users associated with this employee record yet.', [
                'employee_id' => $this->employee->id,
                'employee_uuid' => $this->employee->uuid,
            ]);

            return;
        }

        $roleName = $this->employee->employee_type;
        $legalEntityId = $this->employee->legal_entity_id;

        setPermissionsTeamId($legalEntityId);

        foreach ($users as $user) {
            if (!$user->hasRole($roleName)) {
                foreach ($this->getGuardsForRole($roleName) as $guard) {
                    Log::info("Assigning role '{$roleName}' to user ID {$user->id} for guard '{$guard}'.");

                    Auth::shouldUse($guard);

                    $user->assignRole($roleName);
                }
            }
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

    /**
     * Determine which authentication guards define the given role.
     * Checks only the 'web' and 'ehealth' guards.
     * Queries Spatie\Permission\Models\Role by name and guard_name.
     * Returns an empty collection if the role is not defined for any of the checked guards.
     *
     * @param  string  $role  The role name to check across guards.
     * @return Collection<int, string> Collection of guard names that have this role defined.
     */
    protected function getGuardsForRole(string $role): Collection
    {
        $guards = collect(['web', 'ehealth']);

        return $guards->filter(
            fn ($guard) =>
                Role::where('name', $role)
                    ->where('guard_name', $guard)
                    ->exists()
        );
    }
}
