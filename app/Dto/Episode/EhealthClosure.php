<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Dto\FormCollection;
use App\Livewire\Episode\Forms\EpisodeClosingForm as InputForm;
use App\Mapping\Transforms\FhirCodeableConcept;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

#[Map(source: FormCollection::class, if: new SourceClass(FormCollection::class))]
#[Map(source: InputForm::class, if: new SourceClass(InputForm::class))]
final class EhealthClosure
{
    #[Map(source: '[closingDate]', if: new SourceClass(FormCollection::class), transform: [self::class, 'periodValue'])]
    #[Map(source: 'closingDate', if: new SourceClass(InputForm::class), transform: [self::class, 'periodValue'])]
    public array $period;

    #[Map(source: '[closingReason]', if: new SourceClass(FormCollection::class), transform: [self::class, 'reasonValue'])]
    #[Map(source: 'closingReason', if: new SourceClass(InputForm::class), transform: [self::class, 'reasonValue'])]
    public array $statusReason;

    #[Map(source: '[closingSummary]', if: new SourceClass(FormCollection::class), transform: [self::class, 'summaryValue'])]
    #[Map(source: 'closingSummary', if: new SourceClass(InputForm::class), transform: [self::class, 'summaryValue'])]
    public mixed $closingSummary;

    public function __construct(#[Map(if: false)] private readonly string $reasonText)
    {
    }

    public static function periodValue(string $value, object $source): array
    {
        $time = $source instanceof FormCollection ? $source['closingTime'] : $source->closingTime;

        return ['end' => convertToEHealthISO8601($value.' '.$time)];
    }

    public static function reasonValue(string $value, object $source, self $target): array
    {
        $concept = new FhirCodeableConcept('eHealth/episode_closing_reasons', includeText: true)($value, $source, null);
        $concept['text'] = $target->reasonText;

        return $concept;
    }

    public static function summaryValue(mixed $value): mixed
    {
        return $value ?: null;
    }

    public function toArray(): array
    {
        return ['period' => $this->period, 'status_reason' => $this->statusReason, 'closing_summary' => $this->closingSummary];
    }
}
