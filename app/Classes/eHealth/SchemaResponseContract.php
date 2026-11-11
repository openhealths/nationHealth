<?php

declare(strict_types=1);

namespace App\Classes\eHealth;

use DateTimeImmutable;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

/** Native response contracts extracted from the official MIS and Medical Events OpenAPI snapshots. */
class SchemaResponseContract
{
    private static array $contracts = [];

    public static function validate(EHealthResponse $response, string $operation, string $group = 'shared'): array
    {
        $contracts = self::contracts($group);
        $schema = $contracts['operations'][$operation]['data'] ?? throw new RuntimeException('Unknown eHealth response contract.');
        $envelope = $response->json();
        if (!is_array($envelope) || !array_key_exists('data', $envelope)) {
            throw ValidationException::withMessages(['data' => 'The eHealth response data is missing.']);
        }

        $errors = self::errors($envelope['data'], $schema, 'data', $contracts['components']);
        foreach (['urgent'] as $field) {
            if (array_key_exists($field, $envelope) && isset($contracts['operations'][$operation][$field])) {
                $errors += self::errors($envelope[$field], $contracts['operations'][$operation][$field], $field, $contracts['components']);
            }
        }
        if (isset($contracts['operations'][$operation]['paging']) && !array_key_exists('paging', $envelope)) {
            $errors['paging'] = 'The eHealth response paging is missing.';
        }
        // Paging is consumed by callers only after this validation, including an empty last page.
        if (array_key_exists('paging', $envelope)) {
            // Medical Events documents `page`; General MIS documents `page_number`.
            // Validate both documented spellings before the response normalizes paging.
            $paging = $envelope['paging'];
            if (is_array($paging) && isset($paging['page']) && !isset($paging['page_number'])) {
                $paging['page_number'] = $paging['page'];
            }
            if (is_array($paging) && isset($paging['page'], $paging['page_number']) && $paging['page'] !== $paging['page_number']) {
                $errors['paging.page'] = 'The eHealth response paging aliases disagree.';
            }
            $pagingSchema = $contracts['operations'][$operation]['paging'] ?? [];
            // Both official APIs use the same paging semantics with different names.
            // Adapt only that declared alias; retain any other provider constraints.
            $pagingSchema['type'] = 'object';
            $pagingSchema['required'] = ['page_number', 'page_size', 'total_entries', 'total_pages'];
            $pagingSchema['properties'] = array_replace($pagingSchema['properties'] ?? [], [
                'page' => ['type' => 'integer', 'minimum' => 1],
                'page_number' => ['type' => 'integer', 'minimum' => 1],
                'page_size' => ['type' => 'integer', 'minimum' => 1],
                'total_entries' => ['type' => 'integer', 'minimum' => 0],
                'total_pages' => ['type' => 'integer', 'minimum' => 0],
            ]);
            $errors += self::errors($paging, $pagingSchema, 'paging', $contracts['components']);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Signing must receive the untouched documented native fields, never mapped FHIR.
        return $envelope['data'];
    }

    /** Validate native data supplied by a validated job or an explicit persistence boundary. */
    public static function validateOperationData(array $data, string $operation, string $group = 'shared'): array
    {
        $contracts = self::contracts($group);
        $schema = $contracts['operations'][$operation]['data'] ?? throw new RuntimeException('Unknown eHealth response contract.');
        self::validateData($data, $schema, $contracts['components']);

        return $data;
    }

    public static function validateData(mixed $value, array $schema, array $components = []): void
    {
        $errors = self::errors($value, $schema, 'data', $components);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private static function contracts(string $group): array
    {
        if (!in_array($group, ['shared', 'medication'], true)) {
            throw new RuntimeException('Unknown eHealth response contract group.');
        }
        if (!isset(self::$contracts[$group])) {
            try {
                self::$contracts[$group] = json_decode(file_get_contents(resource_path('ehealth/contracts/'.$group.'-responses.json')), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('The eHealth response contract is invalid.', previous: $exception);
            }
        }

        return self::$contracts[$group];
    }

    private static function errors(mixed $value, array $schema, string $path, array $components): array
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return [];
        }
        if (isset($schema['$ref'])) {
            $name = basename($schema['$ref']);

            return self::errors($value, $components[$name] ?? throw new RuntimeException('Missing eHealth response schema.'), $path, $components);
        }
        $errors = [];
        if (isset($schema['not']) && self::errors($value, $schema['not'], $path, $components) === []) {
            $errors[$path] = 'The eHealth response matches a forbidden field combination.';
        }
        foreach ($schema['allOf'] ?? [] as $part) {
            $errors += self::errors($value, $part, $path, $components);
        }
        foreach (['oneOf', 'anyOf'] as $choice) {
            if (isset($schema[$choice])) {
                $matches = count(array_filter($schema[$choice], static fn (array $part): bool => self::errors($value, $part, $path, $components) === []));
                if ($matches === 0 || ($choice === 'oneOf' && $matches !== 1)) {
                    $errors[$path] = 'The eHealth response does not match the documented field choice.';
                }
            }
        }
        $type = $schema['type'] ?? (isset($schema['properties']) || isset($schema['required']) ? 'object' : null);
        $validType = match ($type) {
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
        if (!$validType) {
            return $errors + [$path => 'The eHealth response field has an invalid '.$type.' type.'];
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[$path] = 'The eHealth response field has an undocumented value.';
        }
        if (is_array($value) && $type === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $value)) {
                    $errors[$path.'.'.$key] = 'The required eHealth response field is missing.';
                }
            }
            foreach ($schema['properties'] ?? [] as $key => $part) {
                if (array_key_exists($key, $value)) {
                    $errors += self::errors($value[$key], $part, $path.'.'.$key, $components);
                }
            }
            if (($schema['additionalProperties'] ?? true) === false && array_diff(array_keys($value), array_keys($schema['properties'] ?? [])) !== []) {
                $errors[$path] = 'The eHealth response contains undocumented fields.';
            }
            if (is_array($schema['additionalProperties'] ?? null)) {
                foreach (array_diff(array_keys($value), array_keys($schema['properties'] ?? [])) as $key) {
                    $errors += self::errors($value[$key], $schema['additionalProperties'], $path.'.'.$key, $components);
                }
            }
        }
        if (is_array($value) && $type === 'array') {
            foreach ($value as $index => $item) {
                $errors += self::errors($item, $schema['items'] ?? [], $path.'.'.$index, $components);
            }
            foreach (['minItems' => 'minimum', 'maxItems' => 'maximum'] as $limit => $label) {
                if (isset($schema[$limit]) && ($limit === 'minItems' ? count($value) < $schema[$limit] : count($value) > $schema[$limit])) {
                    $errors[$path] = 'The eHealth collection violates its documented '.$label.' size.';
                }
            }
        }
        if (is_string($value)) {
            foreach (['minLength', 'maxLength'] as $limit) {
                if (isset($schema[$limit]) && ($limit === 'minLength' ? mb_strlen($value) < $schema[$limit] : mb_strlen($value) > $schema[$limit])) {
                    $errors[$path] = 'The eHealth response string has an invalid length.';
                }
            }
            if (isset($schema['pattern']) && preg_match('~'.str_replace('~', '\\~', $schema['pattern']).'~u', $value) !== 1) {
                $errors[$path] = 'The eHealth response string has an invalid format.';
            }
            if (($schema['format'] ?? null) === 'uuid' && !Str::isUuid($value)) {
                $errors[$path] = 'The eHealth response identifier is invalid.';
            }
            if (($schema['format'] ?? null) === 'date-time') {
                $valid = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) === 1;
                if ($valid) {
                    try {
                        new DateTimeImmutable($value);
                        $diagnostics = DateTimeImmutable::getLastErrors();
                        $valid = $diagnostics === false || ($diagnostics['warning_count'] === 0 && $diagnostics['error_count'] === 0);
                    } catch (Exception) {
                        $valid = false;
                    }
                }
                if (!$valid) {
                    $errors[$path] = 'The eHealth response date-time is invalid.';
                }
            }
            $date = ($schema['format'] ?? null) === 'date' ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : null;
            if (($schema['format'] ?? null) === 'date' && ($date === false || $date->format('Y-m-d') !== $value)) {
                $errors[$path] = 'The eHealth response date is invalid.';
            }
        }
        if (is_int($value) || is_float($value)) {
            if ((isset($schema['minimum']) && $value < $schema['minimum']) || (isset($schema['maximum']) && $value > $schema['maximum'])) {
                $errors[$path] = 'The eHealth response number is outside the documented limits.';
            }
        }

        return $errors;
    }
}
