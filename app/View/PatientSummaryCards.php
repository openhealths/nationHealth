<?php

declare(strict_types=1);

namespace App\View;

use App\Enums\ClinicalImpression\Status as ClinicalImpressionStatus;
use App\Enums\Device\Status as DeviceStatus;
use App\Enums\Episode\Status as EpisodeStatus;
use App\Enums\Person\ConditionClinicalStatus;
use App\Enums\Person\ConditionVerificationStatus;
use App\Enums\Person\DiagnosticReportStatus;
use App\Enums\Person\EncounterStatus;
use App\Enums\Person\ImmunizationStatus;
use App\Enums\Person\ObservationStatus;
use App\Enums\Person\ProcedureStatus;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/** Formats the records already loaded by the patient summary for its Blade cards. */
class PatientSummaryCards
{
    private const array SECTIONS = [
        'episodes' => ['title' => 'name', 'titleLabel' => 'episodes.name', 'route' => ['episodes.view', 'episode'], 'fields' => [
            'period.start' => 'episodes.period_start', 'period.end' => 'episodes.period_end',
            'ehealthUpdatedAt' => 'episodes.updated_at_date', 'careManager' => 'episodes.doctor',
        ], 'ids' => ['uuid' => 'forms.ehealth_id']],
        'encounters' => ['title' => 'period.start', 'titleLabel' => 'patient-summary.date', 'route' => ['encounters.show', 'encounter'], 'fields' => [
            'class' => 'encounters.interaction_class', 'type' => 'encounters.interaction_type',
            'performerSpeciality' => 'encounters.performer_speciality',
        ], 'ids' => ['uuid' => 'forms.ehealth_id', 'episode' => 'encounters.episode_id']],
        'clinicalImpressions' => ['title' => 'code', 'titleLabel' => 'clinical-impressions.code', 'route' => ['clinical-impressions.view', 'clinicalImpression'], 'fields' => [
            'ehealthInsertedAt' => 'clinical-impressions.inserted_at',
            'effectivePeriod.start|effectiveDateTime' => 'clinical-impressions.effective_period_start',
            'effectivePeriod.end' => 'clinical-impressions.effective_period_end',
            'assessor' => 'clinical-impressions.assessor', 'summary' => 'clinical-impressions.summary',
        ], 'ids' => ['uuid' => 'forms.ehealth_id', '@episode' => 'encounters.episode_id']],
        'immunizations' => ['title' => 'vaccineCode', 'titleLabel' => 'immunizations.vaccine', 'route' => ['immunizations.view', 'immunization'], 'fields' => [
            'doseQuantity' => 'immunizations.dose_quantity', 'route' => 'immunizations.route',
            'explanation.reasons|explanations.0.reasons' => 'immunizations.explanation',
            'reactions.*.detail' => 'immunizations.reactions', 'performer' => 'medical-events.performer',
            '@manufacturerLot' => 'immunizations.manufacturer_and_lot_number', 'site' => 'immunizations.site',
            '@given' => 'immunizations.was_performed', 'date' => 'immunizations.date_time_performed',
            'ehealthInsertedAt' => 'immunizations.inserted_at',
        ], 'ids' => ['uuid' => 'forms.ehealth_id', 'context' => 'patient-summary.encounter_id']],
        'observations' => ['title' => '@observationTitle', 'titleLabel' => 'observations.category_and_code', 'route' => ['observations.view', 'observation'], 'fields' => [
            '@source' => 'medical-events.information_source', 'method' => 'observations.method',
            '@observationValue' => 'observations.value', 'effectivePeriod|effectiveDateTime' => 'observations.getting_indicators',
            'ehealthUpdatedAt' => 'observations.updated_at', 'interpretation|components.*.interpretation' => 'observations.interpretation',
            'bodySite' => 'observations.body_site', 'performer' => 'observations.doctor', 'ehealthInsertedAt' => 'observations.inserted_at',
        ], 'ids' => ['uuid' => 'forms.ehealth_id', 'context|diagnosticReport' => 'patient-summary.context_id']],
        'conditions' => ['title' => 'code', 'titleLabel' => 'medical-events.code_and_name', 'status' => 'clinicalStatus', 'statusLabel' => 'conditions.clinical_status', 'route' => ['conditions.view', 'condition'], 'fields' => [
            'reportOrigin' => 'medical-events.information_source',
            'role' => 'patient-summary.diagnosis_role', 'verificationStatus' => 'conditions.verification_status',
            'bodySites' => 'conditions.body_sites', 'assertedDate' => 'conditions.asserted_date',
            'ehealthInsertedAt|insertedAt' => 'patient-summary.created_at',
            'asserter' => 'conditions.doctor', 'severity' => 'conditions.severity', 'onsetDate' => 'conditions.onset_date',
        ], 'ids' => ['uuid' => 'forms.ehealth_id', 'context' => 'patient-summary.encounter_id']],
        'diagnoses' => ['title' => 'code', 'titleLabel' => 'medical-events.code_and_name', 'status' => 'clinicalStatus', 'statusLabel' => 'conditions.clinical_status', 'fields' => [
            'role' => 'patient-summary.diagnosis_role', 'rank' => 'patients.diagnosis_rank', 'verificationStatus' => 'conditions.verification_status',
            'bodySites' => 'conditions.body_sites', 'ehealthInsertedAt|insertedAt' => 'patient-summary.created_at',
            'asserter' => 'conditions.doctor', 'severity' => 'conditions.severity', 'onsetDate' => 'conditions.onset_date',
        ], 'ids' => ['condition' => 'conditions.label', 'context' => 'patient-summary.encounter_id']],
        'diagnosticReports' => ['title' => 'code', 'titleLabel' => 'medical-events.code_and_name', 'route' => ['diagnostic-report.view', 'diagnosticReport'], 'fields' => [
            'category|categories' => 'diagnostic-reports.category', '@diagnosticPerformers' => 'diagnostic-reports.performer',
            'ehealthInsertedAt' => 'diagnostic-reports.inserted_at', 'paperReferral.requisition' => 'patient-summary.referral_number',
            'conclusion' => 'diagnostic-reports.conclusion',
        ], 'ids' => ['uuid' => 'forms.ehealth_id', 'encounter' => 'patient-summary.encounter_id']],
        'devices' => ['title' => 'names.0.value|type', 'titleLabel' => 'devices.name', 'route' => ['devices.view', 'device'], 'fields' => [
            'modelNumber' => 'devices.model_number', 'type' => 'forms.type', 'recorder' => 'patient-summary.worker',
            'ehealthInsertedAt' => 'patient-summary.created_at', 'managingOrganization|performerLegalEntity' => 'patient-summary.organization',
            'manufacturer' => 'patient-summary.manufacturer', 'serialNumber' => 'patient-summary.serial_number',
            'lotNumber' => 'devices.lot_number', 'manufactureDate' => 'devices.manufacture_date',
            'expirationDate' => 'devices.expiration_date', 'note' => 'devices.notes', 'statusReason' => 'patients.status_change_reason',
            '@deviceProperties' => 'devices.properties',
        ], 'ids' => ['uuid' => 'patient-summary.device_id', 'context' => 'patient-summary.encounter_id', '@episode' => 'encounters.episode_id']],
        'procedures' => ['title' => 'code', 'titleLabel' => 'medical-events.code_and_name', 'route' => ['procedure.view', 'procedure'], 'fields' => [
            'category' => 'patient-summary.category', 'performedPeriod|performedDateTime' => 'patient-summary.performed',
            'performer' => 'medical-events.performer',
            'ehealthInsertedAt' => 'patient-summary.created_at', 'outcome' => 'patient-summary.result',
            'note' => 'procedures.note',
        ], 'ids' => ['uuid' => 'forms.ehealth_id', 'encounter|context' => 'patient-summary.encounter_id']],
    ];

