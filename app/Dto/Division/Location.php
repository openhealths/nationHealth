<?php

declare(strict_types=1);

namespace App\Dto\Division;

use App\Livewire\Division\Forms\DivisionForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: DivisionForm::class)]
class Location
{
    #[Map(source: 'division[location?][latitude?]', transform: [self::class, 'normalizeCoordinate'])]
    public mixed $latitude = null;

    #[Map(source: 'division[location?][longitude?]', transform: [self::class, 'normalizeCoordinate'])]
    public mixed $longitude = null;

    public static function normalizeCoordinate(mixed $value): mixed
    {
        $normalized = \is_string($value) ? str_replace(',', '.', $value) : $value;

        return \is_numeric($normalized) ? (float) $normalized : $value;
    }

    /** @return array{latitude: mixed, longitude: mixed} */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
