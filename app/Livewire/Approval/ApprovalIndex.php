<?php

declare(strict_types=1);

namespace App\Livewire\Approval;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\CarePlan;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\LogsExceptions;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class ApprovalIndex extends Component
{
    use LogsExceptions;
    use WithPagination;

    public array $patients = [];
    public array $employees = [];
    public array $resources = [];
    public array $resourceTypes = [];
    public array $reasons = [];
    public array $booleanOptions = [];
    public array $accessLevels = [];

    public string $filterPatientId = '';
    public string $filterGrantedTo = '';
    public string $filterGrantedResources = '';
    public string $filterGrantedResourceType = '';
    public string $filterIsVerified = '';
    public string $filterIsExpired = '';
    public string $filterReason = '';
    public string $filterAccessLevel = '';
    
    public bool $isSearching = false;

    public function mount(): void
    {
        $this->loadFilterOptions();
    }

    #[Computed]
    public function paginatedApprovals(): LengthAwarePaginator
    {
        return $this->isSearching ? $this->searchApprovalsFromEHealth() : $this->paginateLocalApprovals();
    }

    #[Computed]
    public function searchPatient(): ?Person
    {
        return $this->isSearching ? Person::query()->with('names')->where('uuid', $this->filterPatientId)->firstOrFail() : null;
    }

