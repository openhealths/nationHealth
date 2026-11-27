<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Dto\CodeableConcept\Ehealth as CodeableConceptEhealth;
use App\Dto\EhealthMapping;
use App\Dto\Transform\Ehealth\DictionaryConcept;
use App\Livewire\Episode\Forms\EpisodeCancellationForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Episode cancel payload in the format expected by the eHealth API: the reason and the explanation behind it.
 * Properties without #[Map] are taken from the form property with the same name.
 */
#[Map(source: EpisodeCancellationForm::class)]
class EhealthCancel
{
    use EhealthMapping;

    #[Map(source: 'cancellationReason', transform: new DictionaryConcept('eHealth/cancellation_reasons'))]
    public CodeableConceptEhealth $statusReason;

    public ?string $explanatoryLetter = null;
}
