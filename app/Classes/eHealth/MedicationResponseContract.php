<?php

declare(strict_types=1);

namespace App\Classes\eHealth;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

/** Native IL response contracts from the official General MIS OpenAPI snapshot. */
final class MedicationResponseContract
{
    private static ?array $contracts = null;

    public static function validate(EHealthResponse $response, string $operation): array
    {
        $contracts = self::contracts();
        $schema = $contracts['operations'][$operation]['data'] ?? throw new RuntimeException('Unknown medication response contract.');
        $envelope = $response->json();
        if (!is_array($envelope) || !array_key_exists('data', $envelope)) {
            throw ValidationException::withMessages(['data' => 'The eHealth response data is missing.']);
        }

        $errors = self::errors($envelope['data'], $schema, 'data', $contracts['components']);
        // Paging is consumed by callers only after this validation, including an empty last page.
        if (array_key_exists('paging', $envelope)) {
            $errors += self::errors($envelope['paging'], [
                'type' => 'object',
                'required' => ['page_number', 'page_size', 'total_entries', 'total_pages'],
                'properties' => [
                    'page_number' => ['type' => 'integer', 'minimum' => 1],
                    'page_size' => ['type' => 'integer', 'minimum' => 1],
                    'total_entries' => ['type' => 'integer', 'minimum' => 0],
                    'total_pages' => ['type' => 'integer', 'minimum' => 0],
                ],
            ], 'paging', $contracts['components']);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Signing must receive the untouched documented native fields, never mapped FHIR.
        return $envelope['data'];
    }

    private static function contracts(): array
    {
        if (self::$contracts === null) {
            try {
                self::$contracts = json_decode(file_get_contents(resource_path('ehealth/contracts/medication-responses.json')), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('The medication response contract is invalid.', previous: $exception);
            }
        }

        return self::$contracts;
    }

    private static function errors(mixed $value, array $schema, string $path, array $components): array
    {
        if (isset($schema['$ref'])) {
            $name = basename($schema['$ref']);

            return self::errors($value, $components[$name] ?? throw new RuntimeException('Missing medication response schema.'), $path, $components);
        }
        $errors = [];
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
        if ($value === null && ($schema['nullable'] ?? false)) {
            return $errors;
        }
        $type = $schema['type'] ?? null;
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
