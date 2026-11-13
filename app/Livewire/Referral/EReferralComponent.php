<?php

declare(strict_types=1);

namespace App\Livewire\Referral;

use App\Livewire\Referral\Forms\EReferralForm;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Shared referral state; Blade views and their UI events are deferred to Mefizz/ohealth#41. */
abstract class EReferralComponent extends Component
{
    public EReferralForm $eReferralForm;

    #[Locked]
    public int $legalEntityId;

    #[Locked]
    public int $personId;

    #[Locked]
    public int $referralId;

    protected ?ServiceRequestRequest $referralModel = null;

    abstract protected function actionAbility(): string;

    public function mount(int $legalEntityId, int $personId, int $referralId): void
    {
        $this->legalEntityId = $legalEntityId;
        $this->personId = $personId;
        $this->referralId = $referralId;

        $record = $this->referral();
        if (Auth::user()->cannot($this->actionAbility(), $record)) {
            session()->flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        // A summary imported by the registry may not have a complete FHIR graph yet.
        $this->eReferralForm->setReferral([
            'note' => $record->note,
            'patientInstruction' => $record->patientInstruction,
            'priority' => $record->priority?->text,
        ]);
    }

    /** Resolve lazily on every request; protected models do not survive Livewire hydration. */
    protected function referral(): ServiceRequestRequest
    {
        abort_unless((int) legalEntity()->id === $this->legalEntityId, 404);

        return $this->referralModel ??= Repository::serviceRequest()->findForPerson($this->referralId, $this->personId);
    }

    #[Computed]
    public function isEditing(): bool
    {
        return $this->actionAbility() === 'edit';
    }

    #[Computed]
    public function summary(): array
    {
        $record = $this->referral();
        if (Auth::user()->cannot($this->actionAbility(), $record)) {
            return [];
        }

        return Repository::serviceRequest()->toPatientRegistryRow($record);
    }

    #[Computed]
    public function patientFullName(): string
    {
        return $this->summary === [] ? '' : $this->referral()->person->fullName;
    }

    #[Computed]
    public function canEdit(): bool
    {
        return Auth::user()->can('edit', $this->referral());
    }

}
