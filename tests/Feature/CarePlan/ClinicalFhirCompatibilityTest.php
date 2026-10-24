<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Repositories\CarePlanRepository;
use App\Repositories\CarePlanActivityRepository;
use App\Repositories\MedicalEvents\MedicationRequestRepository;
use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClinicalFhirCompatibilityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_plan_activities_prescriptions_and_referrals_preserve_shared_primitives(): void
    {
        Http::preventStrayRequests();
        $native = $this->fixture('medication-request-detail.json');
        $person = Person::create(['uuid' => $native['person']['id'], 'birth_date' => '1990-01-01', 'gender' => 'MALE']);
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);
        $legalEntity = LegalEntity::create(['uuid' => (string) Str::uuid(), 'status' => 'ACTIVE', 'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId, 'is_active' => true]);
        $planResource = $this->fixture('CarePlan/plan.json');
        $planResource['id'] = $native['based_on'][0]['identifier']['value'];
        $planResource['period']['end'] = '2026-11-01T21:59:59.123456Z';
        $plan = app(CarePlanRepository::class)->storeResource($planResource, $person->id, $legalEntity->id);
        $activityResource = $this->fixture('CarePlan/activity.json');
        $activityResource['id'] = $native['based_on'][1]['identifier']['value'];
        $activityResource['carePlan']['identifier']['value'] = $plan->uuid;
        $activityResource['detail']['productReference']['identifier']['value'] = $native['medication_info']['medication_id'];
        $activity = app(CarePlanActivityRepository::class)->storeResource($activityResource, $plan);
        $medications = app(MedicationRequestRepository::class);
        $request = $medications->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $medications->assertActivityBasis($plan, $activity, ['medication_id' => $native['medication_info']['medication_id']]);
        $this->assertSame($activity->id, $medications->findActivity($activity->uuid, $person->id, $plan->id, $legalEntity->id)?->id);
        $this->assertNull($medications->findActivity($activity->uuid, $person->id, $plan->id + 1, $legalEntity->id));
        $this->assertNull($medications->findActivity($activity->uuid, $person->id + 1, $plan->id, $legalEntity->id));
        $this->assertNull($medications->findActivity($activity->uuid, $person->id, $plan->id, $legalEntity->id + 1));

        $referralResource = $this->fixture('ServiceRequest/resource.json');
        $serviceActivity = $activityResource;
        $serviceActivity['id'] = (string) Str::uuid();
        $serviceActivity['detail']['kind'] = 'service_request';
        $serviceActivity['detail']['productReference'] = $referralResource['code'];
        app(CarePlanActivityRepository::class)->storeResource($serviceActivity, $plan);
        $referralResource['basedOn'] = [$activityResource['carePlan'], [
            'identifier' => ['value' => $serviceActivity['id'], 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'activity']]]],
        ]];
        $referrals = app(ServiceRequestRequestRepository::class);
        $referral = $referrals->storeResource($referralResource, $person->id);

        // Reimporting any owner must not mutate another module's references or periods.
        $medications->storeResource($medications->getResource($request), $person->id);
        $referrals->storeResource($referralResource, $person->id);
        app(CarePlanRepository::class)->storeResource($planResource, $person->id, $legalEntity->id);
        $this->assertEquals((new MedicationRequestMapper())->fromEHealth($native), $medications->getResource($request->fresh()));
        $this->assertEquals($referralResource, $referrals->getResource($referral->fresh()));
        $this->assertEquals($activityResource, app(CarePlanActivityRepository::class)->getResource($activity->id));
        $this->assertSame($planResource['period'], app(CarePlanRepository::class)->getResource($plan->id)['period']);
    }

    private function fixture(string $path): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/'.$path)), true, flags: JSON_THROW_ON_ERROR);
    }
}
