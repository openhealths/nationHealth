<?php

declare(strict_types=1);

namespace App\Livewire\Employee;

use AllowDynamicProperties;
use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Jobs\EmployeeSync;
use App\Livewire\Actions\Logout;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Role as ModelsRole;
use App\Models\User;
use App\Notifications\EmployeeSyncCompleted;
use App\Notifications\SyncNotification;
use App\Repositories\Repository;
use App\Services\Party\PartyVerificationCache;
use App\Models\Relations\Party;
use App\Traits\BatchLegalEntityQueries;
use App\Livewire\Employee\Concerns\DeletesEmployeeRequestDraft;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use JsonException;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

#[AllowDynamicProperties]
class EmployeeIndex extends EmployeeComponent
{
    use WithPagination;
    use BatchLegalEntityQueries;
    use DeletesEmployeeRequestDraft;

    protected const string BATCH_NAME = 'EmployeeFullSync';
    protected const string DEPENDENT_BATCH_NAME = 'EmployeeDetailsSync';

    // --- Component State for Filters ---
    public string $search = '';
    public array $status = [
        Status::APPROVED->value,
        Status::NEW->value,
        Status::REORGANIZED->value,
    ];
    public array $filter = [
        'phone' => '',
        'email' => '',
        'role' => '',
        'position' => '',
        'division_id' => '',
        'tax_id' => '',
        'verification_status' => '',
    ];

    // --- State for Modals ---
    public bool $showDeactivateModal = false;
    public ?int $employeeIdToDeactivate = null;
    public ?string $employeeToDeactivateName = null;
    public bool $isDoctorToDeactivate = false;
    public string $deactivationEndDate = '';
    public string $deactivationStatus = 'STOPPED';

    public ?int $employeeToDismissId = null;
    public ?string $employeeToDismissName = null;

    public ?string $batchId = null;
    public string $dismissalMessageType = 'default';

    public int $refreshTrigger = 0;

    private LegalEntity $legalEntity;

    /**
     * Represents the current synchronization status for the component.
     *
     * @var string
     */
    public string $syncStatus = '';

    #[Computed]
    public function isSync(): bool
    {
        return $this->isSyncProcessing();
    }

    /**
     * Get the synchronization status of the employee request.
     *
     * @return string The current sync status
     */
    protected function getSyncStatus(): string
    {
        return legalEntity()?->getEntityStatus(LegalEntity::ENTITY_EMPLOYEE) ?? '';
    }

    /**
     * Determine if a synchronization process is currently running.
     *
     * @return bool True if a sync process is actively processing, false otherwise.
     */
    protected function isSyncProcessing(): bool
    {
        // Get the sync status for whole Legal Entity
        $legalEntitySyncStatus = legalEntity()?->getEntityStatus();

        // Set the sync status only for Employee
        $this->syncStatus = $this->getSyncStatus();

        // Determine if either the Legal Entity's sync is in progress
        $legalEntitySync = $this->isEntitySyncIsInProgress($legalEntitySyncStatus, true);

        // Determine if either the Division's sync is in progress
        $employeeSync = $this->isEntitySyncIsInProgress($this->syncStatus);

        // Return true if either sync is in progress
        return $legalEntitySync || $employeeSync;
    }

    public function boot(): void
    {
        $this->legalEntity = legalEntity();

        // This will ensure that the 'isSync' computed property is not cached between requests
        unset($this->isSync);
    }

    public function mount(LegalEntity $legalEntity): void
    {
        $this->authorize('viewAny', Employee::class);

        $this->legalEntity = $legalEntity;

        $this->loadDivisions($legalEntity);

        $this->loadDictionaries();

        // Set the sync status for Employee
        $this->syncStatus = $this->getSyncStatus();
    }

