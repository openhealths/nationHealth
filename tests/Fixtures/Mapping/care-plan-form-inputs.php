<?php

declare(strict_types=1);

$plan = json_decode(file_get_contents(__DIR__.'/../CarePlan/plan.json'), true, flags: JSON_THROW_ON_ERROR);
$cases = [];
foreach (['d.m.Y' => ['19.10.2026', '20.10.2026'], 'Y-m-d' => ['2026-10-19', '2026-10-20'], 'd/m/Y' => ['19/10/2026', '20/10/2026']] as $format => [$start, $end]) {
    foreach (['create' => [], 'edit' => $plan] as $mode => $seed) {
        $cases[$mode.' '.$format] = [
            'dateFormat' => $format,
            'fields' => ['title' => 'Synthetic form', 'category' => 'TEST', 'termsOfService' => 'OUTPATIENT', 'author' => 'UI author display', 'encounter' => $plan['encounter']['identifier']['value'], 'periodStart' => $start, 'periodStartTime' => '13:20:30', 'periodEnd' => $end, 'coAuthors' => [$plan['contributor'][0]['identifier']['value']], 'episodes' => [['uuid' => $plan['supportingInfo'][0]['identifier']['value']]], 'medicalRecords' => [$plan['supportingInfo'][1]], 'password' => 'synthetic-secret-never-in-payload', 'knedp' => 'synthetic-provider'],
            'resolved' => ['uuid' => $plan['id'], 'author' => $plan['author']['identifier']['value'], 'addresses' => $plan['addresses']],
            'context' => ['resource' => $seed, 'timezone' => 'Europe/Kyiv', 'dateFormat' => $format],
        ];
    }
}

return $cases;
