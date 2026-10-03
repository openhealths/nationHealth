<?php

declare(strict_types=1);

namespace App\Dto\Division;

use App\Dto\EhealthMapping;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Division payload in the format expected by the eHealth API.
 */
class Ehealth
{
    use EhealthMapping;

    public ?string $name = null;

    public ?string $type = null;

    public ?string $email = null;

    public ?string $externalId = null;

    /** @var array<int, array<string, mixed>> */
    #[Map(transform: [self::class, 'transformAddresses'])]
    public array $addresses = [];

    /** @var array<int, array<string, mixed>> */
    #[Map(transform: [self::class, 'transformPhones'])]
    public array $phones = [];

    /** @var array{latitude: float|null, longitude: float|null}|null */
    public ?array $location = null;

    /** @var array<string, array<int, array<int, string>>> */
    #[Map(transform: [self::class, 'transformWorkingHours'])]
    public array $workingHours = [];

    /**
     * @param  array<string|int, array<string, mixed>>|null  $addresses
     * @return array<int, array<string, mixed>>
     */
    public static function transformAddresses(?array $addresses): array
    {
        return array_values($addresses ?? []);
    }

    /**
     * @param  array<string|int, array<string, mixed>>|null  $phones
     * @return array<int, array<string, mixed>>
     */
    public static function transformPhones(?array $phones): array
    {
        return array_values($phones ?? []);
    }

    /**
     * eHealth expects the time divider to be a dot (08.00) instead of a colon.
     *
     * @param  array<string, array<int, array<int, string>>>|null  $workingHours
     * @return array<string, array<int, array<int, string>>>
     */
    public static function transformWorkingHours(?array $workingHours): array
    {
        return array_map(
            fn (array $intervals) => array_map(
                fn (array $interval) => array_map(fn (string $time) => str_replace(':', '.', $time), $interval),
                $intervals
            ),
            $workingHours ?? []
        );
    }

    /**
     * @return list<string>
     */
    protected function keepWhenSet(): array
    {
        return ['location', 'working_hours'];
    }
}
