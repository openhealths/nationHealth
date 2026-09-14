<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Enums\DeviceDispense\DeviceReferenceType;

/**
 * The Device Request a dispense is issued against, reduced to what the dispense rules actually need.
 *
 * A request may be written either for a device type or for a concrete device definition, may or may not
 * carry a medical program, and may or may not state how many devices the patient is entitled to. Each of
 * those three facts drives a different rule of TV 3.22.1, so they are read once and passed around together.
 */
final readonly class DeviceDispenseSourceRequest
{
    /**
     * @param  string  $uuid
     * @param  DeviceReferenceType  $deviceReferenceType  How the request names the device it was written for
     * @param  string  $deviceId  Classification type code or device definition UUID, per the reference type
     * @param  string|null  $programId  Medical program UUID, when the request was written under one
     * @param  int|null  $quantity  Requested amount, null when the request leaves it open
     * @param  int  $dispensedQuantity  How much has already been handed over against this request
     */
    public function __construct(
        public string $uuid,
        public DeviceReferenceType $deviceReferenceType,
        public string $deviceId,
        public ?string $programId = null,
        public ?int $quantity = null,
        public int $dispensedQuantity = 0
    ) {
    }

    /**
     * Build from the flat shape both the eHealth API and the local device request repository return.
     *
     * @param  array  $request
     * @param  int  $dispensedQuantity
     * @return self
     */
    public static function fromArray(array $request, int $dispensedQuantity = 0): self
    {
        $deviceDefinitionId = (string) (data_get($request, 'code_reference.identifier.value')
            ?? data_get($request, 'deviceDefinitionId')
            ?? '');
        $typeCode = (string) (data_get($request, 'code.coding.0.code') ?? data_get($request, 'deviceTypeCode') ?? '');

        // The device request repository keeps both representations in one `device_id` column, so a UUID there
        // means the request was written for a device definition and anything else for a classification type
        if ($deviceDefinitionId === '' && $typeCode === '') {
            $deviceId = (string) (data_get($request, 'device_id') ?? data_get($request, 'deviceId') ?? '');

            if (self::looksLikeUuid($deviceId)) {
                $deviceDefinitionId = $deviceId;
            } else {
                $typeCode = $deviceId;
            }
        }

        $quantity = data_get($request, 'quantity');

        if (is_array($quantity)) {
            $quantity = data_get($quantity, 'value');
        }

        $quantity ??= data_get($request, 'quantity_integer') ?? data_get($request, 'quantityInteger');

        return new self(
            uuid: (string) (data_get($request, 'uuid') ?? data_get($request, 'id') ?? ''),
            deviceReferenceType: $deviceDefinitionId !== ''
                ? DeviceReferenceType::DEVICE_DEFINITION
                : DeviceReferenceType::DEVICE_CODE,
            deviceId: $deviceDefinitionId !== '' ? $deviceDefinitionId : $typeCode,
            programId: (string) (data_get($request, 'program.identifier.value')
                ?? data_get($request, 'program_id')
                ?? data_get($request, 'programId')
                ?? '') ?: null,
            quantity: $quantity === null || $quantity === '' ? null : (int) $quantity,
            dispensedQuantity: $dispensedQuantity
        );
    }

    /**
     * Whether the request was written under a medical program, which makes the program rules apply.
     */
    public function hasProgram(): bool
    {
        return $this->programId !== null && $this->programId !== '';
    }

    /**
     * How much is still to be handed over, or null when the request left the amount open.
     */
    public function remainingQuantity(): ?int
    {
        if ($this->quantity === null) {
            return null;
        }

        return max(0, $this->quantity - $this->dispensedQuantity);
    }

    private static function looksLikeUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
