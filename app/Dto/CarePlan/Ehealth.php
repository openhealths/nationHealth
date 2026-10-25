<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Dto\Fhir\CreateData;
use App\Dto\FormCollection;
use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Contracts\Support\Arrayable;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Endpoint create allowlist; server metadata and full persistence belong to Model/Repository. */
#[Map(source: FormCollection::class)]
final class Ehealth implements Arrayable
{
    use CreateData;
    #[Map(source: '[id?]', if: new SourceHasPath('id'))]
    public mixed $id;

    #[Map(source: '[category?]', if: new SourceHasPath('category'))]
    public mixed $category;

    #[Map(source: '[title?]', if: new SourceHasPath('title'))]
    public mixed $title;

    #[Map(source: '[description?]', if: new SourceHasPath('description'))]
    public mixed $description;

    #[Map(source: '[period?]', if: new SourceHasPath('period'))]
    public mixed $period;

    #[Map(source: '[supportingInfo?]', if: new SourceHasPath('supportingInfo'), transform: [self::class, 'mapReferences'])]
    public mixed $supportingInfo;

    #[Map(source: '[note?]', if: new SourceHasPath('note'))]
    public mixed $note;

    #[Map(source: '[encounter?]', if: new SourceHasPath('encounter'))]
    public mixed $encounter;

    #[Map(source: '[addresses?]', if: new SourceHasPath('addresses'), transform: [self::class, 'mapConcepts'])]
    public mixed $addresses;

    #[Map(source: '[author?]', if: new SourceHasPath('author'))]
    public mixed $author;

    #[Map(source: '[contributor?]', if: new SourceHasPath('contributor'), transform: [self::class, 'mapReferences'])]
    public mixed $contributor;

    #[Map(source: '[termsOfService?]', if: new SourceHasPath('termsOfService'))]
    public mixed $termsOfService;

    #[Map(source: '[informWith?]', if: new SourceHasPath('informWith'))]
    public mixed $informWith;

    #[Map(if: false)]
    public string $intent = 'order';

    #[Map(if: false)]
    public string $status = 'new';
}
