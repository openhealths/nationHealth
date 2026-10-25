<?php

declare(strict_types=1);

namespace App\Mapping\Conditions;

use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\ConditionCallableInterface;

/** An explicit null in plain input is distinct from an absent field; no model getters are invoked. */
final readonly class SourceHasPath implements ConditionCallableInterface
{
    public function __construct(private string $path)
    {
    }

    public function __invoke(mixed $value, object $source, ?object $target): bool
    {
        $data = $source;
        foreach (explode('.', $this->path) as $part) {
            if ($data instanceof Collection) {
                $data = $data->all();
            } elseif (is_object($data)) {
                $data = get_object_vars($data);
            }
            if (!is_array($data) || !array_key_exists($part, $data)) {
                return false;
            }
            $data = $data[$part];
        }

        return true;
    }
}
