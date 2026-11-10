<?php

declare(strict_types=1);

namespace App\Dto\Division;

use App\Livewire\Division\Forms\DivisionForm;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

#[Map(source: DivisionForm::class)]
#[Map(source: Collection::class)]
class Location
{
    protected const int DECIMAL_PLACES = 14;

    #[Map(source: 'division[location?][latitude?]', if: new SourceClass(DivisionForm::class), transform: [self::class, 'normalizeCoordinate'])]
    #[Map(source: '[latitude?]', if: new SourceClass(Collection::class), transform: [self::class, 'normalizeCoordinate'])]
    public mixed $latitude = null;

    #[Map(source: 'division[location?][longitude?]', if: new SourceClass(DivisionForm::class), transform: [self::class, 'normalizeCoordinate'])]
    #[Map(source: '[longitude?]', if: new SourceClass(Collection::class), transform: [self::class, 'normalizeCoordinate'])]
    public mixed $longitude = null;

    public static function normalizeCoordinate(mixed $value): mixed
    {
        $normalized = \is_string($value) ? str_replace(',', '.', $value) : $value;

        if (!\is_numeric($normalized) || !is_finite((float) $normalized)) {
            return $value;
        }

        $number = \is_float($normalized)
            ? json_encode($normalized, JSON_THROW_ON_ERROR)
            : trim((string) $normalized);

        try {
            return (string) BigDecimal::of($number)
                ->toScale(self::DECIMAL_PLACES, RoundingMode::Down)
                ->strippedOfTrailingZeros();
        } catch (NumberFormatException) {
            return $value;
        }
    }

    /**
     * @param  array<string, mixed>|null  $location
     * @return array<string, mixed>|null
     */
    public static function normalizeLocation(?array $location): ?array
    {
        if ($location === null) {
            return null;
        }

        foreach (['latitude', 'longitude'] as $axis) {
            if (\array_key_exists($axis, $location)) {
                $location[$axis] = self::normalizeCoordinate($location[$axis]);
            }
        }

        return $location;
    }

    /** @return array{latitude: mixed, longitude: mixed} */
    public function toArray(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }
}
