<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Dto\DeviceRequest\Ehealth;
use App\Dto\DeviceRequest\EhealthCreate;
use App\Dto\DeviceRequest\EhealthPrequalify;
use Carbon\CarbonImmutable;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

/** Test adapter for the existing golden contracts; application callers map the DTOs directly. */
final class DeviceRequestPayloads
{
    public function __construct(private readonly ObjectMapperInterface $mapper)
    {
    }

    public function prequalify(array $data, array $uuids, CarbonImmutable $mappedAt, ?string $carePlanUuid = null, ?string $activityUuid = null): array
    {
        return $this->mapper->map(Ehealth::source($data, $uuids, $mappedAt, $carePlanUuid, $activityUuid), EhealthPrequalify::class)->toArray();
    }

    public function signedCreate(array $data, array $uuids, CarbonImmutable $mappedAt, ?string $carePlanUuid = null, ?string $activityUuid = null): array
    {
        return $this->mapper->map(Ehealth::source($data, $uuids, $mappedAt, $carePlanUuid, $activityUuid), EhealthCreate::class)->toArray();
    }
}