    public function hydrate(): void
    {
        $this->status = array_values(array_unique(array_map(
            fn (string $status): string => match ($status) {
                Status::SIGNED->value => Status::NEW->value,
                Status::DISMISSED->value => Status::STOPPED->value,
                default => $status,
            },
            $this->status,
        )));
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, array{verification_status: mixed}>
     */
    public function partyVerificationDetails(Party $party): array
    {
        if (empty($party->uuid)) {
            return [];
        }

        return PartyVerificationCache::get($party->uuid)['details'] ?? [];
    }

    /**
     * Main computed property to fetch and filter parties.
     */
    #[Computed]
    public function parties(): LengthAwarePaginator
    {
        // 1. We get the basic query from the repository (all the complex SQL is hidden there)
        $query = Repository::employee()->getPartiesWithLatestActivityQuery($this->legalEntity->id);

        // 2. Apply dynamic filters (Search, Email, Phone, etc.)
        // This method (applyDatabaseFilters) remains in the component because it is responsible for UI filtering
        $this->applyDatabaseFilters($query);

        // 3. Return the paginated result
        return $query->paginate(10);
    }

    /**
     * Applies UI filters (Search, Email, Phone, Status) to the query builder.
     */
    private function applyDatabaseFilters(Builder $query): void
    {
        // Only parties with real Employee records for this legal entity (requests live on EmployeeRequestIndex).
        $query->whereHas('employees', function ($sub) {
            $sub->where('legal_entity_id', $this->legalEntity->id);
            $this->applyChildFilters($sub);
        });

        // 2. Filter: Search Text (Full Name, Case-Insensitive, Order-Independent)
        // Each word must match any of last/first/second name — "Іван Петренко" and "Петренко Іван" both work.
        if (!empty($this->search)) {
            $words = preg_split('/\s+/u', trim($this->search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($words as $word) {
                $searchTerm = '%' . $word . '%';
                $query->where(function (Builder $q) use ($searchTerm) {
                    $q->where('last_name', 'ILIKE', $searchTerm)
                        ->orWhere('first_name', 'ILIKE', $searchTerm)
                        ->orWhere('second_name', 'ILIKE', $searchTerm);
                });
            }
        }

        // 3. Filter: Email (via Users on Party)
        if (!empty($this->filter['email'])) {
            $query->whereHas(
                'users',
                fn ($q) => $q->where('email', 'ILIKE', '%' . $this->filter['email'] . '%')
            );
        }

        // 4. Filter: Phone
        if (!empty($this->filter['phone'])) {
            $query->whereHas('phones', fn ($q) => $q->where('number', 'like', '%' . $this->filter['phone'] . '%'));
        }

        // 5–6. tax_id / verification_status — 3.23.3.1.1 (OWNER/HR/ADMIN/PHARMACY_OWNER only)
        if ($this->canViewPartyVerificationMeta()) {
            if (!empty($this->filter['tax_id'])) {
                $query->where('tax_id', 'ILIKE', '%' . trim($this->filter['tax_id']) . '%');
            }

            if (!empty($this->filter['verification_status'])) {
                $query->where('verification_status', $this->filter['verification_status']);
            }
        }
    }

    /**
     * Party tax_id / verification_status in list UI — TZ 3.23.3.1 (elevated roles only).
     */
    private function canViewPartyVerificationMeta(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->hasAllowedRole([Role::ADMIN, Role::HR, Role::OWNER, Role::PHARMACY_OWNER]);
    }

    /**
     * Unique labels for the status multi-select (NEW covers SIGNED, STOPPED covers DISMISSED).
     *
     * @return array<string, string>
     */
    public function statusFilterOptions(): array
    {
        return [
            Status::APPROVED->value => __('forms.status.active'),
            Status::NEW->value => __('forms.status.new'),
            Status::STOPPED->value => __('forms.status.stopped'),
            Status::ENTERED_IN_ERROR->value => __('forms.status.entered_in_error'),
            Status::REORGANIZED->value => __('forms.reorganized'),
        ];
    }

    /**
     * Expand UI status keys to the values stored on employees / requests.
     *
     * @return list<string>
     */
    public function statusesForQuery(): array
    {
        if ($this->status === []) {
            return [];
        }

        $expanded = [];
        foreach ($this->status as $status) {
            $expanded = [
                ...$expanded,
                ...match ($status) {
                    Status::NEW->value, Status::SIGNED->value => [Status::NEW->value, Status::SIGNED->value],
                    Status::STOPPED->value, Status::DISMISSED->value => [Status::STOPPED->value, Status::DISMISSED->value],
                    default => [$status],
                },
            ];
        }

        return array_values(array_unique(array_diff($expanded, ['VERIFIED', 'NOT_VERIFIED'])));
    }

    /**
     * Positions shown for a party after applying list filters (status, role, position, division).
     * Party-level whereHas only decides which parties appear; this trims rows inside each party card.
     * EmployeeRequest drafts/pending updates are listed only on EmployeeRequestIndex.
     *
     * @return Collection<int, mixed>
     */
    public function positionsForParty(Party $party): Collection
    {
        $legalEntityId = $this->legalEntity->id;
        $allowed = $this->statusesForQuery();

        $positions = $party->employees
            ->where('legal_entity_id', $legalEntityId)
            ->sortByDesc('updated_at');

        return $positions
            ->filter(function ($position) use ($allowed) {
                if ($allowed !== []) {
                    $status = $position->status instanceof \UnitEnum
                        ? $position->status->value
                        : (string) $position->status;

                    if (!in_array($status, $allowed, true)) {
                        return false;
                    }
                }

                if (!empty($this->filter['division_id'])
                    && (string) $position->division_id !== (string) $this->filter['division_id']
                ) {
                    return false;
                }

                $employeeType = $position->employeeType ?? $position->employee_type ?? null;
                if (!empty($this->filter['role']) && (string) $employeeType !== (string) $this->filter['role']) {
                    return false;
                }

                $positionCode = $position->position ?? null;
                if (!empty($this->filter['position']) && (string) $positionCode !== (string) $this->filter['position']) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * Helper to apply role, division, position, and status filters to relationship subqueries.
     */
    private function applyChildFilters(Builder $subQuery): void
    {
        $dbStatuses = $this->statusesForQuery();
        if ($dbStatuses !== []) {
            $subQuery->whereIn('status', $dbStatuses);
        }

        // Division Filter
        if (!empty($this->filter['division_id'])) {
            $subQuery->where('division_id', $this->filter['division_id']);
        }

        // Role Filter
        if (!empty($this->filter['role'])) {
            $subQuery->where('employee_type', $this->filter['role']);
        }

        // Position Filter
        if (!empty($this->filter['position'])) {
            $subQuery->where('position', $this->filter['position']);
        }
    }

    public function showModalDeactivate(int $id): void
    {
        $employee = Employee::find($id);

        if ($employee) {
            $this->authorize('deactivate', $employee);

            $this->employeeIdToDeactivate = $id;

            $this->employeeToDeactivateName = $employee->full_name
                ?? ($employee->last_name . ' ' . $employee->first_name);

            $type = $employee->employeeType ?? $employee->employee_type ?? '';

            $this->isDoctorToDeactivate = ($type === Role::DOCTOR->value);
        }

        $this->deactivationStatus = Status::STOPPED->value;
        $this->deactivationEndDate = $this->defaultDeactivationEndDate(
            isset($employee) ? ($employee->startDate ?? '') : ''
        );

        $this->showDeactivateModal = true;
    }

    public function updatedDeactivationStatus(string $value): void
    {
        if ($value === Status::ENTERED_IN_ERROR->value) {
            $this->deactivationEndDate = '';
        } elseif ($this->deactivationEndDate === '' && $this->employeeIdToDeactivate) {
            $employee = Employee::find($this->employeeIdToDeactivate);
            $this->deactivationEndDate = $this->defaultDeactivationEndDate($employee?->startDate ?? '');
        }
    }

    public function closeModal(): void
    {
        $this->showDeactivateModal = false;
        $this->reset(['employeeToDeactivateId', 'employeeToDeactivateName', 'dismissalMessageType']);
    }

    public function resetFilters(): void
    {
        $this->reset(['filter', 'status', 'search']);
        $this->status = [
            Status::APPROVED->value,
            Status::NEW->value,
            Status::REORGANIZED->value,
        ];
        $this->resetPage();
    }

    public function deactivate()
    {
        // 1. Get the employee record from the database
        $employee = Employee::find($this->employeeIdToDeactivate);

        if (!$employee) {
            // If the employee is not found, just close and clean UI state
            $this->resetDeactivateState();

            return;
        }

        // eHealth: STOPPED requires end_date (>= start_date, <= today); ENTERED_IN_ERROR omits end_date.
        $status = in_array($this->deactivationStatus, [Status::STOPPED->value, Status::ENTERED_IN_ERROR->value], true)
            ? $this->deactivationStatus
            : Status::STOPPED->value;

        $formattedEndDate = null;

        if ($status === Status::STOPPED->value) {
            $today = $this->kyivToday();
            $startDate = $this->parseFlexibleDate($employee->startDate);
            $endDate = $this->parseFlexibleDate(trim($this->deactivationEndDate)) ?? $today;

            if ($startDate && $endDate->lt($startDate)) {
                $this->dispatch('flashMessage', [
                    'message' => __('employees.deactivation_end_date_before_start'),
                    'type' => 'error',
                ]);

                return;
            }

            if ($endDate->gt($today)) {
                $this->dispatch('flashMessage', [
                    'message' => __('employees.deactivation_end_date_in_future'),
                    'type' => 'error',
                ]);

                return;
            }

            $formattedEndDate = $endDate->format('Y-m-d');
        }

        try {
            $response = EHealth::employee()->deactivate(
                $employee->uuid,
                $formattedEndDate,
                $status
            );

            if (!empty($response)) {
                // 3. Updates in the local database
                $employee->update([
                    'status' => $status,
                    'end_date' => $formattedEndDate,
                    'is_active' => false,
                ]);

                $this->deactivateEmployeeRole($employee);

                $this->resetDeactivateState();

                $sessionUser = Auth::guard('ehealth')->user() ?? Auth::guard('web')->user();
                if (
                    $sessionUser instanceof User
                    && (int) $employee->partyId === (int) $sessionUser->partyId
                ) {
                    return app(Logout::class)(message: __('employees.dismissalSuccess'));
                }

                $this->dispatch('flashMessage', ['message' => __('employees.dismissalSuccess'), 'type' => 'success']);
            } else {
                $this->dispatch(
                    'flashMessage',
                    ['message' => __('employees.dismissalEhealthError'), 'type' => 'error']
                );
            }
        } catch (\Exception $e) {
            // Log the error for debugging purposes
            Log::error('Employee deactivation failed', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage()
            ]);

            $this->dispatch(
                'flashMessage',
                ['message' => __('employees.requestError', ['error' => $this->translateRequestError($e->getMessage())]), 'type' => 'error']
            );
        }

        // 5. Reset UI State to ensure modal is clean for the next action
        $this->resetDeactivateState();
    }

    // Auxiliary method to avoid duplicating the reset code (can be pasted directly into deactivate if you want)
    private function resetDeactivateState(): void
    {
        $this->showDeactivateModal = false;
        $this->employeeIdToDeactivate = null;
        $this->employeeToDeactivateName = null;
        $this->isDoctorToDeactivate = false;
        $this->deactivationEndDate = '';
        $this->deactivationStatus = Status::STOPPED->value;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Throwable
     * @throws NotFoundExceptionInterface
     */
    public function sync(): void
    {
        if ($this->isSyncProcessing()) {
            Session::flash('error', __('forms.errors.sync_already_running'));

            return;
        }

        $user = Auth::user();
        $token = session()->get(config('ehealth.api.oauth.bearer_token'));

        // Try to resume previous sync if it was paused or failed
        if ($this->syncStatus === JobStatus::PAUSED->value || $this->syncStatus === JobStatus::FAILED->value) {

            if ($this->resumeSynchronization($user, $token)) {
                $user->notify(new SyncNotification('employee', 'resumed'));

                return;
            }
        }

        $user->notify(new SyncNotification('employee', 'started'));

        $this->dispatch('flashMessage', [
            'message' => __('employees.sync.started'),
            'type' => 'success',
        ]);

        try {
            $response = EHealth::employee()->getMany(['legal_entity_id' => legalEntity()->uuid]);
        } catch (EHealthConnectionException $e) {
            Log::error('Employee sync failed: No connection to E-Health.', ['error' => $e->getMessage()]);
            $this->dispatch(
                'flashMessage',
                ['message' => __('errors.ehealth.messages.no_connection'), 'type' => 'error']
            );

            return;
        } catch (EHealthResponseException $e) {
            Log::error(
                'Employee sync failed: E-Health API error.',
                ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );
            $this->dispatch(
                'flashMessage',
                ['message' => __('employees.requestError', ['error' => $this->translateRequestError($e->getMessage())]), 'type' => 'error']
            );

            return;
        } catch (\Exception $e) {
            Log::error(
                'Employee sync failed: An unexpected error occurred during initiation.',
                ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );
            $this->dispatch('flashMessage', ['message' => __('employees.sync.error'), 'type' => 'error']);

            return;
        }

        $employees = $response->validate();
        data_forget($employees, '*.party');
        data_forget($employees, '*.doctor');
        data_fill($employees, '*.legal_entity_id', legalEntity()->id);
        data_fill($employees, '*.sync_status', JobStatus::PARTIAL->value);

        Employee::upsert($employees, uniqueBy: ['uuid']);

        if ($response->isNotLast()) {
            Bus::batch([
                new EmployeeSync(
                    legalEntity: $this->legalEntity,
                    page: 2,
                    nextEntity: null
                ),
            ])
                ->withOption('legal_entity_id', $this->legalEntity->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->withOption('sync_entity', LegalEntity::ENTITY_EMPLOYEE)
                ->then(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions())
                ->catch(callback: function (Batch $batch, \Throwable $e) use ($user) {
                    $message = __('employees.sync.failed');
                    Log::error('Employee sync batch failed.', ['batch_id' => $batch->id, 'exception' => $e]);
                    $user->notify(new EmployeeSyncCompleted($message, 'error'));
                })
                ->onQueue('sync')
                ->name(self::BATCH_NAME)
                ->dispatch();
        } else {
            Bus::batch($this->getEmployeeDetailsStartJob($this->legalEntity, null))
                ->withOption('legal_entity_id', $this->legalEntity->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->withOption('sync_entity', LegalEntity::ENTITY_EMPLOYEE)
                ->catch(callback: function (Batch $batch, \Throwable $e) use ($user) {
                    $message = __('employees.sync.failed');
                    Log::error('Employee sync batch failed.', ['batch_id' => $batch->id, 'exception' => $e]);
                    $user->notify(new EmployeeSyncCompleted($message, 'error'));
                })
                ->onQueue('sync')
                ->name(self::DEPENDENT_BATCH_NAME)
                ->dispatch();
        }

        legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_EMPLOYEE);

        $this->dispatch('flashMessage', [
            'message' => "Сторінка 1 оброблена. Решта завантажується фоново.",
            'type' => 'success'
        ]);
    }

    /**
     * Resume the synchronization process for a user with the provided token.
     *
     * This method handles the continuation of a previously initiated synchronization
     * operation for a specific user using an authentication or session token.
     *
     * @param  User  $user  The user instance for whom synchronization should be resumed
     * @param  string  $token  The authentication or session token used to resume the sync process
     * @return void
     */
    protected function resumeSynchronization(User $user, string $token): bool
    {
        $encryptedToken = Crypt::encryptString($token);

        // Find all the EmployeeRequests failed batches for this legal entity and retry them
        $failedBatches = $this->findFailedBatchesByLegalEntity(legalEntity()->id, 'ASC');

        foreach ($failedBatches as $batch) {
            if ($batch->name === self::BATCH_NAME || $batch->name === self::DEPENDENT_BATCH_NAME) {
                Log::info('Resuming Employee sync batch: ' . $batch->name . ' id: ' . $batch->id);

                legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_EMPLOYEE);

                $this->restartBatch($batch, $user, $encryptedToken, legalEntity());

                $this->dispatch('flashMessage', [
                    'message' => __('forms.success.sync_resumed'),
                    'type' => 'success'
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Synchronize a specific employee.
     * Uses the parent syncEmployeeData method.
     */
    public function syncOne(int $employeeId): void
    {
        $employee = Employee::with(['party'])->find($employeeId);

        if (!$employee) {
            $this->dispatch('flashMessage', ['message' => 'Працівника не знайдено', 'type' => 'error']);

            return;
        }

        // Call the core logic from EmployeeComponent
        $success = $this->syncEmployeeData($employee);

        if ($success) {
            $this->refreshTrigger++;
        }
    }

    /**
     * Revoke the Spatie role of the deactivated employee type for linked users,
     * unless another APPROVED employee of the same type remains in this legal entity.
     * Also drops stale direct eHealth scopes so the next login can resync them.
     */
    protected function deactivateEmployeeRole(Employee $employee): void
    {
        $linkedUsers = $this->usersLinkedToEmployee($employee);

        $employee->users()->detach();

        if ($linkedUsers->isEmpty()) {
            return;
        }

        $guards = array_keys((array) config('auth.guards'));
        $savedGuard = Auth::getDefaultDriver();
        $employeeType = $employee->employeeType;

        setPermissionsTeamId($this->legalEntity->id);

        foreach ($linkedUsers as $user) {
            if (
                is_string($employeeType)
                && $employeeType !== ''
                && !$employee->userHasOtherApprovedOfType((int) $user->id, (int) $this->legalEntity->id)
            ) {
                foreach ($guards as $guard) {
                    Auth::shouldUse($guard);

                    if ($user->hasRole($employeeType, $guard)) {
                        $user->removeRole(ModelsRole::findByName($employeeType, $guard));
                    }
                }
            }

            // Direct eHealth scopes are synced on login only; drop stale rows after deactivate.
            $user->syncPermissions([]);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        Auth::shouldUse($savedGuard);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Users bound to the employee via `user_id` or the `employee_users` pivot.
     *
     * @return Collection<int, User>
     */
    protected function usersLinkedToEmployee(Employee $employee): Collection
    {
        $users = collect();

        if ($employee->userId) {
            $owner = User::query()->without(['person'])->find($employee->userId);

            if ($owner instanceof User) {
                $users->push($owner);
            }
        }

        return $users
            ->concat($employee->users()->without(['person'])->get())
            ->unique('id')
            ->values();
    }

    /**
     * Current calendar date in Europe/Kyiv, start of day.
     */
    protected function kyivToday(): Carbon
    {
        return Carbon::now('Europe/Kyiv')->startOfDay();
    }

    /**
     * Parse a date from app display format, ISO, or Carbon into a Kyiv start-of-day value.
     */
    protected function parseFlexibleDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->copy()->timezone('Europe/Kyiv')->startOfDay();
        }

        $value = trim((string) $value);
        $formats = array_unique([config('app.date_format'), 'Y-m-d', 'd.m.Y']);

        foreach ($formats as $format) {
            if ($format === '') {
                continue;
            }

            try {
                $parsed = Carbon::createFromFormat($format, $value, 'Europe/Kyiv');

                if ($parsed !== false) {
                    return $parsed->startOfDay();
                }
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($value, 'Europe/Kyiv')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Default STOPPED end date: today, or the employee start date when it is in the future.
     */
    protected function defaultDeactivationEndDate(mixed $startDateRaw = ''): string
    {
        $today = $this->kyivToday();
        $startDate = $this->parseFlexibleDate($startDateRaw);

        if ($startDate && $today->lt($startDate)) {
            return $startDate->format((string) config('app.date_format'));
        }

        return $today->format((string) config('app.date_format'));
    }

    private function translateRequestError(string $error): string
    {
        if (str_contains($error, 'Missing allowances: employee:deactivate')) {
            return __('employees.errors.missing_allowance_employee_deactivate');
        }

        return $error;
    }

    /**
     * Renders the component view.
     *
     * @throws JsonException
     */
    public function render(): object
    {
        $filterKey = md5(
            $this->search .
            implode(',', $this->status) .
            json_encode($this->filter, JSON_THROW_ON_ERROR) .
            $this->getPage() .
            $this->refreshTrigger
        );

        return view('livewire.employee.employee-index', [
            'parties' => $this->parties,
            'dictionaries' => $this->dictionaries,
            'filterKey' => $filterKey,
        ]);
    }
}
