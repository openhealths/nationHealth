<?php

declare(strict_types=1);

namespace Tests\Unit\eHealth\Api;

use App\Classes\eHealth\Api\Patient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientSummaryResponsesTest extends TestCase
{
    private const array METHODS = [
        'allergy_intolerances' => 'getAllergyIntolerances',
        'medication_statements' => 'getMedicationStatements',
        'risk_assessments' => 'getRiskAssessments',
    ];

    #[DataProvider('pages')]
    public function test_response_contract(string $section, array $body, bool $valid): void
    {
        config(['ehealth.api.domain' => 'https://ehealth.example']);
        $patientId = '9183a36b-4d45-4244-9339-63d81cd08d9c';
        $path = "/api/patients/$patientId/summary/$section";
        $respond = static fn (Request $request) => $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === $path ? Http::response($body) : null;
        Http::fake($respond);
        $client = new Patient(Http::getFacadeRoot());
        $client->stub([$respond])->preventStrayRequests();
        $response = $client->{self::METHODS[$section]}($patientId);
        Http::assertSentCount(1);

        if (!$valid) {
            $this->expectException(ValidationException::class);
        }

        $rows = $response->validate();
        $this->assertSame($response->getData(), $rows);
    }

    public static function pages(): iterable
    {
        $date = '2018-08-02T10:45:00.000Z';
        $common = ['id' => '90a9e15b-b71b-4caf-8f2e-ff247e8a5600', 'asserted_date' => $date];
        $concept = ['coding' => [['system' => 'urn:test', 'code' => 'R80']]];
        $allergy = $common + [
            'clinical_status' => 'active', 'verification_status' => 'confirmed', 'type' => 'allergy',
            'category' => 'food', 'criticality' => 'low', 'code' => $concept, 'context' => ['display_value' => ''],
            'onset_date_time' => $date, 'inserted_at' => $date, 'updated_at' => $date, 'primary_source' => true,
        ];
        $medication = $common + [
            'status' => 'active', 'context' => (object) [], 'primary_source' => true, 'medication_code' => $concept,
        ];
        $risk = $common + ['status' => 'preliminary', 'code' => $concept, 'context' => (object) [], 'performer' => (object) []];

        foreach (['allergy_intolerances' => $allergy, 'medication_statements' => $medication, 'risk_assessments' => $risk] as $section => $record) {
            $isRisk = $section === 'risk_assessments';
            [$first, $second] = $isRisk ? ['reason_references', 'reason_codes'] : ['asserter', 'report_origin'];
            $a = $record + [$first => $isRisk ? [] : (object) []];
            $b = $record + [$second => (object) []];
            if (!$isRisk) {
                $b['primary_source'] = false;
            }
            yield "$section empty page" => [$section, ['data' => []], true];
            yield "$section missing data" => [$section, [], false];
            yield "$section data object" => [$section, ['data' => (object) []], false];
            yield "$section scalar entry" => [$section, ['data' => [false]], false];
            yield "$section $first" => [$section, ['data' => [$a]], true];
            yield "$section $second" => [$section, ['data' => [$b]], true];
            yield "$section both alternatives" => [$section, ['data' => [$a + [$second => $b[$second]]]], false];
            yield "$section neither alternative on second row" => [$section, ['data' => [$a, $record]], false];
            yield "$section wrong first shape" => [$section, ['data' => [$record + [$first => $isRisk ? (object) [] : []]]], false];
            yield "$section wrong second shape" => [$section, ['data' => [$record + [$second => []]]], false];
            if (!$isRisk) {
                yield "$section string is not boolean" => [$section, ['data' => [array_replace($a, ['primary_source' => '0'])]], false];
            }
        }

        $allergy += ['asserter' => (object) []];
        foreach ([(object) [], ['display_value' => null], ['display_value' => 0]] as $i => $badContext) {
            yield "allergy display key missing or not a string $i" => ['allergy_intolerances', ['data' => [array_replace($allergy, ['context' => $badContext])]], false];
        }
        unset($medication['medication_code']);
        yield 'medication code required' => ['medication_statements', ['data' => [$medication + ['asserter' => (object) []]]], false];

        $reference = ['identifier' => [
            'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'condition']]],
            'value' => '97d57238-ffbe-4335-92ea-28d4de117ea2',
        ]];
        yield 'risk reference list' => ['risk_assessments', ['data' => [$risk + ['reason_references' => [$reference]]]], true];
        yield 'risk reference list contains scalar' => ['risk_assessments', ['data' => [$risk + ['reason_references' => [false]]]], false];
        $risk += ['reason_codes' => ['coding' => [['system' => 'urn:test', 'code' => 'reason']]]];
        $quantity = ['value' => 0, 'system' => 'urn:test', 'code' => 'kg'];
        $range = ['low' => $quantity, 'high' => $quantity];
        $period = ['start' => $date];
        $prediction = ['probability_decimal' => 0, 'when_period' => $period];
        foreach ([
            'decimal zero and period' => [[$prediction], true],
            'probability and time ranges' => [[['probability_range' => $range, 'when_range' => $range]], true],
            'null predictions' => [null, false],
            'nonobject prediction' => [[false], false],
            'second prediction lacks probability' => [[$prediction, ['when_period' => $period]], false],
            'both probabilities' => [[$prediction + ['probability_range' => $range]], false],
            'missing time' => [[['probability_decimal' => 0]], false],
            'both times' => [[$prediction + ['when_range' => $range]], false],
            'period without start' => [[array_replace($prediction, ['when_period' => ['end' => $date]])], false],
        ] as $name => [$predictions, $valid]) {
            yield "risk $name" => ['risk_assessments', ['data' => [$risk + ['predictions' => $predictions]]], $valid];
        }
    }
}
