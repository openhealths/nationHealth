<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Dto\FormCollection;
use App\Livewire\Episode\Forms\EpisodeCancellationForm as InputForm;
use App\Mapping\Transforms\FhirCodeableConcept;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

#[Map(source: FormCollection::class, if: new SourceClass(FormCollection::class))]
#[Map(source: InputForm::class, if: new SourceClass(InputForm::class))]
final class EhealthCancellation
{
    #[Map(source: '[cancellationReason]', if: new SourceClass(FormCollection::class), transform: [self::class, 'reasonValue'])]
    #[Map(source: 'cancellationReason', if: new SourceClass(InputForm::class), transform: [self::class, 'reasonValue'])]
    public array $statusReason;

    #[Map(source: '[explanatoryLetter]', if: new SourceClass(FormCollection::class), transform: [self::class, 'letterValue'])]
    #[Map(source: 'explanatoryLetter', if: new SourceClass(InputForm::class), transform: [self::class, 'letterValue'])]
    public mixed $explanatoryLetter;

    public function __construct(#[Map(if: false)] private readonly string $reasonText)
    {
    }

    public static function reasonValue(string $value, object $source, self $target): array
    {
        $concept = new FhirCodeableConcept('eHealth/cancellation_reasons', includeText: true)($value, $source, null);
        $concept['text'] = $target->reasonText;

        return $concept;
    }

    public static function letterValue(mixed $value): mixed
    {
        return $value ?: null;
    }

    public function toArray(): array
    {
        return ['status_reason' => $this->statusReason, 'explanatory_letter' => $this->explanatoryLetter];
    }
}
