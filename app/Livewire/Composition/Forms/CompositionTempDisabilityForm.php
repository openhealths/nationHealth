<?php

declare(strict_types=1);

namespace App\Livewire\Composition\Forms;

use App\Core\BaseForm;
use App\Enums\Person\CompositionCategory;
use App\Enums\Person\CompositionType;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * Input for a temporary disability conclusion (МВТН).
 *
 * Mandatory fields follow TV 3.8.2.5.2 and optional ones TV 3.8.2.5.3. The form holds
 * only user input; turning it into a createComposition body is
 * {@see \App\Services\MedicalEvents\Mappers\CompositionMapper::tempDisability()}, which
 * keeps the FHIR shape in one tested place instead of inside a Livewire form object.
 */
class CompositionTempDisabilityForm extends BaseForm
{
    /** Fixed — this form only ever produces a МВТН. */
    public string $type = CompositionType::TEMP_DISABILITY->value;

    /** Code from COMPOSITION_CATEGORIES, limited to the disability categories. */
    public string $category = '';

    /** Person UUID, or preperson UUID when the patient is unidentified. */
    public string $subjectUuid = '';

    public bool $isUnidentified = false;

    public string $encounterUuid = '';

    /**
     * The incapacitated person (section.focus).
     *
     * Usually the patient themselves, but they differ when the conclusion is issued for
     * caring for someone else, which is why it is captured separately.
     */
    public string $sectionFocusUuid = '';

    /** Start of the incapacity period, as `Y-m-d`. */
    public string $eventPeriodStart = '';

    /** End of the incapacity period, as `Y-m-d`. */
    public string $eventPeriodEnd = '';

    /** Chosen authentication method (INFORM_WITH); may be left unset per TV 3.8.2.4.4. */
    public ?string $informWithUuid = null;

    public bool $isAccident = false;

    public bool $isIntoxicated = false;

    public bool $isForeignTreatment = false;

    public bool $isForceRenew = false;

    /** Code from COMPOSITION_TREATMENT_VIOLATION. */
    public ?string $treatmentViolation = null;

    public ?string $treatmentViolationDate = null;

    /** Previous conclusion this one refines or replaces (TV 3.8.2.5.2, 3.8.2.12). */
    public ?string $relatesToTargetUuid = null;

    /** Relation type: 'appends' for continuation, 'replaces' for refinement. */
    public ?string $relatesToCode = null;

    /**
     * @param  array<string, string>  $allowedCategories  Options offered to the user.
     * @param  list<string>  $allowedTreatmentViolations
     */
    public function compositionRules(array $allowedCategories = [], array $allowedTreatmentViolations = []): array
    {
        return [
            'type' => ['required', Rule::in([CompositionType::TEMP_DISABILITY->value])],
            'category' => [
                'required',
                'string',
                Rule::in($allowedCategories === []
                    ? array_map(
                        static fn (CompositionCategory $category) => $category->value,
                        CompositionCategory::forType(CompositionType::TEMP_DISABILITY)
                    )
                    : array_keys($allowedCategories)),
            ],
            'subjectUuid' => ['required', 'uuid'],
            'encounterUuid' => ['required', 'uuid'],
            'sectionFocusUuid' => ['required', 'uuid'],

            // The period is a pair of calendar dates; the fixed times are added by the mapper.
            'eventPeriodStart' => ['required', 'date_format:Y-m-d'],
            'eventPeriodEnd' => ['required', 'date_format:Y-m-d', 'after_or_equal:eventPeriodStart'],

            'informWithUuid' => ['nullable', 'uuid'],
            'isAccident' => ['boolean'],
            'isIntoxicated' => ['boolean'],
            'isForeignTreatment' => ['boolean'],
            'isForceRenew' => ['boolean'],
            'treatmentViolation' => array_filter([
                'nullable',
                'string',
                $allowedTreatmentViolations === [] ? null : Rule::in($allowedTreatmentViolations),
            ]),

            // TV 3.8.2.5.3 ties the violation date to the incapacity period it falls in.
            'treatmentViolationDate' => [
                'nullable',
                'required_with:treatmentViolation',
                'date_format:Y-m-d',
                'after_or_equal:eventPeriodStart',
            ],
            'relatesToTargetUuid' => ['nullable', 'uuid'],
            'relatesToCode' => ['nullable', 'string'],
        ];
    }