    public function __construct(protected readonly array $dictionaries)
    {
    }

    public function collection(
        string $section,
        array $records,
        ?int $personId = null,
        ?int $prepersonId = null,
    ): array {
        if ($records === [] || !isset(self::SECTIONS[$section])) {
            return [];
        }

        $cards = [];
        $occurrences = [];
        foreach ($records as $index => $record) {
            $card = $this->card($section, $record, $personId, $prepersonId);
            $key = $card['key'];
            $occurrence = $occurrences[$key] ?? 0;
            $occurrences[$key] = $occurrence + 1;
            $card['key'] = $key.'-'.$occurrence;
            $cards[$index] = $card;
        }

        return $cards;
    }

    private function card(string $section, array $record, ?int $personId, ?int $prepersonId): array
    {
        $schema = self::SECTIONS[$section];
        $status = $this->get($record, $schema['status'] ?? 'status');
        $status = $status instanceof BackedEnum ? $status->value : $status;
        $enum = match ($section) {
            'episodes' => EpisodeStatus::class,
            'encounters' => EncounterStatus::class,
            'clinicalImpressions' => ClinicalImpressionStatus::class,
            'immunizations' => ImmunizationStatus::class,
            'observations' => ObservationStatus::class,
            'conditions', 'diagnoses' => ConditionClinicalStatus::class,
            'diagnosticReports' => DiagnosticReportStatus::class,
            'devices' => DeviceStatus::class,
            'procedures' => ProcedureStatus::class,
        };
        $badge = is_string($status) ? $enum::tryFrom($status) : null;
        $href = null;
        if (isset($schema['route'], $record['id']) && ctype_digit((string) $record['id']) && ($personId !== null || $prepersonId !== null)) {
            [$route, $parameter] = $schema['route'];
            $prefix = $prepersonId !== null ? 'prepersons.' : (in_array($section, ['diagnosticReports', 'procedures'], true) ? '' : 'persons.');
            $href = route($prefix.$route, [
                legalEntity(),
                $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId,
                $parameter => $record['id'],
            ]);
        }

        $uuid = $record['uuid'] ?? $this->referenceId($record['condition'] ?? null);
        $key = $section === 'diagnoses'
            ? md5(json_encode($record, JSON_THROW_ON_ERROR))
            : ($uuid ?? $record['id'] ?? md5(json_encode($record, JSON_THROW_ON_ERROR)));

        return [
            'key' => $section.'-'.$key,
            'title' => $this->title($record, $schema['title']),
            'titleLabel' => __($schema['titleLabel']),
            'status' => $badge?->label() ?? $this->format($status),
            'statusLabel' => __($schema['statusLabel'] ?? 'forms.status.label'),
            'statusClass' => $badge?->color() ?? 'badge-dark',
            'href' => $href,
            'fields' => $this->fields($record, $schema['fields']),
            'ids' => $this->fields($record, $schema['ids'], true),
            'groups' => [],
        ];
    }

