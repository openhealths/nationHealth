<?php

declare(strict_types=1);

namespace App\Dto\DeviceDefinition;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class Program
{
    #[Map(source: '[is_active?]', transform: [self::class, 'active'])]
    public bool $isActive;

    #[Map(source: '[program_devices?]', transform: [self::class, 'rows'])]
    public array $programDevices;

    public function __construct(
        #[Map(if: false)] private readonly ?string $programId,
        #[Map(if: false)] private readonly CarbonInterface $today,
    ) {
    }

    public static function active(mixed $value, Collection $source): bool
    {
        return filter_var($value ?? $source['isActive'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    public static function rows(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    public function allowsCarePlanActivity(): bool
    {
        return $this->isActive && ($this->programDevices === [] || $this->resolveProgramDevice() !== null);
    }

    public function resolveProgramDevice(): ?array
    {
        $programDevices = $this->programDevices;
        if (!is_array($programDevices) || $programDevices === []) {
            return null;
        }

        $today = $this->today->copy()->startOfDay();
        $programId = $this->programId;

        foreach ($programDevices as $programDevice) {
            if (!is_array($programDevice)) {
                continue;
            }

            if ($programId !== null && $programId !== '') {
                $rowProgramId = (string) ($programDevice['medical_program_id']
                    ?? ($programDevice['program_id']
                        ?? ($programDevice['medicalProgramId'] ?? '')));
                // Rows returned under medical_program_id filter often omit program id on the nested object.
                if ($rowProgramId !== '' && $rowProgramId !== $programId) {
                    continue;
                }
            }

            if (array_key_exists('care_plan_activity_allowed', $programDevice)
                && !filter_var($programDevice['care_plan_activity_allowed'], FILTER_VALIDATE_BOOLEAN)
            ) {
                continue;
            }

            $startDate = $programDevice['start_date'] ?? null;
            if (is_string($startDate) && $startDate !== '' && $today->lt(Carbon::parse($startDate)->startOfDay())) {
                continue;
            }

            $endDate = $programDevice['end_date'] ?? null;
            if (is_string($endDate) && $endDate !== '' && $today->gt(Carbon::parse($endDate)->endOfDay())) {
                continue;
            }

            return $programDevice;
        }

        return null;
    }
}
