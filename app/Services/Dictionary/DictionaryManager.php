<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use App\Services\Dictionary\Collections\MedicalProgramCollection;
use App\Services\Dictionary\Collections\ServiceCollection;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\Dictionary\Dictionaries\ServiceDictionary;
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
     * Get cached dictionary data by key.
     *
     * @param  string  $key  Dictionary key
     * @return Collection Raw dictionary data wrapped in Collection
     * @throws InvalidArgumentException When dictionary key not found
     */
    private function get(string $key): Collection
    {
        $dictionary = $this->dictionaries[$key] ?? throw new InvalidArgumentException("Dictionary '$key' not found");

        try {
            return collect(
                Cache::remember(
                    $dictionary->getKey(),
                    now()->endOfDay(),
                    static fn () => $dictionary->fetch()
                )
            );
        } catch (ConnectionException $e) {
            Log::error("Dictionary '$key' connection failed", ['error' => $e->getMessage()]);

            return collect();
        } catch (EHealthResponseException|EHealthValidationException $e) {
            Log::error("Dictionary '$key' API error", ['error' => $e->getMessage()]);

            return collect();
        }
    }
}
