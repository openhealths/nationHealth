<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Dto\CodeableConcept\Ehealth as CodeableConceptEhealth;
use App\Dto\EhealthMapping;
use App\Dto\Transform\Ehealth\DictionaryConcept;
use App\Dto\Transform\LocalDateTime;
use App\Livewire\Episode\Forms\EpisodeClosingForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Episode close payload in the format expected by the eHealth API: the moment, the reason and the summary.
 * Properties without #[Map] are taken from the form property with the same name.
 */
#[Map(source: EpisodeClosingForm::class)]
class EhealthClose
{
    use EhealthMapping;

    /** @var array{end: string} */
    #[Map(source: 'closingDate', transform: [self::class, 'period'])]
    public array $period;

    #[Map(source: 'closingReason', transform: new DictionaryConcept('eHealth/episode_closing_reasons'))]
    public CodeableConceptEhealth $statusReason;

    public ?string $closingSummary = null;

    /**
     * Closing date and time from the form as the period end, an ISO 8601 date-time in UTC.
     * The time is read from the form, since a transform receives the source along with the mapped value.
     *
     * @param  string  $closingDate
     * @param  EpisodeClosingForm  $form
     * @return array{end: string}
     */
    public static function period(string $closingDate, EpisodeClosingForm $form): array
    {
        return ['end' => LocalDateTime::toUtc($closingDate, $form->closingTime)];
    }
}
