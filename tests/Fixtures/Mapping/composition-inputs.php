<?php

declare(strict_types=1);

$newborn = [
    'prepersonUuid' => '10000000-0000-4000-8000-000000000001',
    'personUuid' => '20000000-0000-4000-8000-000000000002',
    'encounterUuid' => '30000000-0000-4000-8000-000000000003',
    'newbornBirthDate' => '13.08.2026',
    'newbornSex' => 'FEMALE',
];
$disability = [
    'category' => 'SICKNESS',
    'subjectUuid' => '20000000-0000-4000-8000-000000000002',
    'sectionFocusUuid' => '20000000-0000-4000-8000-000000000002',
    'encounterUuid' => '30000000-0000-4000-8000-000000000003',
    'eventPeriodStart' => '25.10.2026',
    'eventPeriodEnd' => '26.10.2026',
];

return [
    'newborn open end' => ['kind' => 'newborn', 'fields' => $newborn],
    'newborn auth and UI fields' => ['kind' => 'newborn', 'fields' => [...$newborn, 'informWithUuid' => '40000000-0000-4000-8000-000000000004', 'password' => 'synthetic-ui-only']],
    'disability empty optional lists' => ['kind' => 'disability', 'fields' => $disability],
    'disability all flags and violation' => ['kind' => 'disability', 'fields' => [...$disability, 'isAccident' => true, 'isIntoxicated' => true, 'isForeignTreatment' => true, 'isForceRenew' => true, 'treatmentViolation' => 'reject_recommendation', 'treatmentViolationDate' => '25.10.2026', 'informWithUuid' => '40000000-0000-4000-8000-000000000004']],
    'disability preperson and different focus' => ['kind' => 'disability', 'fields' => [...$disability, 'isUnidentified' => true, 'sectionFocusUuid' => '50000000-0000-4000-8000-000000000005']],
    'disability violation without date' => ['kind' => 'disability', 'fields' => [...$disability, 'treatmentViolation' => 'reject_recommendation']],
    'disability date without violation' => ['kind' => 'disability', 'fields' => [...$disability, 'treatmentViolationDate' => '25.10.2026']],
    'disability default replacement' => ['kind' => 'disability', 'fields' => [...$disability, 'relatesToTargetUuid' => '60000000-0000-4000-8000-000000000006']],
    'disability continuation' => ['kind' => 'disability', 'fields' => [...$disability, 'relatesToTargetUuid' => '60000000-0000-4000-8000-000000000006', 'relatesToCode' => 'appends']],
    'disability empty relation preserved' => ['kind' => 'disability', 'fields' => [...$disability, 'relatesToTargetUuid' => '60000000-0000-4000-8000-000000000006', 'relatesToCode' => '']],
    'disability UTC calendar' => ['kind' => 'disability', 'timezone' => 'UTC', 'fields' => [...$disability, 'eventPeriodStart' => '2026-03-29', 'eventPeriodEnd' => '2026-03-30']],
    'newborn UTC calendar' => ['kind' => 'newborn', 'timezone' => 'UTC', 'fields' => [...$newborn, 'newbornBirthDate' => '2026-03-29']],
    'cancel newborn' => ['kind' => 'cancel', 'type' => 'NEWBORN', 'fields' => ['reason' => 'incorrect_data', 'reasonText' => 'Виправлення даних: тест']],
    'cancel disability' => ['kind' => 'cancel', 'type' => 'TEMP_DISABILITY', 'fields' => ['reason' => '0', 'reasonText' => '0']],
];
