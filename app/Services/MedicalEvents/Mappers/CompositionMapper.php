<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Enums\Composition\CompositionCategory;
use App\Enums\Composition\CompositionType;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;

/**
 * Builds the createComposition payload for a medical conclusion (МВН / МВТН).
 *
 * Two things make this mapper deliberately different from its siblings:
 *
 * 1. Composition references are bare `resourceIdentifier` objects (`{type, value}`),
 *    not the `{identifier: {type, value}}` shape that {@see FhirResource::toIdentifier()}
 *    produces for the other medical event entities. Reusing that helper here silently
 *    nests every reference one level too deep and eHealth rejects the payload.
 *
 * 2. Extensions are a flat list of `{valueCode, value<Type>}` pairs — the code names the
 *    extension and the value sits in a sibling key typed for it — rather than a map
 *    keyed by the extension name.
 *
 * @see https://app.swaggerhub.com/apis/ehealthua/compositions/2.39.2#/main/createComposition
 */
class CompositionMapper
{
    public const string SYSTEM_TYPES = 'COMPOSITION_TYPES';

    public const string SYSTEM_CATEGORIES = 'COMPOSITION_CATEGORIES';

    public const string SYSTEM_EVENTS = 'COMPOSITION_EVENTS';

    public const string SYSTEM_RESOURCES = 'eHealth/resources';

    public const string EVENT_VALIDITY_PERIOD = 'COMPOSITION_VALIDITY_PERIOD';

    /**
     * Supported relations for relatesTo.
     */
    public const string RELATION_REPLACES = 'replaces';

    public const string RELATION_APPENDS = 'appends';

    /**
     * Build the payload for a temporary disability conclusion (МВТН).
     *
     * @param  array{
     *     category: string,
     *     subjectUuid: string,
     *     isUnidentified: bool,
     *     encounterUuid: string,
     *     sectionFocusUuid: string,
     *     eventPeriodStart: string,
     *     eventPeriodEnd: string,
     *     informWithUuid?: string|null,
     *     isAccident?: bool,
     *     isIntoxicated?: bool,
     *     isForeignTreatment?: bool,
     *     isForceRenew?: bool,
     *     treatmentViolation?: string|null,
     *     treatmentViolationDate?: string|null,
     *     relatesToTargetUuid?: string|null,
     *     relatesToCode?: string|null
     * }  $data
     * @return array<string, mixed>
     */
    public function tempDisability(array $data, string $authorEmployeeUuid): array
    {
        $isUnidentified = (bool) ($data['isUnidentified'] ?? false);
        $subjectResource = $isUnidentified ? 'preperson' : 'person';

        $payload = $this->base([
            'type' => CompositionType::TEMP_DISABILITY,
            'category' => $data['category'],
            'subjectUuid' => $data['subjectUuid'],
            'subjectResource' => $subjectResource,
            'encounterUuid' => $data['encounterUuid'],
            'authorEmployeeUuid' => $authorEmployeeUuid,
            'focusUuid' => $data['sectionFocusUuid'],
            'focusResource' => $subjectResource,
            'periodStart' => $this->startOfDay($data['eventPeriodStart']),
            'periodEnd' => $this->endOfDay($data['eventPeriodEnd']),
        ]);

        $extensions = $this->informWith($data['informWithUuid'] ?? null);

        foreach ([
            'IS_ACCIDENT' => $data['isAccident'] ?? false,
            'IS_INTOXICATED' => $data['isIntoxicated'] ?? false,
            'IS_FOREIGN_TREATMENT' => $data['isForeignTreatment'] ?? false,
            'IS_FORCE_RENEW' => $data['isForceRenew'] ?? false,
        ] as $code => $isSet) {
            if ($isSet) {
                $extensions[] = ['valueCode' => $code, 'valueBoolean' => true];
            }
        }

        if (!empty($data['treatmentViolation'])) {
            $extensions[] = [
                'valueCode' => 'TREATMENT_VIOLATION',
                'valueString' => $data['treatmentViolation'],
            ];

            if (!empty($data['treatmentViolationDate'])) {
                $extensions[] = [
                    'valueCode' => 'TREATMENT_VIOLATION_DATE',
                    'valueDate' => $this->date($data['treatmentViolationDate']),
                ];
            }
        }

        $payload['extension'] = $extensions;

        if (!empty($data['relatesToTargetUuid'])) {
            $payload['relatesTo'] = [
                'code' => $data['relatesToCode'] ?? self::RELATION_REPLACES,
                'targetIdentifier' => $this->resourceIdentifier('composition', $data['relatesToTargetUuid']),
            ];
        }

        return $payload;
    }

