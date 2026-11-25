<?php

declare(strict_types=1);

namespace App\Dto\CarePlanRequest;

use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Models\CarePlanRequest;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\TargetClass;

/**
 * Project a validated CarePlanForm onto the local CarePlanRequest attributes.
 * The editor supplies the author and encounter-derived fields between the two mappings.
 * Each persisted property has a mapping because Eloquent attributes are dynamic.
 */
#[Map(source: CarePlanForm::class)]
#[Map(target: CarePlanRequest::class)]
class Model
{
    /** Care plan title from the editor. */
    #[Map]
    public string $title;

    /** Configured category code selected for the encounter. */
    #[Map]
    public string $category;

    /** Terms-of-service code derived from the encounter class. */
    #[Map]
    public string $termsOfService;

    /** Start date in the editor's date format, normalized by the model cast. */
    #[Map]
    public string $periodStart;

    /** Optional end date in the editor's date format, stored as null when empty. */
    #[Map]
    public string $periodEnd = '';

    /** Local start time in hours and minutes. */
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $periodStartTime = '';

    /** Optional local end time in hours and minutes. */
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $periodEndTime = '';

    /** eHealth UUID of the selected encounter. */
    #[Map]
    public string $encounter;

    /** Optional encounter context code reserved for the extended editor. */
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $context = '';

    /** Free-text treatment description. */
    #[Map]
    public string $description = '';

    /** Notes entered by the clinician. */
    #[Map]
    public string $note = '';

    /** Selected patient notification method identifier, if supplied. */
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public string $informWith = '';

    /**
     * eHealth UUIDs of the selected co-authors.
     *
     * @var list<string>
     */
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public array $coAuthors = [];

    /**
     * Supporting episodes derived from the current encounter.
     *
     * @var list<array{uuid?: string|null, name?: string|null, date?: string|null}>
     */
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public array $episodes = [];

    /**
     * Supporting medical records with the labels shown in the editor.
     *
     * @var list<array{uuid?: string|null, name?: string|null, date?: string|null}>
     */
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
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public array $addresses = [];
}
