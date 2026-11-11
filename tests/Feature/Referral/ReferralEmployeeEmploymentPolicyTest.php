<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Enums\User\Role;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Policies\ServiceRequestRequestPolicy;
use App\Services\Dictionary\Dictionaries\ServiceDictionary;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\Support\GrantsReferralEmployeeRole;
use Tests\TestCase;

/** Employment dates remain mandatory even when a synchronized position still says active. */
class ReferralEmployeeEmploymentPolicyTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use GrantsReferralEmployeeRole;
    use UsesCompositionDictionaries;

    private const string SERVICE = '10050000-0000-4000-8000-000000000011';

    public static function employmentWorkplaces(): array
    {
        $cases = [];
        foreach ([LegalEntity::TYPE_PRIMARY_CARE, LegalEntity::TYPE_OUTPATIENT] as $entityType) {
            foreach ([Role::DOCTOR, Role::SPECIALIST, Role::ASSISTANT, Role::MED_COORDINATOR, Role::MED_ADMIN, Role::LABORANT] as $role) {
                foreach (['past_start', 'starts_today', 'future_start', 'ends_today', 'ended', 'ends_tomorrow'] as $period) {
                    $cases[$entityType.' '.$role->value.' '.$period] = [$entityType, $role, $period];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('employmentWorkplaces')]
    public function test_referral_author_and_executor_permissions_use_actual_employment_dates(string $entityType, Role $role, string $period): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $context = $this->compositionFixture($entityType, $role, ['service_request:read', 'service_request:write', 'service_request:use']);
        $employee = $context['employee'];
        $this->grantReferralEmployeeRole($context['user'], $employee);
        $employee->update($this->employmentDates($period));
        $this->seedService();
        $count = ServiceRequestRequest::count();
        $available = in_array($period, ['past_start', 'starts_today', 'ends_tomorrow'], true);
        $canCreate = in_array($role, [Role::DOCTOR, Role::SPECIALIST, Role::ASSISTANT, Role::MED_COORDINATOR], true);
        $canUse = in_array($role, [Role::DOCTOR, Role::SPECIALIST, Role::ASSISTANT, Role::LABORANT], true);
        $policy = new ServiceRequestRequestPolicy();

        $this->assertSame($available && $canCreate, $policy->create($context['user'], $employee, 'laboratory_procedure')->allowed());
        $this->assertSame($available && $canUse, $policy->use($context['user'], $employee, 'laboratory_procedure')->allowed());
        $this->assertSame($available, $policy->createCode($context['user'], $employee, self::SERVICE)->allowed());
        $this->assertSame($count, ServiceRequestRequest::count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function unavailableSpecimenAuthors(): array
    {
        $cases = [];
        foreach (['future_start', 'ends_today', 'ended'] as $period) {
            foreach ([false, true] as $preperson) {
                $cases[$period.($preperson ? ' preperson' : ' person')] = [$period, $preperson];
            }
        }

        return $cases;
    }

    #[DataProvider('unavailableSpecimenAuthors')]
    public function test_unavailable_author_cannot_persist_person_or_preperson_specimen_referral(string $period, bool $preperson): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $context = $this->compositionFixture(scopes: ['service_request:read', 'service_request:write', 'specimen:read']);
        $this->grantReferralEmployeeRole($context['user'], $context['employee']);
        $context['employee']->update($this->employmentDates($period));
        $patient = $preperson ? $this->compositionPreperson() : $context['person'];
        $specimen = Specimen::create([
            'uuid' => (string) Str::uuid(),
            $preperson ? 'preperson_id' : 'person_id' => $patient->id,
            'status' => 'available',
            'type_id' => CodeableConcept::create()->id,
            'managing_organization_id' => Identifier::create(['value' => $context['legalEntity']->uuid])->id,
            'registered_by_id' => Identifier::create(['value' => $context['employee']->uuid])->id,
        ]);
        $this->seedService();
        $count = ServiceRequestRequest::count();

        try {
            app(ReferralRequestLifecycleService::class)->createSpecimenDraft($specimen, $patient, [
                'service_id' => self::SERVICE, 'quantity' => 1,
                'started_at' => now()->addHour()->toIso8601String(),
                'ended_at' => now()->addDay()->toIso8601String(),
            ], $context['employee']);
            $this->fail('An unavailable position must be refused before creating a referral.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('executorId', $exception->errors());
            $this->assertSame($count, ServiceRequestRequest::count());
            Http::assertNothingSent();
            Queue::assertNothingPushed();
        }
    }

    private function employmentDates(string $period): array
    {
        return [
            'start_date' => match ($period) {
                'starts_today' => today()->toDateString(),
                'future_start' => today()->addDay()->toDateString(),
                default => today()->subMonth()->toDateString(),
            },
            'end_date' => match ($period) {
                'ends_today' => today()->toDateString(),
                'ended' => today()->subDay()->toDateString(),
                'ends_tomorrow' => today()->addDay()->toDateString(),
                default => null,
            },
        ];
    }

    private function seedService(): void
    {
        Cache::forever(ServiceDictionary::KEY, [[
            'id' => self::SERVICE, 'code' => 'TEST', 'name' => 'Laboratory',
            'is_active' => true, 'request_allowed' => true, 'category' => 'laboratory_procedure',
        ]]);
        Cache::forever(ServiceDictionary::KEY.':fresh', true);
        config(['ehealth.referrals.assistant_allowed_categories' => ['laboratory_procedure']]);
    }
}
