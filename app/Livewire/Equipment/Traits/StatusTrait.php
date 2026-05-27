<?php

declare(strict_types=1);

namespace App\Livewire\Equipment\Traits;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Equipment\AvailabilityStatus;
use App\Enums\Equipment\Status;
use App\Models\Equipment;
use App\Repositories\Repository;
use App\Rules\InDictionary;
use App\Traits\FormTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Throwable;

trait StatusTrait
{
    use FormTrait;

    public string $status;
    public string $errorReason;
    public string $availabilityStatus;

    public function updateStatus(string $uuid): void
    {
        $equipment = Equipment::whereUuid($uuid)->firstOrFail();
        if (Auth::user()->cannot('updateStatus', $equipment)) {
            Session::flash('error', __('equipments.policy.update'));

            return;
        }

        try {
            $validated = $this->validate($this->rulesForChangingStatus($equipment->uuid));
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $response = EHealth::equipment()->changeStatus($equipment->uuid, removeEmptyKeys(Arr::toSnakeCase($validated)));
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when updating status of equipment');

            return;
        }

        try {
            Repository::equipment()->updateStatus($response->validate()['uuid'], $response->validate());

            Session::flash('success', __('equipments.success.status_updated'));
            $this->dispatch('close-update-status-modal');
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store equipment');

            return;
        }
    }

    public function updateAvailabilityStatus(string $uuid): void
    {
        $equipment = Equipment::whereUuid($uuid)->firstOrFail();
        if (Auth::user()->cannot('updateAvailabilityStatus', $equipment)) {
            Session::flash('error', __('equipments.policy.update_availability_status'));

            return;
        }

        try {
            $validated = $this->validate($this->rulesForChangingAvailabilityStatus($equipment));
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $response = EHealth::equipment()->changeAvailabilityStatus(
                $uuid,
                removeEmptyKeys(Arr::toSnakeCase($validated))
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when updating availability status of equipment');

            return;
        }

        try {
            Repository::equipment()->updateStatus($response->validate()['uuid'], $response->validate());

            Session::flash('success', __('equipments.success.availability_status_updated'));
            $this->dispatch('close-update-availability-status-modal');
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store equipment');

            return;
        }
    }

    protected function rulesForChangingStatus(string $uuid): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in(Status::INACTIVE, Status::ENTERED_IN_ERROR),
                // If status changes to inactive then availability_status on device must be any but available
                function ($attribute, $value, $fail) use ($uuid) {
                    if ($value === Status::INACTIVE->value) {
                        $availability = Equipment::whereUuid($uuid)->value('availability_status');

                        if ($availability === AvailabilityStatus::AVAILABLE) {
                            $fail(__('validation.attributes.statusIncorrect'));
                        }
                    }
                }
            ],
            'errorReason' => ['nullable', 'required_if:status,' . Status::ENTERED_IN_ERROR->value, 'string']
        ];
    }

    protected function rulesForChangingAvailabilityStatus(Equipment $equipment): array
    {
        return [
            'availabilityStatus' => [
                'required',
                'string',
                new InDictionary('equipment_availability_statuses'),
                function ($attribute, $value, $fail) use ($equipment) {
                    if ($value === $equipment->availabilityStatus->value) {
                        $fail(__('Новий статус доступності повинен відрізнятися від поточного.'));
                    }
                }
            ]
        ];
    }
}
