<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Collections;

use Illuminate\Support\Collection;

class RuleEngineRuleCollection extends Collection
{
    /**
     * Get flat list of rules as returned by the API.
     *
     * @return array
     */
    public function ruleList(): array
    {
        return $this->map(static fn (array $rule) => collect($rule)->except('details')->all())
            ->values()
            ->all();
    }

    /**
     * Get rule details indexed by rule code.
     *
     * @return array
     */
    public function details(): array
    {
        return $this->filter(static fn (array $rule) => isset($rule['details']))
            ->mapWithKeys(static fn (array $rule) => [data_get($rule, 'code.code') => $rule['details']])
            ->all();
    }
}