    private function fields(array $record, array $paths, bool $identifiers = false): array
    {
        $fields = [];
        foreach ($paths as $path => $label) {
            $value = $this->resolve($record, $path);
            $fields[] = [
                'label' => __($label),
                'value' => $identifiers ? ($this->referenceId($value) ?? '—') : $this->formatField($value, $path),
            ];
        }

        return $fields;
    }

    private function formatField(mixed $value, string $paths): string
    {
        $dates = ['period.start', 'period.end', 'period', 'date', 'onsetDate', 'assertedDate', 'expirationDate', 'manufactureDate',
            'ehealthInsertedAt', 'insertedAt', 'ehealthUpdatedAt', 'updatedAt', 'effectiveDateTime', 'effectivePeriod',
            'effectivePeriod.start', 'effectivePeriod.end', 'performedPeriod', 'performedDateTime'];

        $parts = explode('|', $paths);
        if (array_diff($parts, $dates) === []) {
            return $this->formatDate($value);
        }
        if ($paths === 'verificationStatus') {
            return (is_string($value) ? ConditionVerificationStatus::tryFrom($value)?->label() : null) ?? $this->format($value);
        }
        if (array_diff($parts, ['category', 'categories', 'criticality', 'type']) === []) {
            return $this->formatCodeValue($value);
        }

        return $this->format($value);
    }

