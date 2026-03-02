<?php

declare(strict_types=1);

namespace App\Traits;

use App\Classes\Cipher\Exceptions\CipherApiException;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Throwable;

trait FormTrait
{
    /**
     * @var array|null
     */
    public ?array $dictionaries = [];

    protected function &handleDynamicProperty(string $property): mixed
    {
        $propertyParts = explode('.', $property);
        $currentProperty = &$this;

        foreach ($propertyParts as $part) {
            if (is_object($currentProperty)) {
                if (!property_exists($currentProperty, $part)) {
                    $currentProperty->{$part} = []; // Create a new property as an array
                }

                $currentProperty = &$currentProperty->{$part};
            } // If $currentProperty is an array
            elseif (is_array($currentProperty)) {
                if (!array_key_exists($part, $currentProperty)) {
                    $currentProperty[$part] = []; // Add a new key
                }

                $currentProperty = &$currentProperty[$part];
            }
        }

        return $currentProperty;
    }

    /**
     * Retrieves and sets the dictionaries by searching for the value of 'DICTIONARIES_PATH' in the dictionaries field.
     *
     * @return void
     */
    protected function getDictionary(): void
    {
        $this->dictionaries = dictionary()->getDictionaries($this->dictionaryNames ?? []);
    }

    /**
     * Filter and keep only the specified keys in a dictionaries array.
     *
     * @param  array  $keys  The keys to keep in the dictionaries array
     * @param  string  $dictionaries  The name of the dictionaries array to filter
     * @return array
     */
    protected function getDictionariesFields(array $keys, string $dictionaries): array
    {
        // If the dictionaries array exists and is an array, filter and keep only the specified keys
        if (isset($this->dictionaries[$dictionaries]) && is_array($this->dictionaries[$dictionaries])) {
            // Filter and keep only the specified keys in the dictionaries array
            return array_intersect_key($this->dictionaries[$dictionaries], array_flip($keys));
        }

        // return an empty array if the dictionaries array does not exist or is not an array
        return [];
    }

    /**
     * Convert all keys in address array (course, only of need to) to the snake-case format.
     * This need to do because DB table store it's attributes in the snake-case
     *
     * @param  array  $array
     * @return array
     */
    public function convertArrayKeysToSnakeCase(array $array): array
    {
        return collect($array)
            ->mapWithKeys(function ($value, $key) {
                return is_array($value)
                    ? [Str::snake($key) => $this->convertArrayKeysToSnakeCase($value)]
                    : [Str::snake($key) => $value];
            })
            ->toArray();
    }

    /**
     * Convert all keys in address array (course, only of need to) to the CamelCase format.
     * This need to do because DB table has it's attributes in the snake-case but the form uses camelCase
     *
     * @param  array  $array
     * @return array
     */
    public function convertArrayKeysToCamelCase(array $array): array
    {
        return collect($array)
            ->mapWithKeys(function ($value, $key) {
                return is_array($value)
                    ? [Str::camel($key) => $this->convertArrayKeysToCamelCase($value)]
                    : [Str::camel($key) => $value];
            })
            ->toArray();
    }

    /**
     * Retrieves all attributes from a model object (includes relations).
     *
     * @param  object  $model  The model object to extract attributes from
     * @return array An array containing all attributes of the model
     */
    protected function getAllAttributes(object $model): array
    {
        $arr = $model->getAttributes();
        $relations = $model->getRelations();

        foreach ($relations as $key => $relation) {
            if ($relation instanceof Collection) {
                $relationData = [];

                foreach ($relation as $index => $relationModel) {
                    $relationData[] = [$index => $relationModel->getAttributes()];
                }
            } else {
                $relationData = $relation->getAttributes();
            }

            $arr = array_merge($arr, [$key => $relationData]);
        }

        // return $this->flattenArray($arr);
        return $arr;
    }

