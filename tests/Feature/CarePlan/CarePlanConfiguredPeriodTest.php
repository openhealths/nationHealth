<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\CarePlan as CarePlanLifecycleService;
use App\Classes\eHealth\Api\Person as PersonApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\CarePlan\CarePlanUpdate;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\Encounter;

use App\Models\MedicalEvents\Sql\EncounterDiagnose;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use App\Services\Dictionary\DictionaryManager;
use App\Services\SignatureService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesFhirCarePlans;
use Tests\TestCase;

class CarePlanConfiguredPeriodTest extends TestCase
{
    use CreatesFhirCarePlans;

    use DatabaseTransactions;

    public static function dateFormats(): array
    {
        return [
            'dots' => ['d.m.Y', '10.11.2026', '11.11.2026'],
            'ISO' => ['Y-m-d', '2026-11-10', '2026-11-11'],
            'slashes' => ['d/m/Y', '10/11/2026', '11/11/2026'],
        ];
    }

    #[DataProvider('dateFormats')]
    public function test_existing_draft_reopens_saves_and_signs_dates_in_the_configured_format(string $format, string $start, string $end): void
    {
        config(['app.date_format' => $format, 'cipher.api.domain' => 'https://cipher.invalid']);
        Http::preventStrayRequests();
        Cache::put('knedp_certificate_authority', [], 60);
        $party = Party::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Іван', 'last_name' => 'Лікар',
            'tax_id' => '1234567890', 'birth_date' => '1980-01-01', 'gender' => 'MALE',
        ]);
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'email' => 'period-'.Str::random(8).'@example.com',
            'password' => Hash::make('password'), 'party_id' => $party->id,
        ]);
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);
        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'status' => 'ACTIVE', 'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId, 'is_active' => true,
        ]);
        $this->instance('legalEntity', $legalEntity);
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'full_name' => 'Лікар', 'employee_type' => 'DOCTOR',
            'status' => 'APPROVED', 'legal_entity_id' => $legalEntity->id, 'is_active' => true,
            'position' => 'Doctor', 'start_date' => now()->toDateString(), 'user_id' => $user->id, 'party_id' => $party->id,
        ]);
        $user->employees()->attach($employee->id);
        $this->grantMedicalEventAbilities($user);
        $this->grantCarePlanEmployeeRole($employee, 'OUTPATIENT');
        $this->actingAs($user);
        $person = Person::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Олена', 'last_name' => 'Пацієнт',
            'birth_date' => '1990-01-01', 'gender' => 'FEMALE',
            'patient_signed' => true, 'process_disclosure_data_consent' => true,
        ]);
        $episodeIdentifier = Identifier::create(['value' => (string) Str::uuid()]);
        $episode = Episode::create([
            'uuid' => $episodeIdentifier->value, 'person_id' => $person->id,
            'status' => 'active', 'name' => 'Контроль лікування',
            'managing_organization_id' => Identifier::create(['value' => $legalEntity->uuid])->id,
        ]);
        $episode->period()->create(['start' => '2026-11-10']);
        $class = Coding::create(['system' => 'eHealth/encounter_classes', 'code' => 'AMB']);
        $type = CodeableConcept::create();
        $encounter = Encounter::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'finished', 'episode_id' => $episodeIdentifier->id, 'class_id' => $class->id, 'type_id' => $type->id]);
        $encounter->update(['performer_id' => Identifier::create(['value' => $employee->uuid])->id]);
        $conditionIdentifier = Identifier::create(['value' => (string) Str::uuid()]);
        $code = MedicalEventsRepository::codeableConcept()->store(['coding' => [['system' => 'eHealth/ICD10_AM/condition_codes', 'code' => 'A00.0']]]);
        Condition::create(['uuid' => $conditionIdentifier->value, 'person_id' => $person->id, 'primary_source' => true, 'clinical_status' => 'active', 'verification_status' => 'confirmed', 'code_id' => $code->id, 'context_id' => $episodeIdentifier->id, 'onset_date' => now()]);
        $diagnosisRole = MedicalEventsRepository::codeableConcept()->store([
            'coding' => [['system' => 'eHealth/diagnosis_roles', 'code' => 'primary']],
        ]);
        EncounterDiagnose::create(['encounter_id' => $encounter->id, 'condition_id' => $conditionIdentifier->id, 'role_id' => $diagnosisRole->id, 'rank' => 1]);
        $draft = $this->createFhirCarePlan([
            'person_id' => $person->id, 'author_id' => $employee->id, 'legal_entity_id' => $legalEntity->id,
            'encounter_id' => $encounter->id, 'status' => 'draft', 'category' => 'class_1', 'title' => 'План лікування',
            'terms_of_service' => 'OUTPATIENT', 'period_start' => '2026-11-10', 'period_end' => '2026-11-11',
            'supporting_info' => ['episodes' => [['uuid' => $episode->uuid]]],
        ]);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn([]);
        $this->mock(PersonApi::class, function ($mock) use ($response): void {
            $mock->shouldReceive('getAuthMethods')->andReturn($response);
        });
        $this->mock(DictionaryManager::class, function ($mock): void {
            $mock->shouldReceive('basics')->andReturn(new BasicDictionaryCollection([
                ['name' => 'eHealth/care_plan_categories', 'values' => [['code' => 'class_1', 'description' => 'План лікування']]],
                ['name' => 'eHealth/encounter_classes', 'values' => [['code' => 'AMB', 'description' => 'Амбулаторна взаємодія']]],
                ['name' => 'PROVIDING_CONDITION', 'values' => [['code' => 'OUTPATIENT', 'description' => 'Амбулаторно']]],
                ['name' => 'AUTHENTICATION_METHOD', 'values' => [
                    ['code' => 'OTP', 'description' => 'Одноразовий пароль'],
                    ['code' => 'OFFLINE', 'description' => 'Без SMS'],
                    ['code' => 'THIRD_PERSON', 'description' => 'Через іншу особу'],
                ]],
            ]));
        });
        $payload = null;
        $this->mock(SignatureService::class, function ($mock) use (&$payload): void {
            $mock->shouldReceive('getCertificateAuthorities')->andReturn([]);
            $mock->shouldReceive('signData')->once()->andReturnUsing(function (array $data) use (&$payload): string {
                $payload = $data;

                return 'signed-test-payload';
            });
        });
        $uuid = $draft->uuid;
        $this->mock(CarePlanLifecycleService::class, function ($mock) use ($person, $uuid): void {
            $mock->makePartial();
            $response = Mockery::mock(EHealthResponse::class);
            $response->shouldReceive('validate')->once()->andReturn(['id' => $uuid, 'status' => 'active']);
            $response->shouldReceive('getStatusCode')->andReturn(201);
            $mock->shouldReceive('create')->once()->with($person->uuid, ['signed_data' => 'signed-test-payload', 'signed_data_encoding' => 'base64'])
                ->andReturn($response);
        });

        $earlier = CarbonImmutable::parse('2026-11-09')->format($format);
        Livewire::test(CarePlanUpdate::class, ['legalEntity' => $legalEntity, 'carePlan' => $draft])
            ->set('form.periodEnd', $earlier)->call('save')
            ->assertHasErrors(['form.periodEnd'])
            ->assertSee(__('care-plan.period_end_before_start'))
            ->assertSet('showSignatureModal', false);

        Livewire::test(CarePlanUpdate::class, ['legalEntity' => $legalEntity, 'carePlan' => $draft])
            ->assertSet('form.episodes.0.name', 'Контроль лікування')
            ->assertSet('form.episodes.0.date', $start)
            ->assertSet('form.periodStart', $start)->assertSet('form.periodEnd', $end)
            ->set('form.intent', 'proposal')->set('form.termsOfService', 'INPATIENT')->call('save')->assertHasNoErrors()
            ->assertSet('form.intent', 'order')->assertSet('form.termsOfService', 'OUTPATIENT')
            ->assertDontSeeHtml('<select id="intent"');
        $this->assertSame('2026-11-10', CarbonImmutable::parse(Repository::carePlan()->getResource($draft->id)['period']['start'])->setTimezone(config('app.timezone'))->toDateString());
        $this->assertSame('2026-11-11', CarbonImmutable::parse(Repository::carePlan()->getResource($draft->id)['period']['end'])->setTimezone(config('app.timezone'))->toDateString());

        Livewire::test(CarePlanUpdate::class, ['legalEntity' => $legalEntity, 'carePlan' => $draft->fresh()])
            ->assertSet('form.periodStart', $start)->assertSet('form.periodEnd', $end)
            ->set('form.termsOfService', 'OUTPATIENT')->set('form.knedp', 'test-knedp')
            ->set('form.password', 'secret')->set('form.keyContainerUpload', UploadedFile::fake()->create('key.jks', 100))
            ->call('sign')->assertHasNoErrors();

        $this->assertNotNull($payload);
        $this->assertSame('2026-11-10', CarbonImmutable::parse($payload['period']['start'])->setTimezone(config('app.timezone'))->toDateString());
        $this->assertSame('2026-11-11', CarbonImmutable::parse($payload['period']['end'])->setTimezone(config('app.timezone'))->toDateString());
        $this->assertSame('active', $draft->fresh()->status);
        $this->assertSame('2026-11-10', CarbonImmutable::parse(Repository::carePlan()->getResource($draft->id)['period']['start'])->setTimezone(config('app.timezone'))->toDateString());
        $this->assertSame('2026-11-11', CarbonImmutable::parse(Repository::carePlan()->getResource($draft->id)['period']['end'])->setTimezone(config('app.timezone'))->toDateString());
    }
}