    private function formatDate(mixed $value): string
    {
        if (is_array($value) && (array_key_exists('start', $value) || array_key_exists('end', $value))) {
            return $this->formatDate($value['start'] ?? null).' — '.$this->formatDate($value['end'] ?? null);
        }
        if (is_string($value) && preg_match('/^(?:\\d{4}-\\d{2}-\\d{2}(?:$|[T ]\\d{2}:\\d{2})|\\d{2}\\.\\d{2}\\.\\d{4}(?:$| \\d{2}:\\d{2}))/', $value)) {
            try {
                return CarbonImmutable::parse($value, config('app.timezone'))->setTimezone(config('app.timezone'))->format(strlen($value) === 10 ? 'd.m.Y' : 'd.m.Y H:i');
            } catch (\Throwable) {
                return $value;
            }
        }

        return $this->format($value);
    }

    private function resolve(array $record, string $path): mixed
    {
        return match ($path) {
            '@source' => $this->get($record, 'reportOrigin')
                ?? (in_array($this->get($record, 'primarySource'), [true, 1, '1'], true) ? __('patient-summary.primary_source') : null),
            '@episode' => $this->get($record, 'episode|contextEpisodeId|contextEpisode') ?? $this->episodeReference($record),
            '@manufacturerLot' => implode(' / ', array_filter(
                [$this->get($record, 'manufacturer'), $this->get($record, 'lotNumber')],
                static fn ($value) => $value !== null && $value !== ''
            )),
            '@given' => $this->get($record, 'notGiven') !== null ? !$this->get($record, 'notGiven') : null,
            '@observationTitle' => implode(' | ', array_filter(
                [$this->format($this->get($record, 'categories')), $this->title($record, 'code')],
                static fn (string $value) => $value !== '—'
            )),
            '@observationValue' => $this->observationValue($record),
            '@deviceProperties' => collect($this->get($record, 'properties') ?? [])
                ->map(fn (array $property) => $this->format($property['code'] ?? null).': '.$this->typedValue($property['value'] ?? $property))->all(),
            '@diagnosticPerformers' => collect($this->get($record, 'performer') ?? [])
                ->map(fn ($performer) => $this->get($performer, 'reference|text'))->all(),
            default => $this->get($record, $path),
        };
    }

    private function episodeReference(array $record): mixed
    {
        foreach ($this->get($record, 'supportingInfo') ?? [] as $reference) {
            if ($this->get($reference, 'identifier.type.coding.0.code|identifier.type.0.coding.0.code') === 'episode_of_care') {
                return $reference;
            }
        }

        return null;
    }

    private function title(array $record, string $path): string
    {
        $concept = $this->get($record, $path);
        if ($path === 'code') {
            $code = $this->get($concept, 'coding.0.code');
            $label = $this->format($concept);

            return $code !== null && $label !== $code ? $code.' | '.$label : $label;
        }

        return $this->formatField($this->resolve($record, $path), $path);
    }

    private function get(mixed $record, string $paths): mixed
    {
        foreach (explode('|', $paths) as $path) {
            $value = $record;
            $segments = explode('.', $path);
            foreach ($segments as $index => $segment) {
                if ($segment === '*') {
                    $rest = implode('.', array_slice($segments, $index + 1));
                    $value = collect($value ?? [])
                        ->map(fn ($item) => $rest !== '' ? $this->get($item, $rest) : $item)
                        ->filter(fn ($item) => $item !== null)
                        ->values()->all();
                    break;
                }
                $value = is_array($value) ? ($value[$segment] ?? $value[Str::snake($segment)] ?? null) : null;
                if ($value === null) {
                    break;
                }
            }
            if ($value !== null && $value !== '' && $value !== []) {
                return $value;
            }
        }

        return null;
    }

