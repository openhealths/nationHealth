<?php

declare(strict_types=1);

namespace App\Dto\CarePlanRequest;

use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Models\CarePlanRequest;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

#[Map(source: CarePlanForm::class)]
#[Map(source: CarePlanRequest::class)]
#[Map(target: CarePlanRequest::class)]
#[Map(target: CarePlanForm::class)]
class Model
{
    // Assigned by the component after resolving the patient encounter and current employee.
    public string $uuid;
    public ?string $conditionUuid = null;
    public ?string $authorUuid = null;
    public array $addresses = [];
    public ?int $version = null;

    #[Map]
    public string $title;

    #[Map]
    public string $category;

    #[Map]
    public string $context;

    #[Map]
    public string $termsOfService;

    #[Map]
    public string $periodStart;

    #[Map]
    public string $periodStartTime;

    #[Map(source: '[periodEnd?]', if: new SourceClass(CarePlanRequest::class), transform: [new self(), 'dateText'])]
    #[Map(if: new SourceClass(CarePlanForm::class))]
    #[Map(if: new SourceClass(self::class))]
    public string $periodEnd = '';

    #[Map]
    public string $periodEndTime;

    #[Map]
    public string $encounter;

    #[Map]
    public array $coAuthors;

    #[Map]
    public array $episodes;

    #[Map]
    public array $medicalRecords;

    #[Map]
    public string $description;

    #[Map]
    public string $note;

    #[Map]
    public string $informWith;

    public function dateText(?string $value): string
    {
        return $value ?? '';
    }
}
