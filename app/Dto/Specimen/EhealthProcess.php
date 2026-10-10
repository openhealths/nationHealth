<?php

declare(strict_types=1);

namespace App\Dto\Specimen;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Livewire\Specimen\Forms\SpecimenActionForm;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

#[Map(source: Collection::class, if: new SourceClass(Collection::class))]
#[Map(source: SpecimenActionForm::class, if: new SourceClass(SpecimenActionForm::class))]
final class EhealthProcess
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[receivedDate]', if: new SourceClass(Collection::class), transform: [self::class, 'receivedTime'])]
    #[Map(source: 'receivedDate', if: new SourceClass(SpecimenActionForm::class), transform: [self::class, 'receivedTime'])]
    public string $receivedTime;

    public static function receivedTime(string $value, Collection|SpecimenActionForm $source): string
    {
        return convertToEHealthISO8601($value.' '.($source instanceof SpecimenActionForm ? $source->receivedTime : $source['receivedTime']));
    }
}