    protected function rules(): array
    {
        return $this->compositionRules();
    }

    /**
     * Values the mapper needs, in its own vocabulary.
     *
     * @return array<string, mixed>
     */
    public function toMapperData(): array
    {
        return [
            'category' => $this->category,
            'subjectUuid' => $this->subjectUuid,
            'isUnidentified' => $this->isUnidentified,
            'encounterUuid' => $this->encounterUuid,
            'sectionFocusUuid' => $this->sectionFocusUuid,
            'eventPeriodStart' => $this->eventPeriodStart,
            'eventPeriodEnd' => $this->eventPeriodEnd,
            'informWithUuid' => $this->informWithUuid,
            'isAccident' => $this->isAccident,
            'isIntoxicated' => $this->isIntoxicated,
            'isForeignTreatment' => $this->isForeignTreatment,
            'isForceRenew' => $this->isForceRenew,
            'treatmentViolation' => $this->treatmentViolation,
            'treatmentViolationDate' => $this->treatmentViolationDate,
            'relatesToTargetUuid' => $this->relatesToTargetUuid,
            'relatesToCode' => $this->relatesToCode,
        ];
    }

    /**
     * Carry period and flags over from the conclusion being refined (TV 3.8.2.13).
     *
     * Reads a stored getComposition response, where the period lives inside the `event`
     * list and extensions are a flat list of `{valueCode, value<Type>}` pairs.
     *
     * @param  array<string, mixed>  $previous
     */
    public function prefillFromPrevious(array $previous): void
    {
        $this->eventPeriodStart = $this->asDate(data_get($previous, 'event.0.period.start'));
        $this->eventPeriodEnd = $this->asDate(data_get($previous, 'event.0.period.end'));

        $extensions = collect(data_get($previous, 'extension', []))
            ->filter(static fn ($extension) => is_array($extension) && isset($extension['valueCode']))
            ->mapWithKeys(static fn (array $extension) => [
                $extension['valueCode'] => $extension['valueBoolean']
                    ?? $extension['valueString']
                    ?? $extension['valueDate']
                    ?? null,
            ]);

        $this->isAccident = (bool) $extensions->get('IS_ACCIDENT', false);
        $this->isIntoxicated = (bool) $extensions->get('IS_INTOXICATED', false);
        $this->isForeignTreatment = (bool) $extensions->get('IS_FOREIGN_TREATMENT', false);
        $this->isForceRenew = (bool) $extensions->get('IS_FORCE_RENEW', false);
        $this->treatmentViolation = $extensions->get('TREATMENT_VIOLATION');
        $this->treatmentViolationDate = $this->asDate($extensions->get('TREATMENT_VIOLATION_DATE')) ?: null;

        $this->relatesToTargetUuid = data_get($previous, 'identifier.value');
        $this->relatesToCode = 'replaces';
    }

    /**
     * Continue a previous disability case: start the day after it ended (TV 3.8.2.5.4).
     *
     * Flags and the relation are inherited; the end date is left empty so the author
     * picks a fresh allowed period rather than silently extending the old one.
     *
     * @param  array<string, mixed>  $previous
     */
    public function prefillForContinuation(array $previous): void
    {
        $this->prefillFromPrevious($previous);

        $ended = $this->asDate(data_get($previous, 'event.0.period.end'));
        $this->eventPeriodStart = $ended
            ? CarbonImmutable::parse($ended)->addDay()->format('Y-m-d')
            : '';
        $this->eventPeriodEnd = '';
        $this->relatesToCode = 'appends';
    }

    public function resetCompositionFields(): void
    {
        $this->category = '';
        $this->subjectUuid = '';
        $this->isUnidentified = false;
        $this->encounterUuid = '';
        $this->sectionFocusUuid = '';
        $this->eventPeriodStart = '';
        $this->eventPeriodEnd = '';
        $this->informWithUuid = null;
        $this->isAccident = false;
        $this->isIntoxicated = false;
        $this->isForeignTreatment = false;
        $this->isForceRenew = false;
        $this->treatmentViolation = null;
        $this->treatmentViolationDate = null;
        $this->relatesToTargetUuid = null;
        $this->relatesToCode = null;
    }

    private function asDate(mixed $value): string
    {
        return $value ? CarbonImmutable::parse((string) $value)->format('Y-m-d') : '';
    }
}