    /**
     * Flattens a multi-dimensional array.
     * All non-first level keys are concatenated with a dot.
     *
     * @param  array  $array  The multi-dimensional array to flatten
     * @param  string  $keyPrefix  The prefix to add to the keys
     * @return array The flattened array
     */
    protected function flattenArray(array $array, string $keyPrefix = ''): array
    {
        $flattenedArray = [];

        foreach ($array as $key => $value) {
            $key = $keyPrefix ? $keyPrefix . '.' . $key : $key;

            if (is_array($value)) {
                $flattenedArray = array_merge($flattenedArray, $this->flattenArray($value, $key));
            } else {
                $flattenedArray[$key] = $value;
            }
        }

        return $flattenedArray;
    }

    /**
     * This method merges the values from two config paths (based on user-specific context),
     * removes duplicates, and returns them as an array of keys to be used for filtering dictionaries.
     *
     * @param  string  $configPath
     * @param  string|null  $additionalConfigPath
     * @return array
     */
    public function getFilteredKeysFromConfig(string $configPath, ?string $additionalConfigPath = null): array
    {
        return collect([
            config("ehealth.$configPath"),
            config("ehealth.$additionalConfigPath", [])
        ])
            ->flatten()
            ->unique()
            ->all();
    }

    /**
     * Log not successful messages from EHealth response.
     *
     * @param  EHealthResponse  $response
     * @param  string  $message
     * @return void
     */
    protected function logEHealthError(EHealthResponse $response, string $message): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $caller = collect($trace)
            ->first(static fn (array $frame) => isset($frame['file']) && !str_contains($frame['file'], '/vendor/'));

        Log::channel('e_health_errors')->error($message, [
            'status' => $response->getStatusCode(),
            'error' => $response->getError(),
            'file' => $caller['file'] ?? 'unknown',
            'line' => $caller['line'] ?? 'unknown',
            'function' => $caller['function'] ?? 'unknown'
        ]);
    }

    /**
     * Log error messages if connection exception occur during EHealth request.
     *
     * @param  ConnectionException  $exception
     * @param  string  $message
     * @return void
     */
    protected function logConnectionError(ConnectionException $exception, string $message): void
    {
        Log::channel('e_health_errors')->error($message, [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);
    }

    /**
     * Log error messages if any exception occur during database interaction.
     *
     * @param  Exception|Throwable  $exception
     * @param  string  $message
     * @return void
     */
    protected function logDatabaseErrors(Exception|Throwable $exception, string $message): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];

        Log::channel('db_errors')->error($message, [
            'class' => $caller['class'] ?? 'unknown_class',
            'method' => $caller['function'] ?? 'unknown_method',
            'error_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line_in_file' => $exception->getLine()
        ]);
    }

    /**
     * Log validation and response error from EHealth.
     *
     * @param  EHealthValidationException|EHealthResponseException  $exception
     * @param  string  $message
     * @return void
     */
    protected function logEHealthException(EHealthValidationException|EHealthResponseException $exception, string $message): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];

        Log::channel('e_health_errors')->error($message, [
            'class' => $caller['class'] ?? 'unknown_class',
            'method' => $caller['function'] ?? 'unknown_method',
            'exception_type' => get_class($exception),
            'error_message' => $exception->getDetails()
        ]);
    }

    /**
     * Log validation and response error from Cipher.
     *
     * @param  CipherApiException  $exception
     * @param  string  $message
     * @return void
     */
    protected function logCipherError(CipherApiException $exception, string $message): void
    {
        Log::channel('api_errors')->error($message, [
            'message' => $exception->response->json(['message']),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);
    }

    /**
     * Normalize date fields in an array (need for MySQL database)
     *
     * @param  array  $data
     * @return array
     */
    protected function normalizeDate(array $data): array
    {
        return array_map(function ($item) {
            if (isset($item['ehealth_inserted_at'])) {
                $item['ehealth_inserted_at'] = convertToYmd($item['ehealth_inserted_at']);
            }

            if (isset($item['ehealth_updated_at'])) {
                $item['ehealth_updated_at'] = convertToYmd($item['ehealth_updated_at']);
            }

            if (isset($item['end_date'])) {
                $item['end_date'] = convertToYmd($item['end_date']);
            }

            if (isset($item['start_date'])) {
                $item['start_date'] = convertToYmd($item['start_date']);
            }

            return $item;
        }, $data);
    }
}
