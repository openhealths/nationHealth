<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Livewire\Composition\Forms\CompositionTempDisabilityForm;
use App\Services\MedicalEvents\Mappers\CompositionMapper;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use Tests\TestCase;

/**
 * Covers the input rules for a temporary disability conclusion and the hand-off to the
 * mapper.
 */
class CompositionTempDisabilityFormTest extends TestCase
{
    public function test_a_category_outside_the_disability_set_is_rejected(): void
    {
        $this->assertTrue($this->validate(['category' => 'LIVE_BIRTH'])->fails());
        $this->assertFalse($this->validate(['category' => 'SICKNESS'])->fails());
        $this->assertFalse($this->validate(['category' => 'PREGNANCY'])->fails());
    }

    public function test_a_period_that_ends_before_it_starts_is_rejected(): void
    {
        $validator = $this->validate([
            'eventPeriodStart' => '2026-08-10',
            'eventPeriodEnd' => '2026-08-01',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('eventPeriodEnd', $validator->errors()->toArray());
    }

    public function test_dates_must_be_plain_calendar_dates(): void
    {
        $this->assertTrue($this->validate(['eventPeriodStart' => '2026-08-10T00:00:01Z'])->fails());
    }

    /**
     * TV 3.8.2.5.3 ties the violation date to the incapacity period, so recording a
     * violation without dating it leaves the conclusion unverifiable.
     */
    public function test_a_recorded_violation_requires_its_date(): void
    {
        $validator = $this->validate(['treatmentViolation' => 'reject_recommendation']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('treatmentViolationDate', $validator->errors()->toArray());
    }

    public function test_a_violation_date_before_the_incapacity_started_is_rejected(): void
    {
        $validator = $this->validate([
            'eventPeriodStart' => '2026-08-05',
            'eventPeriodEnd' => '2026-08-20',
            'treatmentViolation' => 'reject_recommendation',
            'treatmentViolationDate' => '2026-08-01',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('treatmentViolationDate', $validator->errors()->toArray());
    }

    public function test_a_violation_inside_the_period_is_accepted(): void
    {
        $validator = $this->validate([
            'eventPeriodStart' => '2026-08-05',
            'eventPeriodEnd' => '2026-08-20',
            'treatmentViolation' => 'reject_recommendation',
            'treatmentViolationDate' => '2026-08-07',
        ]);

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    /**
     * TV 3.8.2.13 — the refining conclusion inherits period and flags from the one it
     * clarifies, which arrives in getComposition shape.
     */
    public function test_prefill_reads_the_period_and_flags_of_the_previous_conclusion(): void
    {
        $form = $this->makeForm();

        $form->prefillFromPrevious([
            'identifier' => ['value' => '5f21c9a6-0000-4000-8000-000000000001'],
            'event' => [['period' => ['start' => '2026-07-01T00:00:01Z', 'end' => '2026-07-14T20:59:59Z']]],
            'extension' => [
                ['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => true],
                ['valueCode' => 'TREATMENT_VIOLATION', 'valueString' => 'hospital_leave'],
                ['valueCode' => 'TREATMENT_VIOLATION_DATE', 'valueDate' => '2026-07-05'],
            ],
        ]);

        $this->assertSame('2026-07-01', $form->eventPeriodStart);
        $this->assertSame('2026-07-14', $form->eventPeriodEnd);
        $this->assertTrue($form->isAccident);
        $this->assertFalse($form->isIntoxicated);
        $this->assertSame('hospital_leave', $form->treatmentViolation);
        $this->assertSame('2026-07-05', $form->treatmentViolationDate);
        $this->assertSame(
            '5f21c9a6-0000-4000-8000-000000000001',
            $form->relatesToTargetUuid,
            'The refining conclusion must point back at the one it replaces.'
        );
    }

    public function test_mapper_data_carries_every_field_the_payload_needs(): void
    {
        $form = $this->makeForm();
        $form->category = 'SICKNESS';
        $form->subjectUuid = '52b504c7-0177-4078-834b-52d89154081c';
        $form->encounterUuid = 'e39ee5ae-2644-4f04-8e64-bb359866e907';
        $form->sectionFocusUuid = '52b504c7-0177-4078-834b-52d89154081c';
        $form->eventPeriodStart = '2026-08-01';
        $form->eventPeriodEnd = '2026-08-10';
        $form->isAccident = true;

        $payload = (new CompositionMapper())->tempDisability(
            $form->toMapperData(),
            '43cc2161-1c2b-481b-a618-77e35817f850'
        );

        $this->assertSame('SICKNESS', $payload['category']['coding'][0]['code']);
        $this->assertSame('2026-08-01T00:00:01Z', $payload['event'][0]['period']['start']);
        $this->assertTrue(collect($payload['extension'])->firstWhere('valueCode', 'IS_ACCIDENT')['valueBoolean']);
    }

    private function makeForm(): CompositionTempDisabilityForm
    {
        return new CompositionTempDisabilityForm(new class extends Component
        {
            public function render()
            {
                return '';
            }
        }, 'form');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function validate(array $overrides = []): ValidatorContract
    {
        $form = $this->makeForm();

        $data = array_merge([
            'type' => 'TEMP_DISABILITY',
            'category' => 'SICKNESS',
            'subjectUuid' => '52b504c7-0177-4078-834b-52d89154081c',
            'encounterUuid' => 'e39ee5ae-2644-4f04-8e64-bb359866e907',
            'sectionFocusUuid' => '52b504c7-0177-4078-834b-52d89154081c',
            'eventPeriodStart' => '2026-08-01',
            'eventPeriodEnd' => '2026-08-10',
            'isAccident' => false,
            'isIntoxicated' => false,
            'isForeignTreatment' => false,
            'isForceRenew' => false,
        ], $overrides);

        return Validator::make(
            $data,
            $form->compositionRules([], ['reject_recommendation', 'hospital_leave'])
        );
    }
}
