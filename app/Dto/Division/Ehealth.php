<?php

declare(strict_types=1);

namespace App\Dto\Division;

use App\Models\Division;
use App\Dto\EhealthMapping;
use App\Livewire\Division\Forms\DivisionForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Division payload in the format expected by the eHealth API.
 */
#[Map(source: DivisionForm::class)]
class Ehealth
{
    use EhealthMapping;

    #[Map(source: 'division[name]')]
    public ?string $name = null;

    #[Map(source: 'division[type]')]
    public ?string $type = null;

    #[Map(source: 'division[email]')]
    public ?string $email = null;

    #[Map(source: 'division[externalId]')]
    public ?string $externalId = null;

    /** @var array<int, array<string, mixed>> */
    #[Map(source: 'division[addresses]', transform: [self::class, 'transformAddresses'])]
    public array $addresses = [];

    /** @var array<int, array<string, mixed>> */
    #[Map(source: 'division[phones]', transform: [self::class, 'transformPhones'])]
    public array $phones = [];

    /** @var array{latitude: float|null, longitude: float|null}|null */
    #[Map(source: 'division[location]')]
    public ?array $location = null;

    /** @var array<string, array<int, array<int, string>>> */
    #[Map(source: 'division[workingHours]', transform: [self::class, 'transformWorkingHours'])]
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
     * eHealth expects the time divider to be a dot (08.00) instead of a colon
     * and uses 00.00 for a day off.
     *
     * @param  array<string, array<int, array<int, string>>>|null  $workingHours
     * @return array<string, array<int, array<int, string>>>
     */
    public static function transformWorkingHours(?array $workingHours): array
    {
        return array_map(
            fn (array $intervals) => array_map(
                fn (array $interval) => array_map(
                    fn (string $time) => $time === Division::WORKING_TIME_DAY_OFF
                        ? '00.00'
                        : str_replace(':', '.', $time),
                    $interval
                ),
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
