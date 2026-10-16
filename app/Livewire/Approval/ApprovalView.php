<?php

namespace App\Livewire\Approval;

use App\Models\MedicalEvents\Sql\Approval;
use App\Models\Person\Person;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ApprovalView extends Component
{
    public Approval $approval;

    public function mount(Approval $approval): void
    {
        $this->approval = Approval::query()
            ->with(['approvable', 'grantedTo', 'createdBy', 'reason'])
            ->whereHas('grantedTo', static fn ($query) => $query->where('value', Auth::user()->activeDoctorEmployee()->uuid))
            ->findOrFail($approval->id);
    }

    #[Computed]
    public function patient(): Person
    {
        return $this->approval->approvable instanceof Person ? $this->approval->approvable : $this->approval->approvable->person;
    }

    public function render(): View
    {
        return view('livewire.approval.approval-view');
    }
}
