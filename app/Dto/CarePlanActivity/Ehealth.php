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
final class Ehealth implements Arrayable
{
    use CreateData;
    #[Map(source: '[id?]', if: new SourceHasPath('id'))]
    public mixed $id;

    #[Map(source: '[author?]', if: new SourceHasPath('author'), transform: [self::class, 'mapReferences'])]
    public mixed $author;

    #[Map(source: '[carePlan?]', if: new SourceHasPath('carePlan'))]
    public mixed $carePlan;

    #[Map(source: '[detail?]', if: new SourceHasPath('detail'), transform: [self::class, 'mapDetail'])]
    public mixed $detail;
    public static function mapDetail(mixed $value): EhealthDetail
    {
        return EhealthDetail::fromSource(new FormCollection($value));
    }

    protected function trailingFields(): array
    {
        return ['detail'];
    }
}
