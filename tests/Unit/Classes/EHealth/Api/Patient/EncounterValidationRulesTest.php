<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\EHealth\Api\Patient;

use App\Classes\eHealth\Api\Patient\Encounter as EncounterApi;
use Illuminate\Support\Facades\Validator;
use ReflectionClass;
use Tests\TestCase;

/**
 * eHealth's Submit Encounter Package requires `actions` (ICPC-2) only for PHC encounters and
 * prohibits it otherwise; `reasons` is only mandatory for PHC. These rules gate encounters
 * pulled back from eHealth during sync, mirroring the class-aware rules in EncounterForm.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/18167398401/AH+RC+CSI-1758+Submit+Encounter+Package
 */
class EncounterValidationRulesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $api = new EncounterApi();
        $method = (new ReflectionClass($api))->getMethod('encounterValidationRules');
        $method->setAccessible(true);
        $rules = $method->invoke($api);

        return collect($rules)->mapWithKeys(static fn ($rule, $key) => ["*.$key" => $rule])->toArray();
    }

    public function test_actions_are_required_for_phc_class(): void
    {
        $validator = Validator::make([['class' => ['code' => 'PHC'], 'actions' => []]], $this->rules());

        $this->assertTrue($validator->errors()->has('0.actions'));
    }

    public function test_actions_are_prohibited_for_amb_class(): void
    {
        $validator = Validator::make([[
            'class' => ['code' => 'AMB'],
            'actions' => [['coding' => [['code' => 'T89', 'system' => 'eHealth/ICPC2/actions']]]]
        ]], $this->rules());

        $this->assertTrue($validator->errors()->has('0.actions'));
    }

    public function test_actions_are_optional_for_inpatient_class_when_absent(): void
    {
        $validator = Validator::make([['class' => ['code' => 'INPATIENT'], 'actions' => []]], $this->rules());

        $this->assertFalse($validator->errors()->has('0.actions'));
    }

    public function test_reasons_are_required_for_phc_class(): void
    {
        $validator = Validator::make([['class' => ['code' => 'PHC'], 'reasons' => []]], $this->rules());

        $this->assertTrue($validator->errors()->has('0.reasons'));
    }

    public function test_reasons_are_optional_for_inpatient_class(): void
    {
        $validator = Validator::make([['class' => ['code' => 'INPATIENT'], 'reasons' => []]], $this->rules());

        $this->assertFalse($validator->errors()->has('0.reasons'));
    }
}
