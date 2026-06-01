<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Dictionaries;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Services\Dictionary\DictionaryInterface;
use App\Services\Dictionary\RequiresAuthentication;
use Illuminate\Support\Facades\Cache;

class RuleEngineRuleDictionary implements DictionaryInterface, RequiresAuthentication
{
    /**
     * Dictionary unique identifier key.
     */
    public const string KEY = 'dictionaries.rule_engine_rules';

    /**
     * Cache key for per-rule details, indexed by rule code.
     */
    public const string DETAILS_CACHE_KEY = 'dictionaries.rule_engine_rule_details';

    /**
     * Get the dictionary key.
     *
     * @return string Dictionary identifier for caching and registry
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * Fetch the rule list and cache per-rule details as a side effect.
     *
     * Details are fetched in the same request so they stay in sync with the list.
     * The background refresh job calls this method too, keeping both caches aligned.
     *
     * @inheritDoc
     */
    public function fetch(int $page = 1): EHealthResponse
    {
        $response = EHealth::ruleEngineRules()->getList();

        $this->fetchAndCacheDetails($response->getData());

        return $response;
    }

    /**
     * Fetch per-rule details for each rule in the list and store them under a single cache key.
     *
     * Failures are caught and logged so that a broken details fetch does not prevent
     * the rule list from being cached by the caller.
     *
     * @param  array  $rules
     * @return void
     */
    private function fetchAndCacheDetails(array $rules): void
    {
        try {
            $details = [];

            foreach ($rules as $rule) {
                $detail = EHealth::ruleEngineRules()->getDetails($rule['id'])->getData();
                $code = data_get($detail, 'code.code');
                $details[$code] = $detail;
            }

            Cache::put(self::DETAILS_CACHE_KEY, $details, now()->addWeek());
            Cache::put(self::DETAILS_CACHE_KEY . ':fresh', true, now()->endOfDay());
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Failed to fetch rule engine details');
        }
    }
}
