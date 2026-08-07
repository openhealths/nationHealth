<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlanActivity;
use App\Repositories\CarePlanActivityRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CarePlanActivityPayloadTest extends TestCase
{
    use DatabaseTransactions;

    public function test_care_plan_activity_payload_contains_correct_daily_amount_code()
    {
        $activity = new CarePlanActivity([
            'kind' => 'medication_request',
            'quantity' => 10,
            'quantity_system' => 'MEDICATION_UNIT',
            'quantity_code' => 'ml',
            'daily_amount' => 5,
            'daily_amount_system' => 'MEDICATION_UNIT',
            'daily_amount_code' => 'MG',
            'uuid' => 'test-uuid-1234',
        ]);

        $repository = new CarePlanActivityRepository();
        $payload = $repository->formatCarePlanActivityRequest($activity);

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
