<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Enums\DeviceDispense\DeviceReferenceType;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\LegalEntity;

/**
 * The dispensing rules of TV 3.22.1, kept out of the form so they can be applied wherever a dispense is
 * recorded and tested without a Livewire component.
 *
 * Every rule returns a translation key with its parameters instead of a message, so the caller decides
 * whether it becomes a validation error on a field or an exception.
 */
class DeviceDispenseRules
{
    /**
     * Devices allowed to be dispensed under a medical program, keyed by program UUID.
     *
     * The catalog is read per program rather than in full, because only devices participating in the
     * program of the source request may be handed over under it (TV 3.22.1.5).
     *
     * @var array<string, array<int, array<string, mixed>>|null>
     */
    private array $programDevices = [];

    /**
     * Check the amount being handed over against the source request (TV 3.22.1.1, 3.22.1.2, 3.22.1.3).
     *
     * @param  int  $quantity  Amount being handed over now
     * @param  DeviceDispenseSourceRequest|null  $sourceRequest  Null when the dispense names no request
     * @param  LegalEntity  $legalEntity  The legal entity the dispense is recorded in
     * @return array{0: string, 1: array<string, mixed>}|null  Translation key and parameters, or null when allowed
     */
    public function checkQuantity(
        int $quantity,
        ?DeviceDispenseSourceRequest $sourceRequest,
        LegalEntity $legalEntity
    ): ?array {
        // TV 3.22.2.2 — the amount is an integer greater than zero regardless of anything else
        if ($quantity < 1) {
            return ['device-dispenses.validation.quantity_positive_integer', []];
        }

        if ($sourceRequest === null) {
            return null;
        }

        $remaining = $sourceRequest->remainingQuantity();

        // TV 3.22.1.3 — in a healthcare facility the amount is capped only when the request states one;
        // with no stated amount the medical professional decides how many devices the patient receives
        if ($remaining === null) {
            return null;
        }

        if ($remaining === 0) {
            return ['device-dispenses.validation.request_already_dispensed', []];
        }

        if ($quantity > $remaining) {
            return ['device-dispenses.validation.quantity_exceeds_remaining', ['remaining' => $remaining]];
        }

        // TV 3.22.1.2 — a request carrying a medical program is handed over in full and only in a pharmacy,
        // so a partial dispense under a program is refused rather than left to be rejected by eHealth later
        if ($sourceRequest->hasProgram() && $quantity < $remaining) {
            return ['device-dispenses.validation.program_requires_full_dispense', ['remaining' => $remaining]];
        }

        if ($sourceRequest->hasProgram() && !$legalEntity->isPharmacy()) {
            return ['device-dispenses.validation.program_requires_pharmacy', []];
        }

        return null;
    }

    /**
     * Check that the dispense names the device the way the source request allows (TV 3.22.1.4).
     *
     * @param  DeviceReferenceType  $referenceType  How the dispense names the device
     * @param  DeviceDispenseSourceRequest|null  $sourceRequest
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function checkDeviceReference(
        DeviceReferenceType $referenceType,
        ?DeviceDispenseSourceRequest $sourceRequest
    ): ?array {
        if ($sourceRequest === null) {
            return null;
        }

        // TV 3.22.1.4.2 — a request written for a device model is dispensed as a model or a brand, never
        // widened back to the bare device type. TV 3.22.1.4.1 leaves a request written for a type open to both.
        if (
            $sourceRequest->deviceReferenceType === DeviceReferenceType::DEVICE_DEFINITION
            && $referenceType === DeviceReferenceType::DEVICE_CODE
        ) {
            return ['device-dispenses.validation.model_request_requires_model', []];
        }

        return null;
    }

    /**
     * Check that the device handed over takes part in the program of the source request (TV 3.22.1.5).
     *
     * A dispense naming a device type cannot be checked against the program catalog, which lists device
     * definitions, so under a program the dispense has to name a definition for the rule to hold.
     *
     * @param  DeviceReferenceType  $referenceType
     * @param  string  $deviceId  Classification type code or device definition UUID
     * @param  DeviceDispenseSourceRequest|null  $sourceRequest
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function checkProgramParticipation(
        DeviceReferenceType $referenceType,
        string $deviceId,
        ?DeviceDispenseSourceRequest $sourceRequest
    ): ?array {
        if ($sourceRequest === null || !$sourceRequest->hasProgram()) {
            return null;
        }

        if ($referenceType !== DeviceReferenceType::DEVICE_DEFINITION) {
            return ['device-dispenses.validation.program_requires_device_definition', []];
        }

        $programDevices = $this->programDevices($sourceRequest->programId);

        // The catalog could not be read, so the rule is left to eHealth rather than guessed at here
        if ($programDevices === null) {
            return null;
        }

        $participates = collect($programDevices)->contains(
            static fn (array $device): bool => (string) ($device['id'] ?? '') === $deviceId
        );

        if (!$participates) {
            return ['device-dispenses.validation.device_not_in_program', []];
        }

        return null;
    }

    /**
     * Device definitions participating in the given medical program, or null when eHealth could not be reached.
     *
     * @param  string  $programId
     * @return array<int, array<string, mixed>>|null
     */
    public function programDevices(string $programId): ?array
    {
        if (array_key_exists($programId, $this->programDevices)) {
            return $this->programDevices[$programId];
        }

        try {
            $devices = EHealth::deviceDefinition()
                ->getMany(['medical_program_id' => $programId, 'is_active' => true])
                ->getData();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading device definitions of a medical program');

            return $this->programDevices[$programId] = null;
        }

        return $this->programDevices[$programId] = collect($devices)->values()->toArray();
    }
}
