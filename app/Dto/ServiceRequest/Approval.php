<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Classes\eHealth\Api\Responses\Collections\ServiceRequestDetails;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Referral fields used when previewing or requesting access to permitted resources. */
#[Map(source: ServiceRequestDetails::class)]
class Approval
{
    #[Map(source: '[requisition]')]
    public string $requisition;

    #[Map(source: '[status]')]
    public string $status;

    #[Map(source: '[permitted_resources?]')]
    public ?array $permittedResources = null;

    /** @var list<array{code: ?string, name: ?string}> */
    #[Map(if: false)]
    public array $previewRows {
        get => array_map(static fn (array $resource): array => [
            'code' => data_get($resource, 'identifier.type.coding.0.code'),
            'name' => data_get($resource, 'identifier.value'),
        ], $this->permittedResources ?? []);
    }
}
