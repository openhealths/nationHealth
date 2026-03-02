<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;

class RuleEngineRules extends Request
{
    protected const string URL = '/api/rule_engine_rules';

    /**
     * Get rule engine rule details filtered by ID with active rules.
     *
     * @param  string  $url
     * @param  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException
     */
    public function get(string $url, $query = null): PromiseInterface|EHealthResponse
    {
        return parent::get(self::URL . "/$url", $query);
    }

    /**
     * Get a catalog of all active rule engine rules.
     *
     * @param  string  $url
     * @param  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException
     */
    public function getMany(string $url = self::URL, $query = null): PromiseInterface|EHealthResponse
    {
        return parent::get($url, $query);
    }
}
