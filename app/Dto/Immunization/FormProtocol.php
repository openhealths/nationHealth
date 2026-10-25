<?php

declare(strict_types=1);

namespace App\Dto\Immunization;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
final class FormProtocol
{
    #[Map(source: '[authority?][coding?][0?][code?]', if: new SourceHasPath('authority.coding.0.code'))]
    public mixed $authorityCode = '';

    #[Map(source: '[targetDiseases?]', transform: [[self::class, 'rows'], new MapCollection(targetClass: \App\Dto\Shared\FormConceptCode::class)])]
    public array $targetDiseaseCodes;

    #[Map(source: '[doseSequence?]', if: new SourceHasPath('doseSequence'))]
    public mixed $doseSequence = '';

    #[Map(source: '[series?]', if: new SourceHasPath('series'))]
    public mixed $series = '';

    #[Map(source: '[seriesDoses?]', if: new SourceHasPath('seriesDoses'))]
    public mixed $seriesDoses = '';

    #[Map(source: '[description?]', if: new SourceHasPath('description'))]
    public mixed $description = '';

    public static function rows(?array $value): array
    {
        return array_map(static fn (array $row): Collection => new Collection($row), $value ?? []);
    }

    public function toArray(): array
    {
        $data = get_object_vars($this);
        $data['targetDiseaseCodes'] = array_values(array_filter(array_map(static fn (object $row): mixed => $row->code, $this->targetDiseaseCodes))) ?: [''];

        return $data;
    }
}
