<?php

declare(strict_types=1);

namespace App\Dto\CarePlanRequest;

use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Models\CarePlanRequest;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\TargetClass;

#[Map(source: CarePlanForm::class, target: CarePlanRequest::class)]
class Model
{
    #[Map]
    public string $title;

    #[Map]
    public string $category;

    #[Map]
    public string $termsOfService;

    #[Map]
    public string $periodStart;

    #[Map]
    public string $periodEnd;

    #[Map]
    public string $periodStartTime;

    #[Map]
    public string $periodEndTime;

    #[Map]
    public string $encounter;

    #[Map]
    public string $context;

    #[Map]
    public string $description;

    #[Map]
    public string $note;

    #[Map]
    public string $informWith;

    #[Map]
    public array $coAuthors;

    #[Map]
    public array $episodes;

    #[Map]
    public array $medicalRecords;

    // The editor supplies these from its patient and author context.
    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public ?string $authorUuid;

    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public ?string $conditionUuid;

    #[Map(if: new TargetClass(CarePlanRequest::class))]
    public array $addresses;
}
