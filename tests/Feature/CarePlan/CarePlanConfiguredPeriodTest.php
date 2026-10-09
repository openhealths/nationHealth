<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Person as PersonApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\CarePlan\CarePlanUpdate;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use App\Services\Dictionary\DictionaryManager;
use App\Services\MedicalEvents\CarePlanLifecycleService;
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
use Tests\TestCase;

class CarePlanConfiguredPeriodTest extends TestCase
{
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
        $this->actingAs($user);
        $person = Person::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Олена', 'last_name' => 'Пацієнт',
            'birth_date' => '1990-01-01', 'gender' => 'FEMALE',
            'patient_signed' => true, 'process_disclosure_data_consent' => true,
        ]);
        $draft = CarePlan::create([
            'person_id' => $person->id, 'author_id' => $employee->id, 'legal_entity_id' => $legalEntity->id,
            'status' => 'draft', 'category' => '736382003', 'title' => 'План лікування',
            'terms_of_service' => 'OUTPATIENT', 'period_start' => '2026-11-10', 'period_end' => '2026-11-11',
        ]);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn([]);
        $this->mock(PersonApi::class, function ($mock) use ($response): void {
            $mock->shouldReceive('getAuthMethods')->andReturn($response);
        });
        $this->mock(DictionaryManager::class, function ($mock): void {
            $mock->shouldReceive('basics')->andReturn(new BasicDictionaryCollection());
        });
        $payload = null;
        $this->mock(SignatureService::class, function ($mock) use (&$payload): void {
            $mock->shouldReceive('getCertificateAuthorities')->andReturn([]);
            $mock->shouldReceive('signData')->once()->andReturnUsing(function (array $data) use (&$payload): string {
                $payload = $data;

                return 'signed-test-payload';
            });
        });
        $uuid = (string) Str::uuid();
        $this->mock(CarePlanLifecycleService::class, function ($mock) use ($person, $uuid): void {
            $mock->shouldReceive('submitSignedCreate')->once()->with($person->uuid, 'signed-test-payload')
                ->andReturn(['id' => $uuid, 'status' => 'active']);
        });

        Livewire::test(CarePlanUpdate::class, ['legalEntity' => $legalEntity, 'carePlan' => $draft])
            ->assertSet('form.periodStart', $start)->assertSet('form.periodEnd', $end)
            ->set('form.termsOfService', 'OUTPATIENT')->call('save')->assertHasNoErrors();
        $this->assertSame('2026-11-10', $draft->fresh()->periodStart->toDateString());
        $this->assertSame('2026-11-11', $draft->fresh()->periodEnd->toDateString());

        Livewire::test(CarePlanUpdate::class, ['legalEntity' => $legalEntity, 'carePlan' => $draft->fresh()])
            ->assertSet('form.periodStart', $start)->assertSet('form.periodEnd', $end)
            ->set('form.termsOfService', 'OUTPATIENT')->set('form.knedp', 'test-knedp')
            ->set('form.password', 'secret')->set('form.keyContainerUpload', UploadedFile::fake()->create('key.jks', 100))
            ->call('sign')->assertHasNoErrors();

        $this->assertNotNull($payload);
        $this->assertSame('2026-11-10', CarbonImmutable::parse($payload['period']['start'])->setTimezone(config('app.timezone'))->toDateString());
        $this->assertSame('2026-11-11', CarbonImmutable::parse($payload['period']['end'])->setTimezone(config('app.timezone'))->toDateString());
        $this->assertSame('active', $draft->fresh()->status);
        $this->assertSame('2026-11-10', $draft->fresh()->periodStart->toDateString());
        $this->assertSame('2026-11-11', $draft->fresh()->periodEnd->toDateString());
    }
}
