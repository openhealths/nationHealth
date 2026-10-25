<?php

declare(strict_types=1);

namespace App\Dto\CarePlanActivity;

use App\Dto\Fhir\CreateData;
use App\Dto\FormCollection;
use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Contracts\Support\Arrayable;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Endpoint create allowlist; server metadata and full persistence belong to Model/Repository. */
#[Map(source: FormCollection::class)]
final class EhealthDetail implements Arrayable
{
    use CreateData;
    #[Map(source: '[kind?]', if: new SourceHasPath('kind'))]
    public mixed $kind;

    #[Map(source: '[productReference?]', if: new SourceHasPath('productReference'))]
    public mixed $productReference;

    #[Map(source: '[productCodeableConcept?]', if: new SourceHasPath('productCodeableConcept'))]
    public mixed $productCodeableConcept;

    #[Map(source: '[reasonCode?]', if: new SourceHasPath('reasonCode'), transform: [self::class, 'mapConcepts'])]
    public mixed $reasonCode;

    #[Map(source: '[reasonReference?]', if: new SourceHasPath('reasonReference'), transform: [self::class, 'mapReferences'])]
    public mixed $reasonReference;

    #[Map(source: '[goal?]', if: new SourceHasPath('goal'), transform: [self::class, 'mapConcepts'])]
    public mixed $goal;

    #[Map(source: '[quantity?]', if: new SourceHasPath('quantity'))]
    public mixed $quantity;

    #[Map(source: '[location?]', if: new SourceHasPath('location'))]
    public mixed $location;

    #[Map(source: '[performer?]', if: new SourceHasPath('performer'))]
    public mixed $performer;

    #[Map(source: '[dailyAmount?]', if: new SourceHasPath('dailyAmount'))]
    public mixed $dailyAmount;

    #[Map(source: '[scheduledPeriod?]', if: new SourceHasPath('scheduledPeriod'))]
    public mixed $scheduledPeriod;

    #[Map(source: '[scheduledTiming?]', if: new SourceHasPath('scheduledTiming'))]
    public mixed $scheduledTiming;

    #[Map(source: '[scheduledString?]', if: new SourceHasPath('scheduledString'))]
    public mixed $scheduledString;

    #[Map(source: '[description?]', if: new SourceHasPath('description'))]
    public mixed $description;

    #[Map(source: '[program?]', if: new SourceHasPath('program'))]
    public mixed $program;

    #[Map(source: '[doNotPerform?]', if: new SourceHasPath('doNotPerform'))]
    public mixed $doNotPerform;

    #[Map(if: false)]
    public string $status = 'scheduled';
}
