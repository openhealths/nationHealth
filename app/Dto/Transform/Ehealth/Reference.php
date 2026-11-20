<?php

declare(strict_types=1);

namespace App\Dto\Transform\Ehealth;

use App\Dto\Identifier\Ehealth as IdentifierEhealth;
use App\Enums\ResourceType;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

/**
 * Reference to a resource for #[Map], used as `transform: new Reference(ResourceType::EMPLOYEE)`.
 * Builds the reference from the UUID of the resource held by the form.
 *
 * @implements TransformCallableInterface<object, object>
 */
class Reference implements TransformCallableInterface
{
    public function __construct(protected ResourceType $resourceType)
    {
    }

    /**
     * @param  mixed  $value  UUID of the referenced resource
     * @param  object  $source
     * @param  object|null  $target
     * @return array{identifier: IdentifierEhealth}
     */
    public function __invoke(mixed $value, object $source, ?object $target): array
    {
        return [
            'identifier' => IdentifierEhealth::map([
                'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $this->resourceType->value]]],
                'value' => $value
            ])
        ];
    }
}
