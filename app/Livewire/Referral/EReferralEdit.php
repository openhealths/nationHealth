<?php

declare(strict_types=1);

namespace App\Livewire\Referral;

use App\Dto\ServiceRequest\Model as ReferralData;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class EReferralEdit extends EReferralComponent
{
    public function mount(int $legalEntityId, int $personId, int $referralId): void
    {
        $this->initializeReferral($legalEntityId, $personId, $referralId);
    }

    public function save(): void
    {
        $record = $this->referral();
        if (Auth::user()->cannot('edit', [$record, $this->legalEntity])) {
            session()->flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        $this->eReferralForm->validate();

        try {
            $data = $this->objectMapper->map($this->eReferralForm, ReferralData::class);

            $saved = DB::transaction(function () use ($data): bool {
                // Recheck under the same row lock as persistence: signing may have started after the form opened.
                $record = Repository::serviceRequest()->findForPerson($this->referralId, $this->personId, lockForUpdate: true);
                if (Auth::user()->cannot('edit', [$record, $this->legalEntity])) {
                    return false;
                }

                Repository::serviceRequest()->saveMapped($data, $record);

                return true;
            });
        } catch (ValidationException $exception) {
            // Repository submission errors have no editable field on this page.
            session()->flash('error', __('referrals.messages.action_unavailable'));

            throw $exception;
        } catch (Throwable $exception) {
            Log::channel('db_errors')->error('Referral draft save failed', ['exception' => $exception::class]);
            session()->flash('error', __('messages.database_error'));

            return;
        }

        if (!$saved) {
            session()->flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        $this->referralModel = null;

        // Local fields already equal the saved DTO; refill is reserved for remote sync or server normalization.
        session()->flash('success', __('referrals.draft_saved'));
    }
}
