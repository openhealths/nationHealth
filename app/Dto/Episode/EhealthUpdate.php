<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\FormCollection;
use App\Livewire\Episode\Forms\EpisodeForm as InputForm;
use App\Mapping\Transforms\FhirReference;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

#[Map(source: FormCollection::class, if: new SourceClass(FormCollection::class))]
#[Map(source: InputForm::class, if: new SourceClass(InputForm::class))]
final class EhealthUpdate
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[name]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'name', if: new SourceClass(InputForm::class))]
    public mixed $name;

    #[Map(source: '[careManagerId]', if: new SourceClass(FormCollection::class), transform: new FhirReference('employee', includeText: true))]
    #[Map(source: 'careManagerId', if: new SourceClass(InputForm::class), transform: new FhirReference('employee', includeText: true))]
    public array $careManager;

}
