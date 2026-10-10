<?php

declare(strict_types=1);

namespace App\Dto\Encounter;

use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthCancelledRecord
{
    #[Map(source: '[document]')]
    public array $document;

    #[Map(source: '[cancel]')]
    public bool $cancel;

    #[Map(source: '[statusField]')]
    public string $statusField;

    #[Map(source: '[status]')]
    public string $status;

    #[Map(source: '[explanatoryLetter]')]
    public string $explanatoryLetter;

    public function toArray(): array
    {
        return $this->cancel ? [...$this->document, $this->statusField => $this->status, 'explanatoryLetter' => $this->explanatoryLetter] : $this->document;
    }
}
