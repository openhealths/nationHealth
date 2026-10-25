<?php

declare(strict_types=1);

$plan = json_decode(file_get_contents(__DIR__.'/../CarePlan/plan.json'), true, flags: JSON_THROW_ON_ERROR);
$activity = json_decode(file_get_contents(__DIR__.'/../CarePlan/activity.json'), true, flags: JSON_THROW_ON_ERROR);
$plan['remoteMetadata'] = ['false' => false, 'zero' => 0, 'empty' => [], 'unknown' => null];
$plan['category']['coding'][] = ['system' => 'synthetic/second', 'code' => 'SECOND', 'userSelected' => false];
$plan['basedOn'] = [['identifier' => ['value' => 'parent'], 'extension' => ['literal' => false]]];
$activity['detail']['remoteMetadata'] = ['literal' => false, 'precision' => '0.000100'];
$activity['detail']['reasonCode'][0]['coding'][] = ['system' => 'synthetic/second', 'code' => 'SECOND', 'userSelected' => false];
$activity['remoteMetadata'] = ['zero' => 0, 'empty' => []];
$planContext = ['resource' => $plan, 'timezone' => 'Europe/Kyiv', 'dateFormat' => 'd.m.Y'];
$activityContext = ['resource' => $activity];
$cases = [];
$add = static function (string $name, string $target, array $data, array $context = [], string $direction = 'outbound') use (&$cases): void {
    $cases[$name] = compact('target', 'data', 'context', 'direction');
};

