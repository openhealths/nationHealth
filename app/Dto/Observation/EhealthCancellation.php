<?php

declare(strict_types=1);

namespace App\Dto\Observation;

use App\Core\Arr;
use App\Enums\Person\ObservationStatus;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthCancellation
{
    #[Map(source: '[document]')]
    public array $document;

    #[Map(source: '[explanatoryLetter?]')]
    public mixed $explanatoryLetter;

    public function toArray(): array
    {
        $document = Arr::toSnakeCase($this->document);
        unset($document['inserted_at'],$document['updated_at'],$document['created_at'],$document['inserted_by'],$document['updated_by']);
        if (($document['interpretation'] ?? null) === null) {
            unset($document['interpretation']);
        }
        $document['components'] = array_map(static function (array $row): array {
            if (($row['interpretation'] ?? null) === null) {
                unset($row['interpretation']);
            }

            return $row;
        }, array_values($document['components'] ?? []));
        $document['status'] = ObservationStatus::ENTERED_IN_ERROR->value;
        $document['explanatory_letter'] = $this->explanatoryLetter;

        return $document;
    }
}
