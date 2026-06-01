<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use App\Exceptions\EHealth\EHealthConnectionException;

class RuleEngineRules extends Request
{
    protected const string URL = '/api/rule_engine_rules';

    /**
     * Get a catalog of all active rule engine rules.
     *
     * @param  array{name?:string, system?:string, code?:string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/rule-engine-rules/get-rule-engine-rule-list/get-rule-engine-rule-list
     */
    public function getList(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL, $mergedQuery);
    }

    /**
     * Get rule engine rule details filtered by ID with active rules.
     *
     * @param  string  $uuid
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/rule-engine-rules/get-rule-engine-rule-details/get-rule-engine-rule-details
     */
    public function getDetails(string $uuid): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/$uuid");
    }
}
