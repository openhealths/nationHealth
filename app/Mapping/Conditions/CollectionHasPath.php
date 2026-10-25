<?php

declare(strict_types=1);

namespace App\Mapping\Conditions;

use App\Dto\FormCollection;
use Symfony\Component\ObjectMapper\Condition\ClassRuleConditionCallableInterface;

/** Reject collection paths before PropertyAccessor tries them on a real Livewire Form. */
final readonly class CollectionHasPath implements ClassRuleConditionCallableInterface
{
    public function __construct(private string|array $paths)
    {
    }

    public function __invoke(mixed $value, object $source, ?object $target): bool
    {
        if (!$source instanceof FormCollection) {
            return false;
        }
        foreach ((array) $this->paths as $path) {
            if (new SourceHasPath($path)($value, $source, $target)) {
                return true;
            }
        }

        return false;
    }
}
