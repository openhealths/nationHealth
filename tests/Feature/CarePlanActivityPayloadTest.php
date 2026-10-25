<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\EHealth\EHealthValidationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CarePlanActivityPayloadTest extends TestCase
{
    use DatabaseTransactions;

    public function test_care_plan_activity_payload_contains_correct_daily_amount_code()
    {
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['detail']['kind'] = 'medication_request';
        $resource['detail']['dailyAmount'] = ['value' => 5, 'system' => 'MEDICATION_UNIT', 'code' => 'MG'];
        $payload = app(\App\Classes\eHealth\Api\CarePlanActivity::class)->buildCreateContent($resource);

        $this->assertEquals(5, $payload['detail']['daily_amount']['value']);
        $this->assertEquals('MEDICATION_UNIT', $payload['detail']['daily_amount']['system']);
        $this->assertEquals('MG', $payload['detail']['daily_amount']['code']);
    }

    public function test_ehealth_daily_amount_code_mismatch_error_translation()
    {
        $exception = new EHealthValidationException([
            'error' => [
                'type' => 'validation_failed',
                'message' => 'Validation failed',
                'invalid' => [
                    [
                        'entry' => '$.detail.product_reference.identifier.value',
                        'rules' => [
                            [
                                'description' => "Code field of daily_amount object should be equal to denumerator_unit of one of medication's innms"
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $translatedMessage = $exception->getTranslatedMessage();

        $this->assertStringContainsString('Код одиниці добової дози (daily_amount) повинен збігатися з denumerator_unit одного з INNM обраного лікарського засобу', $translatedMessage);
    }

    public function test_arr_to_snake_case_preserves_numeric_keys_as_json_array(): void
    {
        $input = [
            'outcome_codeable_concept' => [
                ['coding' => [['system' => 'eHealth/test', 'code' => 'A']]],
            ],
        ];

        $result = \App\Core\Arr::toSnakeCase($input);
        $json = json_encode($result['outcome_codeable_concept']);

        // Must serialize as JSON array [...], not object {...}
        $this->assertStringStartsWith('[', $json, 'outcome_codeable_concept must be a JSON array');
    }
}
