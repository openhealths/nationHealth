<?php

declare(strict_types=1);

namespace App\Mapping\Transforms;

use Symfony\Component\ObjectMapper\TransformCallableInterface;

/** The UUID shared by pipe-encoded form selections and eHealth authentication references. */
final class AuthMethodId implements TransformCallableInterface
{
    public function __invoke(mixed $value, object $source, ?object $target): ?string
    {
        return self::extract($value);
    }

    public static function extract(mixed $value): ?string
    {
        if (is_array($value)) {
            $id = $value['auth_method_id'] ?? data_get($value, 'identifier.value') ?? ($value['value'] ?? '');
            $value = is_string($id) ? $id : '';
        }

        $id = explode('|', trim((string) $value))[0] ?? '';

        return $id !== '' ? $id : null;
    }
}
