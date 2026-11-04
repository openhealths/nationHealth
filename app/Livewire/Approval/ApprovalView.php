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

    public function render(): View
    {
        return view('livewire.approval.approval-view')
            ->with(['approval' => $this->approval()]);
    }
}
