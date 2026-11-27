<?php

declare(strict_types=1);

namespace Tests\Unit\Dto\Division;

use App\Dto\Division\Location;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

class LocationTest extends TestCase
{
    protected function map(array $coordinates): Location
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())
            ->map(new Collection($coordinates), Location::class);
    }

    public function test_normalizes_coordinates_without_mutating_input_and_is_idempotent(): void
    {
        $input = [
            'latitude' => '50,324231234567890123456789',
            'longitude' => '-1.799999999999999999e2',
            'note' => '00123',
        ];
        $original = $input;

        $location = $this->map($input)->toArray();

        $this->assertSame([
            'latitude' => '50.32423123456789', 'longitude' => '-179.99999999999999',
        ], $location);
        $this->assertSame($original, $input);
        $this->assertSame($location, $this->map($location)->toArray());
    }

    public static function optionalCoordinates(): array
    {
        return [
            'absent' => [[], ['latitude' => null, 'longitude' => null]],
            'null' => [['latitude' => null, 'longitude' => null], ['latitude' => null, 'longitude' => null]],
            'invalid input' => [['latitude' => 'invalid', 'longitude' => '30,5,2'], ['latitude' => 'invalid', 'longitude' => '30,5,2']],
            'empty input' => [['latitude' => '', 'longitude' => ''], ['latitude' => '', 'longitude' => '']],
            'partial input' => [['latitude' => '0'], ['latitude' => '0', 'longitude' => null]],
        ];
    }

    #[DataProvider('optionalCoordinates')]
    public function test_preserves_optional_and_invalid_coordinates_for_validation(array $input, array $expected): void
    {
        $this->assertSame($expected, $this->map($input)->toArray());
    }
}
