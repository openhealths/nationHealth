<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

/**
 * Normalizes the UI/storage form of a person authentication method
 * (`uuid`, `uuid|OTP|+380…`, or an already-mapped eHealth object).
 */
final class InformWith
{
    public static function authMethodId(mixed $informWith): ?string
    {
        if (is_array($informWith)) {
            $value = $informWith['auth_method_id']
                ?? data_get($informWith, 'identifier.value')
                ?? ($informWith['value'] ?? '');
            $informWith = is_string($value) ? $value : '';
        }

        $id = explode('|', trim((string) $informWith))[0] ?? '';

        return $id !== '' ? $id : null;
    }

    /**
     * Select option value for Livewire forms. Accepts snapshots that still
     * only have `uuid` from before `raw` was added.
     *
     * @param  array{raw?: string, uuid?: string, type?: string, phone_number?: string}  $method
     */
    public static function formValue(array $method): string
    {
        $raw = trim((string) ($method['raw'] ?? ''));
        if ($raw !== '') {
            return $raw;
        }

        $uuid = trim((string) ($method['uuid'] ?? ''));
        if ($uuid === '') {
            return '';
        }

        $type = (string) ($method['type'] ?? '');
        $phone = (string) ($method['phone_number'] ?? '');

        return ($type !== '' || $phone !== '') ? "{$uuid}|{$type}|{$phone}" : $uuid;
    }
}