    #[Computed]
    public function localApprovalsByUuid(): Collection
    {
        return Approval::query()->whereIn('uuid', collect($this->paginatedApprovals->items())->pluck('id'))
            ->whereHas('grantedTo', static fn ($query) => $query->where('value', Auth::user()->activeDoctorEmployee()->uuid))
            ->get(['id', 'uuid', 'status'])->keyBy('uuid');
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());
        $this->isSearching = true;
        $this->resetPage();
    }

    public function sync(): void
    {
        $this->validateOnly('filterPatientId', $this->filterValidationRules());
        $patient = Person::query()->where('uuid', $this->filterPatientId)->firstOrFail();
        $employeeUuid = Auth::user()->activeDoctorEmployee()->uuid;
        $page = 1;

        do {
            try {
                $response = EHealth::approval()->getPatientApprovals($patient->uuid, [
                    'granted_to' => $employeeUuid,
                    'page' => $page,
                    'page_size' => config('pagination.per_page'),
                ]);
                $approvals = [];
                foreach ($response->getData() as $approval) {
                    if (data_get($approval, 'granted_to.identifier.value') === $employeeUuid) {
                        $approvals[] = EHealth::approval()->getApprovalDetails($patient->uuid, $approval['id'])->getData();
                    }
                }
            } catch (EHealthException|EHealthConnectionException $exception) {
                $exception->handle('Error while synchronizing approvals');

                return;
            }

            try {
                foreach ($approvals as $approval) {
                    $existing = Approval::query()->where('uuid', $approval['id'])->first();
                    Repository::approval()->sync($approval, $existing?->approvable ?? $patient, $existing);
                }
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Error while synchronizing approvals');

                return;
            }

            $page++;
        } while ($page <= $response->getPaging()['total_pages']);

        $this->loadFilterOptions();
        $this->reset(['filterGrantedTo', 'filterGrantedResources', 'filterGrantedResourceType', 'filterIsVerified', 'filterIsExpired', 'filterReason', 'filterAccessLevel']);
        $this->search();
        $this->dispatch('flashMessage', ['message' => __('patients.sync_success'), 'type' => 'success']);
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterPatientId', 
            'filterGrantedTo', 
            'filterGrantedResources', 
            'filterGrantedResourceType', 
            'filterIsVerified', 
            'filterIsExpired', 
            'filterReason', 
            'filterAccessLevel', 
            'isSearching'
        ]);
        $this->resetValidation();
        $this->resetPage();
    }

    protected function paginateLocalApprovals(): LengthAwarePaginator
    {
        return Approval::query()->withAllRelations()->with([
            'approvable' => static fn (MorphTo $morphTo) => $morphTo->morphWith([Person::class => ['names'], CarePlan::class => ['person.names']]),
        ])->whereHas('grantedTo', static fn ($query) => $query->where('value', Auth::user()->activeDoctorEmployee()->uuid))
            ->latest()->paginate(config('pagination.per_page'));
    }

    protected function searchApprovalsFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();

        try {
            $response = EHealth::approval()->getPatientApprovals($this->filterPatientId, $this->buildSearchParams());
            $approvals = Arr::toCamelCase($response->getData());
            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading approvals');
            $approvals = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($approvals), $total, $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
    }

    private function loadFilterOptions(): void
    {
        $employee = Auth::user()->activeDoctorEmployee();
        $approvals = Approval::query()->with([
            'reason', 'grantedResources.grantedTo',
            'approvable' => static fn (MorphTo $morphTo) => $morphTo->morphWith([Person::class => ['names'], CarePlan::class => ['person.names']]),
        ])->whereHas('grantedTo', static fn ($query) => $query->where('value', $employee->uuid))->get();

        $this->patients = Person::query()->with('names')->get(['id', 'uuid'])
            ->map(static fn (Person $patient): array => ['uuid' => $patient->uuid, 'name' => $patient->fullName.' | '.$patient->uuid])
            ->sortBy('name')->values()->all();
        $this->employees = [['uuid' => $employee->uuid, 'name' => $employee->fullName]];
        $this->resources = $approvals->flatMap(static fn (Approval $approval) => $approval->grantedResources)->pluck('grantedTo')->filter()
            ->map(static fn ($identifier): array => ['uuid' => $identifier->value, 'name' => ($identifier->displayValue ?: $identifier->value).' | '.$identifier->value])
            ->unique('uuid')->sortBy('name')->values()->all();
        $this->reasons = $approvals->pluck('reason')->filter()->map(static fn ($reason): array => ['uuid' => $reason->value, 'name' => $reason->displayValue ?: $reason->value])
            ->unique('uuid')->sortBy('name')->values()->all();
        $this->resourceTypes = collect(__('approval.resource_types'))->map(static fn ($name, $code): array => ['code' => $code, 'name' => $name])->values()->all();
        $this->booleanOptions = [['code' => 'true', 'name' => __('approval.yes')], ['code' => 'false', 'name' => __('approval.no')]];
        $this->accessLevels = [['code' => 'read', 'name' => __('patients.read')], ['code' => 'write', 'name' => __('patients.write')]];
    }

    private function buildSearchParams(): array
    {
        return array_filter([
            'granted_to' => $this->filterGrantedTo ?: Auth::user()->activeDoctorEmployee()->uuid,
            'granted_resources' => $this->filterGrantedResources,
            'granted_resource_type' => $this->filterGrantedResourceType,
            'is_verified' => $this->filterIsVerified,
            'is_expired' => $this->filterIsExpired,
            'reason' => $this->filterReason,
            'access_level' => $this->filterAccessLevel,
            'page' => $this->getPage(),
            'page_size' => config('pagination.per_page'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function filterValidationRules(): array
    {
        return [
            'filterPatientId' => ['required', 'uuid', Rule::in(array_column($this->patients, 'uuid'))],
            'filterGrantedTo' => ['nullable', 'uuid', Rule::in(array_column($this->employees, 'uuid'))],
            'filterGrantedResources' => ['nullable', 'uuid', Rule::in(array_column($this->resources, 'uuid'))],
            'filterGrantedResourceType' => ['nullable', Rule::in(array_column($this->resourceTypes, 'code'))],
            'filterIsVerified' => ['nullable', Rule::in(['true', 'false'])],
            'filterIsExpired' => ['nullable', Rule::in(['true', 'false'])],
            'filterReason' => ['nullable', 'uuid', Rule::in(array_column($this->reasons, 'uuid'))],
            'filterAccessLevel' => ['nullable', Rule::in(['read', 'write'])],
        ];
    }

    public function render(): View
    {
        return view('livewire.approval.approval-index');
    }
}
