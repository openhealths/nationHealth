<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\EncounterDiagnose;
use App\Repositories\MedicalEvents\EncounterRepository;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class EncounterPrimaryDiagnosisTest extends TestCase
{
    public static function diagnosis(string $roleCode, string $code, int $personId = 11): EncounterDiagnose
    {
        $role = new CodeableConcept();
        $role->setRelation('coding', new Collection([new Coding(['code' => $roleCode])]));
        $concept = new CodeableConcept();
        $concept->setRelation('coding', new Collection([new Coding(['system' => 'eHealth/ICD10_AM/condition_codes', 'code' => $code])]));
        $condition = new Condition(['uuid' => $code, 'person_id' => $personId]);
        $condition->setRelation('code', $concept);
        $diagnosis = new EncounterDiagnose();
        $diagnosis->setRelation('role', $role)->setRelation('conditionModel', $condition);

        return $diagnosis;
    }

    public function test_primary_is_selected_by_role_instead_of_position_or_secondary_code(): void
    {
        $encounter = new Encounter(['person_id' => 11]);
        $encounter->setRelation('diagnoses', new Collection([self::diagnosis('secondary', 'E11'), self::diagnosis('primary', 'E10')]));
        $result = app(EncounterRepository::class)->primaryDiagnosisConditions($encounter);

        $this->assertSame(['E10'], $result->pluck('uuid')->all());
    }

    public function test_missing_primary_does_not_fall_back_to_secondary(): void
    {
        $encounter = new Encounter(['person_id' => 11]);
        $encounter->setRelation('diagnoses', new Collection([self::diagnosis('secondary', 'E11')]));
        $this->assertTrue(app(EncounterRepository::class)->primaryDiagnosisConditions($encounter)->isEmpty());
    }

    public function test_ambiguous_primary_is_not_selected_arbitrarily(): void
    {
        $encounter = new Encounter(['person_id' => 11]);
        $encounter->setRelation('diagnoses', new Collection([self::diagnosis('primary', 'E11'), self::diagnosis('primary', 'E10')]));
        $this->assertTrue(app(EncounterRepository::class)->primaryDiagnosisConditions($encounter)->isEmpty());
    }

    public function test_condition_of_another_patient_cannot_be_used(): void
    {
        $encounter = new Encounter(['person_id' => 11]);
        $encounter->setRelation('diagnoses', new Collection([self::diagnosis('primary', 'E11', 12)]));
        $this->assertTrue(app(EncounterRepository::class)->primaryDiagnosisConditions($encounter)->isEmpty());
    }
}
