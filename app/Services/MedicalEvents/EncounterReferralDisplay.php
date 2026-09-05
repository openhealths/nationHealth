<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use Illuminate\Support\Collection;

class EncounterReferralDisplay
{
    /**
     * Paper requisition, electronic request number, or the stored identifier.
     *
     * @param  array<string, mixed>  $encounter
     * @param  array<string, string>  $requestNumbersByUuid
     */
    public static function label(array $encounter, array $requestNumbersByUuid = []): string
    {
        $paper = data_get($encounter, 'paperReferral.requisition');
        if (filled($paper)) {
            return (string) $paper;
        }

        $uuid = data_get($encounter, 'incomingReferral.identifier.value')
            ?: data_get($encounter, 'incomingReferral.value');
        $display = data_get($encounter, 'incomingReferral.displayValue')
            ?: data_get($encounter, 'incomingReferral.display_value');

        if (filled($display) && (string) $display !== (string) $uuid) {
            return (string) $display;
        }

        if (filled($uuid) && isset($requestNumbersByUuid[(string) $uuid]) && $requestNumbersByUuid[(string) $uuid] !== '') {
            return $requestNumbersByUuid[(string) $uuid];
        }

        if (filled($display)) {
            return (string) $display;
        }

        return filled($uuid) ? (string) $uuid : '-';
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $encounters
     * @return array<string, string>
     */
    public static function requestNumbersFor(iterable $encounters): array
    {
        $uuids = Collection::make($encounters)
            ->map(static fn (array $encounter): ?string => self::incomingReferralUuid($encounter))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($uuids === []) {
            return [];
        }

        return ServiceRequestRequest::query()
            ->whereIn('uuid', $uuids)
            ->whereNotNull('request_number')
            ->pluck('request_number', 'uuid')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $encounter
     */
    public static function incomingReferralUuid(array $encounter): ?string
    {
        $uuid = data_get($encounter, 'incomingReferral.identifier.value')
            ?: data_get($encounter, 'incomingReferral.value');

        return filled($uuid) ? (string) $uuid : null;
    }
}
