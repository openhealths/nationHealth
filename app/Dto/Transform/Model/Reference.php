<?php

declare(strict_types=1);

namespace App\Dto\Transform\Model;

use App\Dto\Identifier\Model as IdentifierModel;
use App\Enums\ResourceType;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

/**
 * Reference to a resource as a stored identifier, for #[Map], used as `transform: new Reference(ResourceType::EMPLOYEE)`.
 * Builds the identifier from the UUID of the resource held by the form.
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
     * @return IdentifierModel
     */
    public function __invoke(mixed $value, object $source, ?object $target): IdentifierModel
    {
        return IdentifierModel::map([
            'value' => $value,
            'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $this->resourceType->value]]]
        ]);
    }
}
