<?php

declare(strict_types=1);

namespace App\Livewire\Referral;

use App\Dto\ServiceRequest\EReferral as EReferralData;
use App\Livewire\Referral\EReferralComponent;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Throwable;

class EReferralEdit extends EReferralComponent
{
    protected function actionAbility(): string
    {
        return 'edit';
    }

    public function save(): void
    {
        $record = $this->referral();
        if (Auth::user()->cannot('edit', $record)) {
            session()->flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        $this->eReferralForm->validate();

        try {
            $data = new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())
                ->map($this->eReferralForm, EReferralData::class);

            $saved = DB::transaction(function () use ($data): bool {
                // Recheck under the same row lock as persistence: signing may have started after the form opened.
                $record = ServiceRequestRequest::query()->whereKey($this->referralId)
                    ->wherePersonId($this->personId)->lockForUpdate()->firstOrFail();
                if (Auth::user()->cannot('edit', $record)) {
                    return false;
                }

                $resource = $data->toResource(Repository::serviceRequest()->getResource($record));
                Repository::serviceRequest()->store($resource, $this->personId, $record->employeeId, $record->divisionId);

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
        $this->eReferralForm->setReferral(Repository::serviceRequest()->getResource($this->referral()));
        unset($this->summary, $this->canEdit);

        // This confirms only a local draft save; no signing or eHealth submission happened.
        session()->flash('success', __('referrals.draft_saved'));
    }
}
