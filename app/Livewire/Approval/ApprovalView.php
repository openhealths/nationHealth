<?php

declare(strict_types=1);

namespace App\Livewire\Approval;

use App\Models\CarePlan;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\Person\Person;
use App\Classes\eHealth\EHealth;
use App\Traits\LogsExceptions;
use App\Repositories\MedicalEvents\Repository;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

use App\Services\MedicalEvents\PatientApprovalService;
use Illuminate\Database\Eloquent\Builder;

class ApprovalView extends Component
{
    use LogsExceptions;

    #[Locked]
    public int $approvalId;

    protected ?Approval $approvalModel = null;

    public function mount(Approval $approval): void
    {
        $this->approvalId = $approval->id;
    }

    public function sync(): void
    {
        if (!Auth::user()->can('sync', Approval::class)) {
            return;
        }

        $approval = $this->approval();
        $patient = $this->patient;

        try {
            $data = EHealth::approval()
                ->getApprovalDetails($patient->uuid, $approval->uuid)
                ->validate();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing approval');

            return;
        }

        try {
            Repository::approval()->sync(
                $data,
                $approval->approvable,
                $approval
            );
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing approval');

            return;
        }

        $this->approvalModel = null;
        unset($this->resourceGroups);

        Session::flash('success', __('patients.sync_success'));
    }

    protected function approval(): Approval
    {
        return $this->approvalModel ??= Approval::query()
            ->with([
                'grantedTo',
                'createdBy',
                'reason',
                'grantedResources.grantedTo.type.coding',
                'grantedResourceTypes.codeableConcept.coding',
                'approvable' => static fn (MorphTo $morphTo) => $morphTo->morphWith([
                    Person::class => ['names'],
                    CarePlan::class => ['person.names'],
                ]),
            ])
            ->findOrFail($this->approvalId);
    }

    #[Computed]
    public function patient(): Person
    {
        $approval = $this->approval();

        return $approval->approvable instanceof Person ? $approval->approvable : $approval->approvable->person;
    }

    #[Computed]
    public function resourceGroups(): array
    {
        $formatter = app(PatientApprovalService::class);

        $resources = $this->approval()->grantedResources
            ->map(static fn ($resource): array => [
                'uuid' => $resource->grantedTo?->value,
                'type' => data_get($resource, 'grantedTo.identifier.type.coding.0.code'),
                'display' => $resource->grantedTo?->displayValue,
            ])
            ->filter(static fn (array $resource): bool => filled($resource['uuid']) && filled($resource['type']))
            ->groupBy('type');

        $models = PatientApprovalService::RESOURCE_MODELS + PatientApprovalService::CHILD_MODELS;
        $groups = [];

        foreach ($resources as $type => $items) {
            $records = collect();

            if (isset($models[$type])) {
                $query = $models[$type]::query()
                    ->whereIn('uuid', $items->pluck('uuid')->all());

                if ($type === 'activity') {
                    $query->whereHas('carePlan', fn (Builder $plan): Builder => $plan->where('person_id', $this->patient->id));
                } else {
                    $query->where('person_id', $this->patient->id);
                }

                $relations = $formatter->resourceRelations($type);

                $records = $query->with($relations)->get()->keyBy('uuid');
            }

            $groups[] = [
                'title' => __('approval.resource_types.'.$type),
                'rows' => $items->map(function (array $resource) use ($records, $type, $models, $formatter): array {
                    $record = $records->get($resource['uuid']);

                    $name = $record !== null ? ($formatter->resourceName($record, $type) ?: $resource['display']) : $resource['display'];

                    if (in_array($type, ['patient', 'person'], true) && $resource['uuid'] === $this->patient->uuid) {
                        $name = $this->patient->fullName;
                    }

                    return [
                        'uuid' => $resource['uuid'],
                        'name' => $name ?: ($record === null && isset($models[$type]) ? __('approval.resource_not_synced') : '-'),
                        'date' => $record !== null ? $formatter->resourceDate($record, $type) : '',
                    ];
                })->values()->all(),
            ];
        }

        foreach ($this->approval()->grantedResourceTypes as $resourceType) {
            $type = data_get($resourceType, 'codeableConcept.coding.0.code');

            if ($type !== null) {
                $groups[] = [
                    'title' => __('approval.resource_types.'.$type),
                    'rows' => [],
                ];
            }
        }

        return $groups;
    }

    public function render(): View
    {
        return view('livewire.approval.approval-view')
            ->with(['approval' => $this->approval()]);
    }
}
