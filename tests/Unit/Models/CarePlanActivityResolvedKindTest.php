<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use Tests\TestCase;

class CarePlanActivityResolvedKindTest extends TestCase
{
    public function test_resolved_kind_prefers_kind_column_over_kind_concept(): void
    {
        $concept = CodeableConcept::make();
        $concept->setRelation('coding', collect([
            (object) ['code' => 'service_request'],
        ]));

        $activity = new CarePlanActivity([
            'kind' => 'medication_request',
        ]);
        $activity->setRelation('kindConcept', $concept);

        $this->assertSame('medication_request', $activity->resolvedKind());
    }

    public function test_resolved_kind_normalizes_fhir_pascal_case_from_column(): void
    {
        $activity = new CarePlanActivity([
            'kind' => 'ServiceRequest',
        ]);

        $this->assertSame('service_request', $activity->resolvedKind());
    }

    public function test_resolved_kind_falls_back_to_kind_concept_when_column_empty(): void
    {
        $concept = CodeableConcept::make();
        $concept->setRelation('coding', collect([
            (object) ['code' => 'DeviceRequest'],
        ]));

        $activity = new CarePlanActivity([
            'kind' => '',
        ]);
        $activity->setRelation('kindConcept', $concept);

        $this->assertSame('device_request', $activity->resolvedKind());
    }
}
