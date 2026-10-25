<?php

declare(strict_types=1);

namespace App\Dto\Fhir;

use App\Dto\FormCollection;
use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Contracts\Support\Arrayable;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: FormCollection::class, transform: [self::class, 'mapSnapshot'])]
final class Coding implements Arrayable
{
    use ResourceData;
    #[Map(source: '[system?]', if: new SourceHasPath('system'))]
    public mixed $system;

    #[Map(source: '[code?]', if: new SourceHasPath('code'))]
    public mixed $code;

    #[Map(source: '[display?]', if: new SourceHasPath('display'))]
    public mixed $display;

    #[Map(source: '[version?]', if: new SourceHasPath('version'))]
    public mixed $version;

    #[Map(source: '[userSelected?]', if: new SourceHasPath('userSelected'))]
    public mixed $userSelected;
}
