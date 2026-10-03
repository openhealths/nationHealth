<?php

declare(strict_types=1);

namespace App\Mapping\EHealth\Referral;

use Carbon\CarbonImmutable;
use stdClass;

final readonly class ServiceRequestInput
{
    /** @param list<stdClass> $basedOn @param list<stdClass>|null $supportingInfo @param list<stdClass>|null $reasonReference */
    public function __construct(
        public ?string $uuid,
        public string $serviceId,
        public string $employeeUuid,
        public string $legalEntityUuid,
        public CarbonImmutable $mappedAt,
        public string $intent = 'order',
        public string $priority = 'routine',
        public array $basedOn = [],
        public ?stdClass $context = null,
        public ?string $category = null,
        public ?float $quantity = null,
        public string $quantitySystem = 'SERVICE_UNIT',
        public string $quantityCode = 'PIECE',
        public ?string $startedAt = null,
        public ?string $endedAt = null,
        public ?array $supportingInfo = null,
        public ?array $reasonReference = null,
        public ?string $patientInstruction = null,
        public mixed $informWith = null,
        public ?string $programId = null,
    ) {
    }

    /**
     * Adapt validated legacy form/record values without loading context or generating identifiers.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $uuids
     */
    public static function fromArray(array $data, array $uuids, CarbonImmutable $mappedAt, ?string $carePlanUuid = null, ?string $activityUuid = null): self
    {
        $context = !empty($uuids['encounter_uuid'])
            ? self::reference('encounter', $uuids['encounter_uuid'])
            : (!empty($uuids['episode_uuid']) ? self::reference('episode_of_care', $uuids['episode_uuid']) : null);

        return new self(
            uuid: $data['uuid'] ?? null,
            serviceId: $data['service_id'],
            employeeUuid: (string) $uuids['employee_uuid'],
            legalEntityUuid: (string) $uuids['legal_entity_uuid'],
            mappedAt: $mappedAt,
            intent: $data['intent'] ?? 'order',
            priority: $data['priority'] ?? 'routine',
            basedOn: !empty($carePlanUuid) && !empty($activityUuid)
                ? [self::reference('care_plan', $carePlanUuid), self::reference('activity', $activityUuid)]
                : [],
            context: $context,
            category: !empty($data['category']) ? (string) $data['category'] : null,
            quantity: isset($data['quantity']) ? (float) $data['quantity'] : null,
            quantitySystem: ($data['quantity_system'] ?? null) ?: 'SERVICE_UNIT',
            quantityCode: ($data['quantity_code'] ?? null) ?: 'PIECE',
            startedAt: !empty($data['started_at']) ? (string) $data['started_at'] : null,
            endedAt: !empty($data['ended_at']) ? (string) $data['ended_at'] : null,
            supportingInfo: self::references($data['supporting_info'] ?? null),
            reasonReference: self::references($data['reason_reference'] ?? null),
            patientInstruction: !empty($data['patient_instruction']) ? (string) $data['patient_instruction'] : null,
            informWith: $data['inform_with'] ?? null,
            programId: !empty($data['program_id']) ? (string) $data['program_id'] : null,
        );
    }

    private static function reference(string $type, string $uuid): stdClass
    {
        return (object) ['type' => $type, 'uuid' => $uuid];
    }

    /** @return list<stdClass>|null */
    private static function references(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $rows = is_array($value) ? array_filter($value, is_array(...)) : [];
        if ($rows === []) {
            return null;
        }

        $references = [];
        foreach ($rows as $row) {
            if (!empty($row['uuid']) && !empty($row['type'])) {
                $references[] = self::reference(strtolower($row['type']), $row['uuid']);
            }
        }

        // A supplied list of incomplete references has historically produced an explicit empty list.
        return $references;
    }
}
