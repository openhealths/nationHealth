<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Livewire\Composition\Forms\CompositionForm;
use App\Services\MedicalEvents\Mappers\CompositionMapper;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use Tests\TestCase;

/**
 * Covers birth-conclusion (МВН) form rules — especially the app date format the UI mask uses.
 */
class CompositionFormTest extends TestCase
{
    public function test_newborn_birth_date_must_match_app_date_format(): void
    {
        $this->assertTrue(
            $this->validate(['newbornBirthDate' => '2026-08-13'])->fails(),
            'Native HTML date (Y-m-d) must not pass UI validation.'
        );

        $this->assertFalse(
            $this->validate(['newbornBirthDate' => '13.08.2026'])->fails(),
            'Masked dd.mm.yyyy input must be accepted.'
        );
    }

    public function test_a_future_newborn_birth_date_is_rejected(): void
    {
        $validator = $this->validate([
            'newbornBirthDate' => now()->addDay()->format(config('app.date_format')),
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('newbornBirthDate', $validator->errors()->toArray());
    }

    public function test_mapper_data_converts_birth_date_to_ymd(): void
    {
        $form = $this->makeForm();
        $form->prepersonUuid = '52b504c7-0177-4078-834b-52d89154081c';
        $form->encounterUuid = 'e39ee5ae-2644-4f04-8e64-bb359866e907';
        $form->personUuid = '43cc2161-1c2b-481b-a618-77e35817f850';
        $form->newbornBirthDate = '13.08.2026';
        $form->newbornSex = 'FEMALE';

        $payload = (new CompositionMapper())->newborn(
            $form->toMapperData(),
            '43cc2161-1c2b-481b-a618-77e35817f850'
        );

        $extension = collect($payload['extension'])->firstWhere('valueCode', 'NEWBORN_BIRTH_DATE');
        $this->assertSame('2026-08-13', $extension['valueDate']);
    }

    public function test_create_view_uses_masked_datepicker_not_native_date_input(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/composition/composition-create.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString('datepicker-input', $blade);
        $this->assertStringContainsString('form.newbornBirthDate', $blade);
        $this->assertStringNotContainsString('type="date"', $blade);
        $this->assertStringContainsString('whitespace-nowrap', $blade);
        $this->assertStringContainsString('clearMother', $blade);
        $this->assertStringContainsString('pick_mother_first', $blade);
    }

    private function makeForm(): CompositionForm
    {
        return new CompositionForm(new class extends Component
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
            'type' => 'NEWBORN',
            'category' => 'LIVE_BIRTH',
            'prepersonUuid' => '52b504c7-0177-4078-834b-52d89154081c',
            'encounterUuid' => 'e39ee5ae-2644-4f04-8e64-bb359866e907',
            'personUuid' => '43cc2161-1c2b-481b-a618-77e35817f850',
            'newbornBirthDate' => '13.08.2026',
            'newbornSex' => 'FEMALE',
        ], $overrides);

        return Validator::make($data, $form->compositionRules(['FEMALE' => 'жіноча', 'MALE' => 'чоловіча']));
    }
}
