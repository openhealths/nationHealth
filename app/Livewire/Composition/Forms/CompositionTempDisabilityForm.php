<?php

declare(strict_types=1);

namespace App\Livewire\Composition\Forms;

use App\Core\BaseForm;
use App\Dto\Composition\Ehealth as CompositionData;
use App\Dto\Composition\Form as CompositionFormData;
use App\Dto\FormCollection;
use App\Enums\Composition\CompositionRelation;
use App\Enums\Composition\CompositionCategory;
use App\Enums\Composition\CompositionType;
use Illuminate\Validation\Rule;

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

    /** Start of the incapacity period, as `d.m.Y` (app date format). */
    public string $eventPeriodStart = '';

    /** End of the incapacity period, as `d.m.Y` (app date format). */
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

            // UI uses the application date format; toPayload preserves the calendar date.
            'eventPeriodStart' => ['required', 'date_format:'.config('app.date_format')],
            'eventPeriodEnd' => [
                'required',
                'date_format:'.config('app.date_format'),
                'after_or_equal:eventPeriodStart',
            ],

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

            // TV 3.8.2.5.3 ties the violation date to the incapacity period it falls in;
            // eHealth rule 1172 also rejects dates after "now".
            'treatmentViolationDate' => [
                'nullable',
                'required_with:treatmentViolation',
                'date_format:'.config('app.date_format'),
                'after_or_equal:eventPeriodStart',
                'before_or_equal:'.now()->format(config('app.date_format')),
            ],
            'relatesToTargetUuid' => ['nullable', 'uuid'],
            'relatesToCode' => ['nullable', Rule::enum(CompositionRelation::class)],
        ];
    }

    protected function rules(): array
    {
        return $this->compositionRules();
    }

    /**
     * Build the unsigned createComposition request.
     *
     * @return array<string, mixed>
     */
    public function toPayload(string $authorEmployeeUuid): array
    {
        return CompositionData::fromForm($this, $authorEmployeeUuid)->toArray();
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
        $this->fill(CompositionFormData::fromSource(
            new FormCollection($previous),
            $this->subjectUuid,
            $this->sectionFocusUuid,
            CompositionRelation::REPLACES,
        )->toArray());
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
        $this->fill(CompositionFormData::fromSource(
            new FormCollection($previous),
            $this->subjectUuid,
            $this->sectionFocusUuid,
            CompositionRelation::APPENDS,
        )->toArray());
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

}