    /**
     * Build the payload for a birth conclusion (МВН).
     *
     * The validity period of a birth conclusion is open-ended: Confluence createComposition
     * examples send `"end": null`, and the start must equal the newborn's date of birth
     * (TV 3.8.1.5.2).
     *
     * @param  array{
     *     category: string,
     *     prepersonUuid: string,
     *     encounterUuid: string,
     *     personUuid: string,
     *     newbornBirthDate: string,
     *     newbornSex: string,
     *     informWithUuid?: string|null
     * }  $data
     * @return array<string, mixed>
     */
    public function newborn(array $data, string $authorEmployeeUuid): array
    {
        $payload = $this->base([
            'type' => CompositionType::NEWBORN,
            'category' => $data['category'],
            'subjectUuid' => $data['prepersonUuid'],
            'subjectResource' => 'preperson',
            'encounterUuid' => $data['encounterUuid'],
            'authorEmployeeUuid' => $authorEmployeeUuid,
            'focusUuid' => $data['personUuid'],
            'focusResource' => 'person',
            'periodStart' => $this->startOfDay($data['newbornBirthDate']),
            'periodEnd' => null,
            'includeNullPeriodEnd' => true,
        ]);

        $payload['extension'] = array_merge($this->informWith($data['informWithUuid'] ?? null), [
            ['valueCode' => 'NEWBORN_BIRTH_DATE', 'valueDate' => $this->date($data['newbornBirthDate'])],
            ['valueCode' => 'NEWBORN_SEX', 'valueString' => $data['newbornSex']],
        ]);

        return $payload;
    }

    /**
     * Parts shared by both conclusion types.
     *
     * @param  array{
     *     type: CompositionType,
     *     category: string,
     *     subjectUuid: string,
     *     subjectResource: string,
     *     encounterUuid: string,
     *     authorEmployeeUuid: string,
     *     focusUuid: string,
     *     focusResource: string,
     *     periodStart: string,
     *     periodEnd: string|null,
     *     includeNullPeriodEnd?: bool
     * }  $parts
     * @return array<string, mixed>
     */
    private function base(array $parts): array
    {
        $period = ['start' => $parts['periodStart']];

        // МВН Confluence examples send `"end": null`; МВТН always has a concrete end.
        if (($parts['periodEnd'] ?? null) !== null || ($parts['includeNullPeriodEnd'] ?? false)) {
            $period['end'] = $parts['periodEnd'] ?? null;
        }

        return [
            'type' => $this->codeableConcept(self::SYSTEM_TYPES, $parts['type']->value),
            'category' => $this->codeableConcept(self::SYSTEM_CATEGORIES, $parts['category']),
            // `event` is a list even though only the validity period is ever sent.
            'event' => [
                [
                    'code' => $this->codeableConcept(self::SYSTEM_EVENTS, self::EVENT_VALIDITY_PERIOD),
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

    /**
     * @return list<array<string, string>>
     */
    private function informWith(?string $authenticationMethodUuid): array
    {
        return $authenticationMethodUuid
            ? [['valueCode' => 'INFORM_WITH', 'valueUuid' => $authenticationMethodUuid]]
            : [];
    }

    /**
     * CodeableConcept without an empty `text` key.
     *
     * Confluence createComposition examples for `type` / `category` / `event.code` send
     * only `{coding: [...]}`. An empty `text: ""` is accepted by OpenAPI but the EMAL
     * Java DTO fails to deserialize CreateCompositionPayload.type when it is present.
     *
     * @return array{coding: list<array{system: string, code: string}>}
     */
    private function codeableConcept(string $system, string $code): array
    {
        $concept = FhirResource::make()->coding($system, $code)->toCodeableConcept();
        unset($concept['text']);

        return $concept;
    }

    /**
     * Composition references are bare `{type, value}` identifiers, not the
     * `{identifier: {type, value}}` wrapper that {@see FhirResource::toIdentifier()}
     * produces for the other medical-event entities.
     *
     * @return array{type: array{coding: list<array{system: string, code: string}>, text?: string}, value: string}
     */
    private function resourceIdentifier(string $resource, string $uuid): array
    {
        // Resource identifiers in the Confluence examples keep a descriptive `text`;
        // empty string is fine here (unlike composition type/category).
        return [
            'type' => FhirResource::make()->coding(self::SYSTEM_RESOURCES, $resource)->toCodeableConcept($resource),
            'value' => $uuid,
        ];
    }

    /**
     * Start of the validity period: the chosen day at 00:00:01 (TV 3.8.1.5.2, 3.8.2.5.2).
     *
     * The time is a fixed marker for "start of day", not a real instant, so the calendar
     * date must survive untouched. Converting the parsed date to UTC first would shift it
     * to the previous day for any positive offset, which is exactly what Kyiv time has.
     */
    private function startOfDay(string $date): string
    {
        return $this->date($date) . 'T00:00:01Z';
    }

    /**
     * End of the validity period: the chosen day at 20:59:59 (TV 3.8.2.5.2).
     */
    private function endOfDay(string $date): string
    {
        return $this->date($date) . 'T20:59:59Z';
    }

    private function date(string $date): string
    {
        return CarbonImmutable::parse($date)->format('Y-m-d');
    }

    /**
     * Categories a given type may be created with, as dictionary options.
     *
     * @return array<string, string>
     */
    public function categoryOptions(CompositionType $type): array
    {
        $descriptions = dictionary()->basics()
            ->byName(CompositionCategory::DICTIONARY)
            ->asCodeDescription();

        $options = [];

        foreach (CompositionCategory::forType($type) as $category) {
            $options[$category->value] = $descriptions->get($category->value, $category->value);
        }

        return $options;
    }
}
