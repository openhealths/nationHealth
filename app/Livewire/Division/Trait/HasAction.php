<?php

declare(strict_types=1);

namespace App\Livewire\Division\Trait;

use Exception;
use Throwable;
use App\Enums\Status;
use App\Models\Division;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Dto\Division\Model as DivisionData;
use Symfony\Component\ObjectMapper\ObjectMapper;
use App\Enums\Division\Status as DivisionStatus;
use App\Enums\Equipment\Status as EquipmentStatus;
use Symfony\Component\PropertyAccess\PropertyAccess;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Enums\HealthcareService\Status as HealthcareServiceStatus;

trait HasAction
{
    /**
     * Set 'ACTIVE' action status for specified division
     *
     * @param  int  $divisionId
     * @return void
     * @throws Exception|EHealthResponseException
     */
    public function activate(int $divisionId): void
    {
        $division = $this->getDivision($divisionId);

        if (!$division) {
            return;
        }

        if (Auth::user()->cannot('activate', $division)) {
            session()->flash('error', __('divisions.policy.deny.activate'));

            return;
        }

        try {
            $response = EHealth::division()->activate($division->uuid);

            session()->flash('success', __('divisions.request.activated'));
        } catch (EHealthResponseException | EHealthConnectionException | EHealthValidationException $err) {
            $err->handle(static::class . ':activateDivision:', __('divisions.errors.activate'));

            return;
        }

        $responseData = $response->getData();

        try {
            Repository::division()->setAction($division, $responseData['status']);

            // Refresh model and sync local state
            $division->refresh();

            // Keep a fresh instance on the component if it uses it
            $this->divisionForm->division['status'] = $responseData['status'];

            if (property_exists($this, 'statusLabel')) {
                $this->statusLabel = DivisionStatus::tryFrom($responseData['status'])?->label() ?? __('forms.unknown');
            }

            if (property_exists($this, 'statusStyle')) {
                $this->statusStyle = DivisionStatus::tryFrom($responseData['status'])?->cssClass() ?? 'status-alert-default';
            }
        } catch (Exception $err) {
            Log::channel('db_errors')->error(static::class . ':activateDivision:', ['message' => $err->getMessage()]);

            session()->flash('error', __('divisions.errors.activate'));
        }
    }

    /**
     * Set 'INACTIVE' action status for specified division
     *
     * @param  int  $divisionId
     * @return void
     * @throws Exception|EHealthResponseException
     */
    public function deactivate(int $divisionId): void
    {
        $division = $this->getDivision($divisionId);

        if (!$division) {
            return;
        }

        if (Auth::user()->cannot('deactivate', $division)) {
            session()->flash('error', __('divisions.policy.deny.deactivate'));

            return;
        }

        if ($validationError = $this->getDeactivationValidationError($division)) {
            session()->flash('error', $validationError);

            return;
        }

        $divisionData = null;

        try {
            $response = EHealth::division()->deactivate($division->uuid);

            // Use the deactivation response to discard local edits without requiring division:details.
            if ($division->status === Status::UNSYNCED) {
                $actualDivision = $response->validate();

                if ($actualDivision->get('status') !== Status::INACTIVE->value) {
                    throw new Exception('eHealth returned an unexpected division status after deactivation.');
                }

                $divisionData = new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessorBuilder()
                    ->disableExceptionOnInvalidPropertyPath()
                    ->getPropertyAccessor())
                    ->map($actualDivision, DivisionData::class);
            }
        } catch (EHealthResponseException $err) {
            $message = __('divisions.errors.deactivate');

            if ($err->response->status() === 409) {
                $reason = $err->response->json('error.message');

                $message = $this->getDeactivationErrorMessage($reason);
            }

            $err->handle(static::class . ':deactivateDivision:', $message);

            return;
        } catch (Throwable $err) {
            Log::channel('e_health_errors')->error(static::class . ':deactivateDivision:', ['message' => $err->getMessage()]);
            session()->flash('error', __('divisions.errors.deactivate'));

            return;
        }

        $responseData = $response->getData();

