<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Classes\eHealth\Api\Responses\Collections\ServiceRequestDetails;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Referral fields used when previewing or requesting access to permitted resources. */
#[Map(source: ServiceRequestDetails::class)]
class Approval
{
    #[Map(source: '[status]')]
    public string $status;

    #[Map(source: '[permitted_resources?]')]
    public ?array $permittedResources = null;
}
