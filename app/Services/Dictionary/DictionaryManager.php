<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\UpdateDictionaryCache;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use App\Services\Dictionary\Collections\DiagnoseGroupCollection;
use App\Services\Dictionary\Collections\DrugCollection;
use App\Services\Dictionary\Collections\MedicalProgramCollection;
use App\Services\Dictionary\Collections\ServiceCollection;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use App\Services\Dictionary\Dictionaries\DiagnoseGroupDictionary;
use App\Services\Dictionary\Dictionaries\DrugDictionary;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\Dictionary\Dictionaries\ServiceDictionary;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Dictionary Manager for handling multiple dictionary sources.
 *
 * Provides centralized access to various dictionary types with automatic caching and collection-based data manipulation.
 */
class DictionaryManager
{
    /**
     * Registered dictionary instances.
     *
     * @var array<string, DictionaryInterface>
     */
    private array $dictionaries = [];

    /**
     * Register a dictionary instance.
     *
     * @param  DictionaryInterface  $dictionary  Dictionary instance to register
     */
    public function register(DictionaryInterface $dictionary): void
    {
        $this->dictionaries[$dictionary->getKey()] = $dictionary;
    }

    /**
     * Get medical programs dictionary collection.
     *
     * @return MedicalProgramCollection Collection with medical program data
     */
    public function medicalPrograms(): MedicalProgramCollection
    {
        return new MedicalProgramCollection($this->get(MedicalProgramDictionary::KEY));
    }

    /**
     * Get services dictionary collection.
     *
     * @return ServiceCollection Collection with service data
     */
    public function services(): ServiceCollection
    {
        return new ServiceCollection($this->get(ServiceDictionary::KEY));
    }

    /**
     * Get basic dictionaries collection.
     *
     * @return BasicDictionaryCollection Collection with basic dictionary data
     */
    public function basics(): BasicDictionaryCollection
    {
        return new BasicDictionaryCollection($this->get(BasicDictionary::KEY));
    }

    /**
     * Get drugs dictionaries collection.
     *
     * @return DrugCollection Collection with basic dictionary data
     */
    public function drugs(): DrugCollection
    {
        return new DrugCollection($this->get(DrugDictionary::KEY));
    }

    /**
     * Get diagnose groups dictionary collection.
     *
     * @return DiagnoseGroupCollection Collection with diagnose group data
     */
    public function diagnoseGroups(): DiagnoseGroupCollection
    {
        return new DiagnoseGroupCollection($this->get(DiagnoseGroupDictionary::KEY));
    }

    /**
     * Get cached dictionary data by key.
     *
     * @param  string  $key  Dictionary key
     * @return Collection Raw dictionary data wrapped in Collection
     * @throws InvalidArgumentException When dictionary key not found
     */
    private function get(string $key): Collection
    {
        $dictionary = $this->dictionaries[$key] ?? throw new InvalidArgumentException("Dictionary '$key' not found");

        $cacheKey = $dictionary->getKey();
        $freshKey = $cacheKey . ':fresh';

        try {
            // Check if fresh data exists
            if (Cache::has($freshKey)) {
                // Fresh data exists, return cached data
                $cachedData = Cache::get($cacheKey, []);

                return collect($cachedData);
            }

            // Fresh marker expired, check if we have stale data
            $staleData = Cache::get($cacheKey);
            if ($staleData !== null) {
                $this->triggerBackgroundRefresh($dictionary);

                return collect($staleData);
            }

            // Get response to check pagination
            $response = $dictionary->fetch();
            $freshData = $response->getData();
            $paging = $response->getPaging();
            $totalPages = $paging['total_pages'] ?? 1;

            // Cache the fresh data with both keys
            Cache::put($cacheKey, $freshData, now()->addWeek()); // Keep stale data for a week
            Cache::put($freshKey, true, now()->endOfDay()); // Fresh marker for 1 day

            // If multiple pages, trigger background refresh for remaining pages
            if ($totalPages > 1) {
                for ($page = 2; $page <= $totalPages; $page++) {
                    UpdateDictionaryCache::dispatch($dictionary->getKey(), $page)
                        ->delay(now()->addSeconds($page * 2));
                }
            }

            return collect($freshData);

        } catch (ConnectionException $e) {
            Log::error("Dictionary '$key' connection failed", ['error' => $e->getMessage()]);

            // Return stale data if available, otherwise empty collection
            $staleData = Cache::get($cacheKey, []);

            return collect($staleData);
        } catch (EHealthResponseException|EHealthValidationException $e) {
            Log::error("Dictionary '$key' API error", ['error' => $e->getMessage()]);

            // Return stale data if available, otherwise empty collection
            $staleData = Cache::get($cacheKey, []);

            return collect($staleData);
        }
    }

    /**
     * Trigger background refresh for dictionary without blocking current request.
     *
     * @param  DictionaryInterface  $dictionary
     */
    private function triggerBackgroundRefresh(DictionaryInterface $dictionary): void
    {
        try {
            UpdateDictionaryCache::dispatch($dictionary->getKey(), 1);
        } catch (Exception $exception) {
            Log::error("Failed to trigger background refresh", [
                'dictionary' => $dictionary->getKey(),
                'error' => $exception->getMessage()
            ]);
        }
    }

    /**
     * Fetch specific page from dictionary API.
     *
     * @param  string  $dictionaryKey  Dictionary key
     * @param  int  $page  Page number (1-based)
     * @return EHealthResponse
     */
    public static function fetchDictionaryPage(string $dictionaryKey, int $page = 1): EHealthResponse
    {
        // Create dictionary instance based on key and use its fetch method
        $dictionary = match ($dictionaryKey) {
            MedicalProgramDictionary::KEY => new MedicalProgramDictionary(),
            ServiceDictionary::KEY => new ServiceDictionary(),
            BasicDictionary::KEY => new BasicDictionary(),
            DrugDictionary::KEY => new DrugDictionary(),
            DiagnoseGroupDictionary::KEY => new DiagnoseGroupDictionary(),
            default => throw new InvalidArgumentException("Unknown dictionary key: $dictionaryKey")
        };

        return $dictionary->fetch($page);
    }
}