    private function referenceId(mixed $value): ?string
    {
        $id = is_array($value) ? $this->get($value, 'identifier.value|value|uuid') : $value;

        return is_scalar($id) && $id !== '' ? (string) $id : null;
    }

    private function observationValue(array $record): string
    {
        $values = [$this->get($record, 'value'), ...collect($this->get($record, 'components') ?? [])->pluck('value')->all()];

        return $this->format(array_map($this->typedValue(...), $values));
    }

    private function typedValue(mixed $value): string
    {
        foreach (['valueBoolean', 'valueString', 'valueInteger', 'valueDateTime', 'valueQuantity', 'valueCodeableConcept', 'valueRange', 'valueRatio', 'valueSampledData'] as $type) {
            $typed = $this->get($value, $type);
            if ($typed !== null) {
                return match ($type) {
                    'valueSampledData' => $this->sampledData($typed),
                    'valueDateTime' => $this->formatDate($typed),
                    'valueBoolean' => $this->format((bool) $typed),
                    default => $this->format($typed),
                };
            }
        }

        return $this->format($value);
    }

    private function sampledData(array $value): string
    {
        $lines = [];
        foreach (['data', 'origin', 'period', 'dimensions', 'factor', 'lowerLimit', 'upperLimit'] as $field) {
            $parameter = $this->get($value, $field);
            if ($parameter !== null && $parameter !== '') {
                $lines[] = __('patient-summary.sampled_data.'.Str::snake($field)).': '.$this->format($parameter);
            }
        }

        return $lines === [] ? '—' : implode("\n", $lines);
    }

    private function format(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }
        if ($value instanceof BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : $this->format($value->value);
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone(config('app.timezone'))->format('d.m.Y H:i');
        }
        if (is_bool($value)) {
            return $value ? __('forms.yes') : __('forms.no');
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                $formatted = collect($value)->map(fn ($item) => $this->format($item))->filter(fn ($item) => $item !== '—')->implode("\n");

                return $formatted === '' ? '—' : $formatted;
            }
            if (isset($value['identifier'])) {
                $reference = $this->get($value, 'displayValue|identifier.value');

                return $reference === null || $reference === '' ? '—' : (string) $reference;
            }
            if (!empty($value['coding'])) {
                $labels = collect($value['coding'])->map(fn ($coding) => $coding['display']
                    ?? $this->dictionaries[$coding['system'] ?? ''][$coding['code'] ?? '']
                    ?? $value['text'] ?? $coding['code'] ?? '—');

                $formatted = $labels->filter(fn ($label) => $label !== '')->implode("\n");

                return $formatted === '' ? '—' : $formatted;
            }
            if (array_key_exists('value', $value)) {
                return implode(' ', array_filter(
                    [$value['comparator'] ?? null, $this->format($value['value']), $value['unit'] ?? $value['code'] ?? null],
                    static fn ($part) => $part !== null && $part !== ''
                ));
            }
            if (isset($value['code'], $value['system'])) {
                return (string) ($value['display'] ?? $this->dictionaries[$value['system']][$value['code']] ?? $value['code']);
            }
            if (array_key_exists('start', $value) || array_key_exists('end', $value)) {
                return $this->format($value['start'] ?? null).' — '.$this->format($value['end'] ?? null);
            }
            if (isset($value['low']) || isset($value['high'])) {
                return $this->format($value['low'] ?? null).' — '.$this->format($value['high'] ?? null);
            }
            if (isset($value['numerator'], $value['denominator'])) {
                return $this->format($value['numerator']).' / '.$this->format($value['denominator']);
            }

            $text = $this->get($value, 'displayValue|display|text');

            return $text === null || $text === '' ? '—' : (string) $text;
        }

        return (string) $value;
    }

    private function formatCodeValue(mixed $value): string
    {
        if (is_string($value)) {
            $key = 'patient-summary.values.'.str_replace('-', '_', $value);
            if (Lang::has($key)) {
                return __($key);
            }
        }

        return $this->format($value);
    }
}
