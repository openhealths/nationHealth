<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Livewire\DeviceRequest\DeviceRequestForm;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Relations\PersonName;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceRequestFormUiTest extends TestCase
{
    public function test_plan_controls_display_the_locked_context_and_preserve_the_two_record_tables(): void
    {
        $form = $this->renderForm(['linked' => true]);
        $form->assertSee(__('device-requests.quantity_period'))->assertSee(__('device-requests.characteristics'))
            ->assertSee(__('device-requests.reason'))->assertSee(__('device-requests.supporting'))
            ->assertSee('08.05.2025')->assertSee('A98 Підтримання здоров’я');

        $xpath = $this->dom($form->html());
        $this->assertSame(1, $xpath->query('//*[@id="request-use-care-plan" and @checked and @disabled]')->length);
        $this->assertSame(1, $xpath->query('//select[@id="request-care-plan" and @disabled]/option[@value="00000000-0000-4000-8000-000000000004"]')->length);
        $this->assertSame(1, $xpath->query('//select[@id="request-care-plan-activity" and @disabled]/option[@value="00000000-0000-4000-8000-000000000005"]')->length);
    }

    public function test_unlinked_request_shows_the_plan_entry_point_and_read_only_unsupported_fields(): void
    {
        $form = $this->renderForm();
        $form->assertSee(__('device-requests.open_from_plan'))->assertSee(__('device-requests.supporting_unavailable'));
        $xpath = $this->dom($form->html());
        $this->assertSame(1, $xpath->query('//*[@id="request-use-care-plan" and not(@checked) and not(@disabled)]')->length);
        $this->assertSame(1, $xpath->query('//select[@id="request-priority" and @disabled]')->length);
        $this->assertSame(1, $xpath->query('//button[@aria-describedby="supporting-record-hint" and @disabled]')->length);
    }

    public function test_mock_plan_choices_can_be_displayed_without_clinical_data(): void
    {
        $this->renderForm(['previewChoices' => true])->assertSee('План А')
            ->assertSeeHtml('value="plan-a"')->assertSee(__('device-requests.open_from_plan'));
        $page = $this->renderForm(['previewChoices' => true]);
        $xpath = $this->dom($page->html());
        $this->assertSame(1, $xpath->query('//select[@id="request-care-plan" and @disabled]')->length);
        $this->assertSame(1, $xpath->query('//select[@id="request-care-plan-activity" and @disabled]')->length);
        $this->assertSame(1, $xpath->query('//a[@href="'.route('persons.care-plans', [legalEntity(), 1]).'"]')->length);
    }

    public function test_saved_draft_has_a_dismissible_confirmation_without_a_delete_action(): void
    {
        $form = $this->renderForm(['draft' => true]);
        $xpath = $this->dom($form->html());
        $this->assertSame(1, $xpath->query('//button[@aria-controls="delete-request-dialog" and not(@disabled)]')->length);
        $this->assertSame(1, $xpath->query('//dialog/form[@method="dialog"]/button[@type="submit" and not(@disabled)]')->length);
        $this->assertSame(1, $xpath->query('//dialog//button[@type="button" and @disabled]')->length);
        $form->assertDontSeeHtml('wire:click="deleteDraft"')->assertSee(__('device-requests.delete_unavailable'));
    }

    public function test_default_authentication_phone_is_visible_before_confirmation(): void
    {
        $this->renderForm()->assertSee('+380501234567')->assertSee(__('device-requests.form.confirm_phone'));
    }

    public function test_missing_selected_authentication_does_not_offer_confirmation_for_another_method(): void
    {
        $this->renderForm()->set('request.inform_with', 'unavailable')
            ->assertSee(__('device-requests.validation.auth_method_unavailable'))->assertDontSee(__('device-requests.form.confirm_phone'));
    }

    public function test_request_session_error_is_displayed_once_by_the_parent_toast(): void
    {
        $this->renderForm()->call('emitFixtureError')->assertHasNoErrors()->assertSee('Fixture request error')
            ->call('clearFixtureRender')->assertDontSee('Fixture request error');
    }

    private function renderForm(array $parameters = [])
    {
        $entity = (new LegalEntity())->forceFill(['id' => 1]);
        $person = (new Person())->forceFill(['id' => 1]);
        $this->instance('legalEntity', $entity);

        return Livewire::test(DeviceRequestRenderHarness::class, ['legalEntity' => $entity, 'person' => $person] + $parameters);
    }

    private function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dom);
    }
}

class DeviceRequestRenderHarness extends DeviceRequestForm
{
    public array $planChoices = [];

    public function emitFixtureError(): void
    {
        Session::flash('error', 'Fixture request error');
    }

    public function clearFixtureRender(): void
    {
    }

    public function mount(LegalEntity $legalEntity, Person $person, bool $linked = false, bool $draft = false, bool $previewChoices = false): void
    {
        $this->legalEntityId = $legalEntity->id;
        $this->personId = $person->id;
        $this->requestUuid = '00000000-0000-4000-8000-000000000003';
        $this->carePlanUuid = $linked ? '00000000-0000-4000-8000-000000000004' : null;
        $this->activityUuid = $linked ? '00000000-0000-4000-8000-000000000005' : null;
        $this->draftId = $draft ? $this->requestUuid : null;
        if ($previewChoices) {
            $this->planChoices = ['plan-a' => ['label' => 'План А', 'activities' => ['activity-a' => 'Призначення А']]];
        }
        $this->request = ['uuid' => $this->requestUuid, 'encounter_uuid' => '', 'device_id' => '', 'device_code_type' => 'CLASSIFICATION_TYPE', 'device_code_system' => '', 'program_id' => '', 'quantity' => 5, 'quantity_code' => 'piece', 'started_at' => '02.04.2027', 'ended_at' => '12.04.2027', 'inform_with' => '', 'phone_confirmed' => false, 'confirmed_phone' => '', 'reason_reference' => [['type' => 'condition', 'uuid' => 'known']], 'parameter_values' => []];
        $this->reasonOptions = ['condition' => ['known' => 'A98 Підтримання здоров’я · 08.05.2025']];
        $this->authMethods = [['id' => 'otp', 'type' => 'OTP', 'phone_number' => '+380501234567']];
        $this->units = ['piece' => 'шт'];
        $this->options = ['parameters' => [
            'mobility' => ['label' => 'Можливість фіксації', 'code' => 'mobility', 'type' => 'boolean', 'required' => true],
            'height' => ['label' => 'Зріст пацієнта', 'code' => 'height', 'type' => 'codeable_concept', 'required' => true, 'values' => ['under180' => 'до 180 см', 'over180' => 'понад 180 см']],
        ]];
    }

    #[Computed]
    public function legalEntity(): LegalEntity
    {
        return (new LegalEntity())->forceFill(['id' => $this->legalEntityId]);
    }

    #[Computed]
    public function person(): Person
    {
        $person = (new Person())->forceFill(['id' => $this->personId]);
        $person->setRelation('names', collect([(new PersonName())->forceFill(['language' => 'uk', 'last_name' => 'Шевченко', 'first_name' => 'Тарас', 'second_name' => 'Григорович'])]));
        return $person;
    }
}
