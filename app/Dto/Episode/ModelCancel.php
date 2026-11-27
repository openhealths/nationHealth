<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Dto\CodeableConcept\Model as CodeableConceptModel;
use App\Models\EhealthJob;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Episode cancellation eHealth processed, mapped to database attributes: the reason and the explanation behind it.
 * Read from the request stored with the job, since the job is finished in the queue, where the form is gone.
 */
#[Map(source: EhealthJob::class)]
class ModelCancel
{
    #[Map(source: '[request_data][status_reason]', transform: [CodeableConceptModel::class, 'map'])]
    public CodeableConceptModel $statusReason;

    #[Map(source: '[request_data][explanatory_letter]')]
    public ?string $explanatoryLetter = null;
}
