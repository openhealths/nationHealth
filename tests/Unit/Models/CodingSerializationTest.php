<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class CodingSerializationTest extends TestCase
{
    public function test_new_nullable_columns_do_not_change_the_legacy_coding_shape(): void
    {
        $coding = new Coding();
        $coding->setRawAttributes([
            'system' => 'eHealth/clinical_impression_patient_categories',
            'code' => 'test-category',
            'display' => null,
            'version' => null,
            'user_selected' => null,
        ]);

        $this->assertSame([
            'system' => 'eHealth/clinical_impression_patient_categories',
            'code' => 'test-category',
        ], $coding->toArray());
    }

    public function test_clinical_impression_keeps_its_nested_legacy_coding_output(): void
    {
        $coding = new Coding();
        $coding->setRawAttributes([
            'system' => 'eHealth/clinical_impression_patient_categories',
            'code' => 'test-category',
            'display' => 'FHIR display',
            'version' => '2026',
            'user_selected' => false,
        ]);
        $concept = new CodeableConcept();
        $concept->setRelation('coding', new Collection([$coding]));
        $clinicalImpression = new ClinicalImpression();
        $clinicalImpression->setRelation('effectivePeriod', null);
        $clinicalImpression->setRelation('code', $concept);

        $this->assertSame([
            ['system' => 'eHealth/clinical_impression_patient_categories', 'code' => 'test-category'],
        ], $clinicalImpression->toArray()['code']['coding']);
        $this->assertSame('FHIR display', $coding->getAttribute('display'));
        $this->assertSame('2026', $coding->getAttribute('version'));
        $this->assertFalse($coding->getAttribute('user_selected'));
    }

    public function test_metadata_does_not_expand_global_mass_assignment(): void
    {
        $this->assertSame(['system', 'code'], (new Coding())->getFillable());
    }
}
