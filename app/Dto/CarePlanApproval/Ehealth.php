<?php

declare(strict_types=1);

namespace App\Dto\CarePlanApproval;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\Shared\EhealthReference;
use App\Models\CarePlan;
use App\Mapping\Transforms\FhirReference;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: CarePlan::class)]
final class Ehealth
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[uuid]', transform: [[self::class, 'resource'], new MapCollection(targetClass: EhealthReference::class)])]
    public array $resources;

    #[Map(if: false)]
    public array $granted_to;

    #[Map(if: false)]
    public string $access_level;

    #[Map(if: false)]
    public ?string $authorize_with;

    public function __construct(string $employeeUuid, string $accessLevel, ?string $authorizeWith)
    {
        $this->granted_to = new FhirReference('employee')($employeeUuid, $this, $this);
        $this->access_level = $accessLevel;
        $this->authorize_with = $authorizeWith ?: null;
    }

    public static function resource(string $uuid): array
    {
        return [(object) ['uuid' => $uuid, 'type' => 'care_plan']];
    }
}
