<?php

declare(strict_types=1);

namespace App\Livewire\Composition\Forms\Concerns;

use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;

trait BuildsCompositionPayload
{
    private function base(array $parts): array
    {
        $period = ['start' => $parts['periodStart']];

        // МВН Confluence examples send `"end": null`; МВТН always has a concrete end.
        if (($parts['periodEnd'] ?? null) !== null || ($parts['includeNullPeriodEnd'] ?? false)) {
            $period['end'] = $parts['periodEnd'] ?? null;
        }

        return [
            'type' => $this->codeableConcept('COMPOSITION_TYPES', $parts['type']->value),
            'category' => $this->codeableConcept('COMPOSITION_CATEGORIES', $parts['category']),
            // `event` is a list even though only the validity period is ever sent.
            'event' => [
                [
                    'code' => $this->codeableConcept('COMPOSITION_EVENTS', 'COMPOSITION_VALIDITY_PERIOD'),
                    'period' => $period,
                ],
            ],
            'subject' => $this->resourceIdentifier($parts['subjectResource'], $parts['subjectUuid']),
            'encounter' => $this->resourceIdentifier('encounter', $parts['encounterUuid']),
            'author' => $this->resourceIdentifier('employee', $parts['authorEmployeeUuid']),
            'section' => [
                'focus' => $this->resourceIdentifier($parts['focusResource'], $parts['focusUuid']),
            ],
        ];
    }

    private function informWith(?string $authenticationMethodUuid): array
    {
        return $authenticationMethodUuid
            ? [['valueCode' => 'INFORM_WITH', 'valueUuid' => $authenticationMethodUuid]]
            : [];
    }

    private function codeableConcept(string $system, string $code): array
    {
        $concept = FhirResource::make()->coding($system, $code)->toCodeableConcept();
        unset($concept['text']);

        return $concept;
    }

    private function resourceIdentifier(string $resource, string $uuid): array
    {
        // Composition uses the bare Identifier, without the Reference wrapper.
        return FhirResource::make()->coding('eHealth/resources', $resource)
            ->toIdentifier($uuid, $resource)['identifier'];
    }

    private function startOfDay(string $date): string
    {
        return $this->date($date) . 'T00:00:01Z';
    }

    private function endOfDay(string $date): string
    {
        return $this->date($date) . 'T20:59:59Z';
    }

    private function date(string $date): string
    {
        return CarbonImmutable::parse($date)->format('Y-m-d');
    }
}