        try {
            // After successful deactivation, replace unsent local changes with the eHealth data
            // and apply the returned status, matching the data-loss warning shown to the user.
            if ($divisionData !== null) {
                $divisionData->status = $responseData['status'];
                Repository::division()->saveMappedDivision($divisionData, $division, legalEntity());
                $this->setDivisionData($division);
            } else {
                Repository::division()->setAction($division, $responseData['status']);
            }

            // Refresh model and sync local state
            $division->refresh();

            // Keep a fresh instance on the component if it uses it
            $this->divisionForm->division['status'] = $responseData['status'];

            if (property_exists($this, 'statusLabel')) {
                $this->statusLabel = DivisionStatus::tryFrom($responseData['status'])?->label() ?? __('forms.unknown');
            }

            if (property_exists($this, 'statusStyle')) {
                $this->statusStyle = DivisionStatus::tryFrom($responseData['status'])?->cssClass() ?? 'status-alert-default';
            }

            session()->flash('success', __('divisions.request.deactivated'));
        } catch (Throwable $err) {
            Log::channel('db_errors')->error(static::class . ':deactivateDivision:', ['message' => $err->getMessage()]);

            session()->flash('error', __('divisions.errors.deactivate'));
        }
    }

    /**
     * Translate the reason for an eHealth division deactivation conflict.
     */
    protected function getDeactivationErrorMessage(mixed $reason): string
    {
        return match ($reason) {
            'Division cannot be deactivated - active healthcare services exists' => __('divisions.errors.deactivate_active_healthcare_services'),
            'Division cannot be deactivated - active employees exists' => __('divisions.errors.deactivate_active_employees'),
            'Division cannot be deactivated - active equipments exists' => __('divisions.errors.deactivate_active_equipments'),
            default => is_string($reason) && trim($reason) !== ''
                ? __('divisions.errors.deactivate_reason', ['reason' => $reason])
                : __('divisions.errors.deactivate'),
        };
    }

    /**
     * Validate local dependencies before requesting division deactivation from eHealth.
     */
    protected function getDeactivationValidationError(Division $division): ?string
    {
        // Check that there are no active healthcare services related to this division
        if ($division->healthcareServices()
            ->where('status', HealthcareServiceStatus::ACTIVE)
            ->exists()) {
            return __('divisions.errors.deactivate_active_healthcare_services');
        }

        // Check that there are no active employees related to this division
        if ($division->employees()
            ->active()
            ->exists()) {
            return __('divisions.errors.deactivate_active_employees');
        }

        // Check that there are no active records in equipment table related to this division
        if ($division->equipments()
            ->where('status', EquipmentStatus::ACTIVE)
            ->exists()) {
            return __('divisions.errors.deactivate_active_equipments');
        }

        return null;
    }

    /**
     * Delete the record from DB for specified division
     * NOTE: only for divsions with DRAFT status!
     *
     * @param  int  $divisionId
     * @return void
     * @throws Exception|Throwable
     */
    public function delete(int $divisionId): void
    {
        $division = $this->getDivision($divisionId);

        if (!$division) {
            return;
        }

        if (Auth::user()->cannot('delete', $division)) {
            session()->flash('error', __('divisions.policy.deny.delete'));

            return;
        }

        try {
            $division->delete();

            $this->redirect(route('division.index', [legalEntity()]), navigate: true);

            session()->flash('success', __('divisions.draft.delete_success'));
        } catch (Throwable $err) {
            Log::channel('db_errors')->error(static::class . ':deleteDraft:', ['message' => $err->getMessage()]);

            session()->flash('error', __('divisions.draft.errors.delete'));

            return;
        }
    }

    /**
     * Retrieves a Division model by its primary key.
     *
     * @param  int  $id
     * @return Division|null
     */
    protected function getDivision(int $id): ?Division
    {
        $division = legalEntity()->divisions()->find($id);

        if (!$division) {
            Log::channel('db_errors')->error(static::class . ':getDivision:', ['message' => "Cannot find model with id=$id"]);

            session()->flash('error', __('errors.ehealth.messages.request_error'));

            return null;
        }

        return $division;
    }
}
