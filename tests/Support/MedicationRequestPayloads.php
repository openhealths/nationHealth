<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Dto\MedicationRequest\Ehealth;
use App\Dto\MedicationRequest\EhealthCreate;
use App\Dto\MedicationRequest\EhealthPrequalify;
use Carbon\CarbonImmutable;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

/** Test adapter for the existing golden contracts; application callers map the DTOs directly. */
final class MedicationRequestPayloads
{
    public function __construct(private readonly ObjectMapperInterface $mapper)
    {
    }

    public function create(array $data, array $uuids, CarbonImmutable $mappedAt, ?string $carePlanUuid = null): array
    {
        return $this->mapper->map(Ehealth::source($data, $uuids, $mappedAt, $carePlanUuid), EhealthCreate::class)->toArray();
    }

    public function prequalify(array $data, array $uuids, CarbonImmutable $mappedAt, ?string $carePlanUuid = null): array
    {
        return $this->mapper->map(Ehealth::source($data, $uuids, $mappedAt, $carePlanUuid), EhealthPrequalify::class)->toArray();
    }

    public function signedContent(array $data, array $uuids, CarbonImmutable $mappedAt, ?string $carePlanUuid = null): array
    {
        return $this->mapper->map(Ehealth::source($data, $uuids, $mappedAt, $carePlanUuid), Ehealth::class)->toArray();
    }
}