$add('plan full hydration', 'plan', $plan, $planContext, 'inbound');
$add('plan full round trip', 'plan', $plan, $planContext, 'roundTrip');
$add('plan unchanged seed', 'plan', [], $planContext);
$add('plan explicit clear', 'plan', ['description' => null, 'note' => '', 'addresses' => [], 'category' => '', 'termsOfService' => null, 'informWith' => ''], $planContext);
$add('plan same concepts and references', 'plan', ['category' => 'TEST', 'termsOfService' => 'OUTPATIENT', 'author' => $plan['author']['identifier']['value'], 'coAuthors' => [$plan['contributor'][0]['identifier']['value']]], $planContext);
$add('plan new concepts and references', 'plan', ['category' => 'NEW', 'termsOfService' => 'INPATIENT', 'careManager' => 'manager', 'managingOrganization' => 'organization'], $planContext);
$add('plan replace lists', 'plan', ['episodes' => [], 'medicalRecords' => [], 'coAuthors' => [], 'basedOn' => [], 'partOf' => []], $planContext);
$add('plan episodes keep other references', 'plan', ['episodes' => [['uuid' => 'new-episode']]], $planContext);
$add('plan medical records keep episodes', 'plan', ['medicalRecords' => [['identifier' => ['value' => 'new-record']]]], $planContext);
$add('plan period end cleared', 'plan', ['periodEnd' => ''], $planContext);
$add('plan exact fractional instant', 'plan', ['periodStart' => '19.10.2026', 'periodStartTime' => '13:20:30'], $planContext);
$add('plan native period bypass', 'plan', ['period' => ['start' => '2026-10-25T01:00:00.123456+03:00', 'end' => null, 'extension' => ['false' => false]]], $planContext);
$add('plan null references keep seed', 'plan', ['author' => null, 'careManager' => '', 'encounter' => null], $planContext);
$add('plan new order of independent lists', 'plan', ['uuid' => 'new-plan', 'coAuthors' => ['employee'], 'episodes' => [['id' => 'episode']], 'medicalRecords' => [], 'informWith' => 'auth-method']);
$add('plan explicit typed references', 'plan', ['author' => $plan['author'], 'contributor' => $plan['contributor'], 'addresses' => $plan['addresses']], ['id' => 'new-plan']);
$add('plan context references and lists', 'plan', ['title' => 'new'], ['id' => 'new-plan', 'employeeUuid' => 'employee', 'encounterUuid' => 'encounter', 'addresses' => [], 'contributor' => [], 'supportingInfo' => []]);
$add('plan native input keys', 'plan', ['uuid' => 'new-plan', 'terms_of_service' => 'OUTPATIENT', 'period_start' => '2026-10-25', 'period_start_time' => '04:30:00', 'inform_with' => ['auth_method_id' => 'method']], ['timezone' => 'Europe/Kyiv']);
foreach (['Y-m-d' => '2026-10-25', 'd.m.Y' => '25.10.2026', 'd/m/Y' => '25/10/2026'] as $format => $date) {
    $add('plan DST '.$format, 'plan', ['uuid' => 'new-plan', 'periodStart' => $date, 'periodStartTime' => '04:30:00', 'periodEnd' => $date], ['timezone' => 'Europe/Kyiv', 'dateFormat' => $format]);
}
$add('plan missing identity', 'plan', ['title' => 'draft']);
$add('plan empty identity', 'plan', ['uuid' => ''], $planContext);
$add('activity full hydration', 'activity', $activity, [], 'inbound');
$add('activity full round trip', 'activity', $activity, $activityContext, 'roundTrip');
$add('activity unchanged seed', 'activity', [], $activityContext);
$add('activity fraction keeps units', 'activity', ['quantity' => '1.234500', 'dailyAmount' => '0.0001', 'remainingQuantity' => 0], $activityContext);
$add('activity explicit clear', 'activity', ['quantity' => '', 'dailyAmount' => null, 'goal' => [], 'reasonCode' => null, 'reasonReference' => null, 'performer' => null, 'doNotPerform' => false, 'outcomeReference' => [], 'outcomeCodeableConcept' => []], $activityContext);
$add('activity quantity metadata', 'activity', ['quantity' => '2.5', 'quantitySystem' => 'UCUM', 'quantityCode' => 'mg', 'quantityUnit' => 'milligram', 'quantityComparator' => '<'], $activityContext);
$add('activity multiple concepts', 'activity', ['goal' => ['GOAL_A', 'GOAL_B'], 'reasonCode' => ['A00', 'B00']], $activityContext);
$add('activity typed reasons preserve metadata', 'activity', ['reasonReference' => ['Observation/'.$activity['detail']['reasonReference'][0]['identifier']['value'], 'DiagnosticReport/report', 'ClinicalImpression/impression']], $activityContext);
$add('activity service group', 'activity', ['uuid' => 'activity', 'kind' => 'service_request', 'productReference' => 'service_group/product', 'author' => ['first', 'second']], ['carePlanUuid' => 'plan']);
$add('activity device concept', 'activity', ['uuid' => 'activity', 'kind' => 'device_request', 'productCodeableConcept' => 'CLASS', 'quantity' => 2, 'doNotPerform' => false]);
$add('activity native keys', 'activity', ['uuid' => 'activity', 'kind' => 'medication_request', 'product_reference' => 'medication', 'scheduled_period_start' => '2026-10-19T10:20:30.123456Z', 'remaining_quantity' => 0], ['employeeUuid' => 'employee', 'carePlanUuid' => 'plan']);
$add('activity embedded detail', 'activity', ['detail' => ['kind' => 'service_request', 'scheduledPeriod' => null, 'scheduledString' => 'as needed', 'unknown' => ['literal' => false]]], $activityContext);
$add('activity period boundaries', 'activity', ['scheduledPeriodStart' => '2026-10-25T01:00:00.123456+03:00', 'scheduledPeriodEnd' => null], $activityContext);
$add('activity status reason requires concept', 'activity', ['statusReason' => 'TEST'], $activityContext);
$add('activity explicit status reason', 'activity', ['statusReason' => ['coding' => [['system' => 'reason', 'code' => 'TEST']], 'text' => 'keep']], $activityContext);
$add('activity multiple product variants', 'activity', ['productCodeableConcept' => 'CLASS'], $activityContext);
$add('activity multiple schedule variants', 'activity', ['scheduledString' => 'as needed'], $activityContext);
$add('activity untyped reason rejected', 'activity', ['reasonReference' => ['untyped']], $activityContext);
$add('activity unsupported kind', 'activity', ['uuid' => 'activity', 'kind' => 'unsupported']);
$add('activity no identity', 'activity', ['kind' => 'service_request']);

return $cases;
