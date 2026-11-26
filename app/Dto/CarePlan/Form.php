<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Livewire\CarePlan\Forms\CarePlanForm;
use ArrayObject;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\IsNotNull;

/** Map the local FHIR projection into the editor's existing Livewire form. */
#[Map(source: ArrayObject::class, target: CarePlanForm::class)]
class Form
{
    #[Map(source: '[title?]', if: new IsNotNull())]
    public string $title = '';

    #[Map(source: '[intent?]', if: new IsNotNull())]
    public string $intent = 'order';

    #[Map(source: '[context?]', if: new IsNotNull())]
    public string $context = '';

    #[Map(source: '[description?]', if: new IsNotNull())]
    public string $description = '';

    #[Map(source: '[note?]', if: new IsNotNull())]
    public string $note = '';

    #[Map(source: '[informWith?]', if: new IsNotNull())]
    public string $informWith = '';

    #[Map(source: '[author]', if: new IsNotNull())]
    public string $author = '';

    #[Map(source: '[encounter]', if: new IsNotNull())]
    public string $encounter = '';

    #[Map(source: '[category]', if: new IsNotNull())]
    public string $category = '';

    #[Map(source: '[termsOfService]', if: new IsNotNull())]
    public string $termsOfService = '';

    #[Map(source: '[periodStart]')]
    public string $periodStart;

    #[Map(source: '[periodStartTime]')]
    public string $periodStartTime;

    #[Map(source: '[periodEnd]')]
    public string $periodEnd;

    #[Map(source: '[periodEndTime]')]
    public string $periodEndTime;

    #[Map(source: '[coAuthors]')]
    public array $coAuthors;

    #[Map(source: '[episodes]')]
    public array $episodes;

    #[Map(source: '[medicalRecords]')]
    public array $medicalRecords;
}
