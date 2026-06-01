<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\UpdateDictionaryCache;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use App\Services\Dictionary\Collections\DeviceDefinitionCollection;
use App\Services\Dictionary\Collections\DiagnoseGroupCollection;
use App\Services\Dictionary\Collections\DrugCollection;
use App\Services\Dictionary\Collections\ForbiddenGroupCollection;
use App\Services\Dictionary\Collections\RuleEngineRuleCollection;
use App\Services\Dictionary\Collections\ServiceCollection;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use App\Services\Dictionary\Dictionaries\DeviceDefinitionDictionary;
use App\Services\Dictionary\Dictionaries\DiagnoseGroupDictionary;
use App\Services\Dictionary\Dictionaries\DrugDictionary;
use App\Services\Dictionary\Dictionaries\ForbiddenGroupDictionary;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\Dictionary\Dictionaries\RuleEngineRuleDictionary;
use App\Services\Dictionary\Dictionaries\ServiceDictionary;
use Exception;
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
     * In-memory cache for the current request to avoid repeated deserialization overhead.
     *
     * @var array<string, Collection>
     */
    private array $resolved = [];

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
     * @return Collection Medical program data
     */
    public function medicalPrograms(): Collection
    {
        return $this->get(MedicalProgramDictionary::KEY);
    }

    /**
     * Get services dictionary collection.
     *
     * @return ServiceCollection Collection with service data
     */
    public function services(): ServiceCollection
    {
        return ServiceCollection::make($this->get(ServiceDictionary::KEY));
    }

    /**
     * Get basic dictionaries collection.
     *
     * @return BasicDictionaryCollection Collection with basic dictionary data
     */
    public function basics(): BasicDictionaryCollection
    {
        return BasicDictionaryCollection::make($this->get(BasicDictionary::KEY));
    }

    /**
     * Get drugs dictionaries collection.
     *
     * @return DrugCollection Collection with basic dictionary data
     */
    public function drugs(): DrugCollection
    {
        return DrugCollection::make($this->get(DrugDictionary::KEY));
    }

    /**
     * Get diagnose groups dictionary collection.
     *
     * @return DiagnoseGroupCollection Collection with diagnose group data
     */
    public function diagnoseGroups(): DiagnoseGroupCollection
    {
        return DiagnoseGroupCollection::make($this->get(DiagnoseGroupDictionary::KEY));
    }

    /**
     * Get forbidden groups dictionary collection.
     *
     * @return ForbiddenGroupCollection Collection with forbidden group data
     */
    public function forbiddenGroups(): ForbiddenGroupCollection
    {
        return ForbiddenGroupCollection::make($this->get(ForbiddenGroupDictionary::KEY));
    }

    /**
     * Get device definitions dictionary collection.
     *
     * @return DeviceDefinitionCollection Collection with device definition data
     */
    public function deviceDefinitions(): DeviceDefinitionCollection
    {
        return DeviceDefinitionCollection::make($this->get(DeviceDefinitionDictionary::KEY));
    }

    /**
     * Get rule engine rules collection with per-rule details.
     *
     * The rule list and its details are fetched together in RuleEngineRuleDictionary::fetch(),
     * so both caches are always in sync. Details are read directly from cache here.
     *
     * @return RuleEngineRuleCollection Collection with ruleList() and details() accessors
     */
    public function ruleEngineRules(): RuleEngineRuleCollection
    {
        $rules = $this->get(RuleEngineRuleDictionary::KEY);
        $details = Cache::get(RuleEngineRuleDictionary::DETAILS_CACHE_KEY, []);

        return RuleEngineRuleCollection::make(
            $rules->map(static fn (array $rule) => array_merge($rule, ['details' => $details[data_get($rule, 'code.code')]]))
        );
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

        return $this->resolved[$key] ?? ($this->resolved[$key] = (function () use (
            $dictionary,
            $key,
            $cacheKey,
            $freshKey
        ) {
            try {
                if (Cache::has($freshKey)) {
                    return collect(Cache::get($cacheKey, []));
                }

                $staleData = Cache::get($cacheKey);
                if ($staleData !== null) {
                    $this->triggerBackgroundRefresh($dictionary);

                    return collect($staleData);
                }

                $response = $dictionary->fetch();
                $freshData = $response->getData();
                $paging = $response->getPaging();
                $totalPages = $paging['total_pages'] ?? 1;

                Cache::put($cacheKey, $freshData, now()->addWeek());
                Cache::put($freshKey, true, now()->endOfDay());

                if ($totalPages > 1) {
                    for ($page = 2; $page <= $totalPages; $page++) {
                        UpdateDictionaryCache::dispatch($dictionary->getKey(), $page)
                            ->delay(now()->addSeconds($page * 2));
                    }
                }

                return collect($freshData);
            } catch (EHealthConnectionException $exception) {
                $exception->handle("Dictionary '$key' connection failed");

                return collect(Cache::get($cacheKey, []));
            } catch (EHealthResponseException|EHealthValidationException $e) {
                Log::error("Dictionary '$key' API error", ['error' => $e->getMessage()]);

                return collect(Cache::get($cacheKey, []));
            }
        })());
    }

    /**
     * Trigger background refresh for dictionary without blocking current request.
     *
     * @param  DictionaryInterface  $dictionary
     */
    private function triggerBackgroundRefresh(DictionaryInterface $dictionary): void
    {
        try {
            $token = $dictionary instanceof RequiresAuthentication
                ? session()->get(config('ehealth.api.oauth.bearer_token'))
                : null;

            UpdateDictionaryCache::dispatch($dictionary->getKey(), 1, $token);
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
     * @throws InvalidArgumentException When dictionary key not found
     */
    public function fetchPage(string $dictionaryKey, int $page = 1): EHealthResponse
    {
        $dictionary = $this->dictionaries[$dictionaryKey] ?? throw new InvalidArgumentException("Dictionary '$dictionaryKey' not found");

        return $dictionary->fetch($page);
    }
}
