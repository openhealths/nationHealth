<?php

declare(strict_types=1);

namespace App\Dto\CarePlanRequest;

use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Models\CarePlanRequest;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Condition\TargetClass;

/**
 * Project the validated CarePlanForm and read back a stored request for signing.
 * The editor supplies the author and encounter-derived fields between the two mappings.
 * Each persisted property has a mapping because Eloquent attributes are dynamic.
 */
class Model
{
    /** Care plan title from the editor. */
    #[Map(if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[title]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $title;

    /** Configured category code selected for the encounter. */
    #[Map(if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[category]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $category;

    /** Terms-of-service code derived from the encounter class. */
    #[Map(if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[terms_of_service]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $termsOfService;

    /** Start date in the editor's date format, normalized by the model cast. */
    #[Map(if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[period_start]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $periodStart;

    /** Optional end date in the editor's date format, stored as null when empty. */
    #[Map(if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[period_end]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public ?string $periodEnd = null;

    /** Local start time in hours and minutes. */
    #[Map(source: '[period_start_time]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $periodStartTime = '';

    /** Optional local end time in hours and minutes. */
    #[Map(source: '[period_end_time]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $periodEndTime = '';

    /** eHealth UUID of the selected encounter. */
    #[Map(if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[encounter]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $encounter;

    /** Optional encounter context code selected in the editor. */
    #[Map(if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[context]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $context = '';

    /** Free-text treatment description. */
    #[Map(if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[description]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $description = '';

    /** Notes entered by the clinician. */
    #[Map(if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[note]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $note = '';

    /** Selected patient notification method identifier, if supplied. */
    #[Map(source: '[inform_with]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $informWith = '';

    /**
     * eHealth UUIDs of the selected co-authors.
     *
     * @var list<string>
     */
    #[Map(source: '[co_authors]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public array $coAuthors = [];

    /**
     * Supporting episodes derived from the current encounter.
     *
     * @var list<array{uuid?: string|null, name?: string|null, date?: string|null}>
     */
    #[Map(source: '[episodes]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public array $episodes = [];

    /**
     * Supporting records resolved and authorized before saving.
     *
     * @var list<array{type: string, uuid: string, name: string}>
     */
    #[Map(source: '[medical_records]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public array $medicalRecords = [];

    /** eHealth UUID of the employee selected by the facility and terms of service. */
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public ?string $authorUuid = null;

    /** Condition UUID preselected by the entry point, when present. */
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public ?string $conditionUuid = null;

    /**
     * Diagnosis concepts derived from the selected encounter.
     *
     * @var list<array<string, mixed>>
     */
    #[Map(source: '[addresses]', if: new SourceClass(CarePlanRequest::class))]
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public array $addresses = [];

    /**
     * Expose the stored form fields to the existing Care Plan FHIR mapper, which accepts arrays.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
