<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
class Model
{
    #[Map(source: '[id]')]
    public string $id;

    #[Map(source: '[status]')]
    public string $status;

    #[Map(source: '[title]')]
    public string $title;

    #[Map(source: '[period]')]
    public array $period;

    #[Map(source: '[intent]')]
    public string $intent;

    #[Map(source: '[category]')]
    public array $category;

    #[Map(source: '[addresses]')]
    public array $addresses;

    #[Map(source: '[encounter]')]
    public array $encounter;

    #[Map(source: '[author]')]
    public array $author;

    #[Map(source: '[termsOfService]')]
    public array $termsOfService;

    #[Map(source: '[subject]')]
    public array $subject;

    #[Map(source: '[managingOrganization]')]
    public array $managingOrganization;

    #[Map(source: '[requisition]')]
    public string $requisition;

    #[Map(source: '[statusReason?]')]
    public ?array $statusReason = null;

    #[Map(source: '[basedOn?]')]
    public ?array $basedOn = null;

    #[Map(source: '[partOf?]')]
    public ?array $partOf = null;

    #[Map(source: '[careManager?]')]
    public ?array $careManager = null;

    #[Map(source: '[contributor?]')]
    public ?array $contributor = null;

    #[Map(source: '[supportingInfo?]')]
    public ?array $supportingInfo = null;

    #[Map(source: '[context?]')]
    public ?string $context = null;

    #[Map(source: '[description?]')]
    public ?string $description = null;

    #[Map(source: '[note?]')]
    public ?string $note = null;

    #[Map(source: '[informWith?]')]
    public ?array $informWith = null;
}
