<?php

declare(strict_types=1);

namespace Tests\Feature\Encounter;

use App\Livewire\EncounterPackage\EncounterReferralCreate;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class EncounterReferralLayoutTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use \Tests\Concerns\MocksBasicDictionaries;
    use UsesCompositionDictionaries;

    private Person $person;
    private LegalEntity $legalEntity;
    private Encounter $encounter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app()->setLocale('uk');
        Http::preventStrayRequests();
        ['person' => $this->person, 'legalEntity' => $this->legalEntity, 'employee' => $employee] = $this->compositionFixture();
        $this->mockBasicDictionaries(['SERVICE_REQUEST_PRIORITY' => ['routine' => 'Планове'], 'SERVICE_UNIT' => ['PIECE' => 'шт.', 'SESSION' => 'сеанс'], 'SERVICE_CATEGORY' => ['diagnostic_procedure' => 'Діагностичні процедури']]);
        $episode = $this->episode($this->person);
        $this->encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'status' => 'finished',
            'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => CodeableConcept::create(['text' => 'Консультація'])->id,
            'episode_id' => Identifier::create(['value' => $episode->uuid])->id,
            'performer_id' => Identifier::create(['value' => $employee->uuid])->id,
        ]);
        Gate::before(fn (): bool => true);
    }

    public function test_form_has_mockup_sections_and_program_info_is_hidden_initially(): void
    {
        $component = $this->referralComponent();
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new \DOMXPath($document);
        $headings = [];
        foreach ($xpath->query('//form[@class="space-y-6"]/fieldset/legend') as $legend) {
            $headings[] = trim($legend->textContent);
        }
        $this->assertSame([
            __('encounters.referral_layout.main_data'), __('encounters.referral_layout.additional_information'),
            __('encounters.referral_layout.reason_records'), __('encounters.referral_layout.supporting_records'),
        ], $headings);
        $help = $xpath->query('//*[@id="encounterReferralProgramInfo"]')->item(0);
        $this->assertTrue($help->hasAttribute('x-cloak'));
        $this->assertSame('showProgramInfo && infoProgram', $help->getAttribute('x-show'));
        $this->assertTrue(str_contains($component->html(), 'showProgramInfo: false'));
        $this->assertCount(1, $xpath->query('//*[@aria-controls="encounterReferralProgramInfo"]'));
        $this->assertCount(2, $xpath->query('//textarea'));
        $this->assertCount(0, $xpath->query('//form//details'));
        $component->assertSeeHtml('id="encounterReferralInformWith"');
        foreach (['encounterReferralStartTime' => 'encounterReferralForm.start_time', 'encounterReferralEndTime' => 'encounterReferralForm.end_time'] as $id => $binding) {
            $this->assertSame('time', $xpath->query('//*[@id="'.$id.'"]')->item(0)->getAttribute('type'));
            $this->assertSame($binding, $xpath->query('//*[@id="'.$id.'"]')->item(0)->getAttribute('wire:model'));
        }
        $component->assertDontSee(__('encounters.referral_layout.specify_time'));
        foreach ($xpath->query('//form//button[contains(@class, "item-add")]') as $button) {
            $this->assertFalse(str_starts_with(trim($button->textContent), '+'));
        }
        $this->assertCount(1, $xpath->query('//*[@id="encounterReferralQuantityUnit"]'));
        $this->assertCount(1, $xpath->query('//form/fieldset[3]//button[contains(@class, "item-add")]'));
        $this->assertCount(1, $xpath->query('//form/fieldset[4]//button[contains(@class, "item-add")]'));
        foreach (['reason_reference' => ['', 'condition', 'observation'], 'supporting_info' => ['', 'condition', 'episode', 'observation', 'diagnostic_report']] as $scope => $types) {
            $options = [];
            foreach ($xpath->query('//*[@data-selection-scope="'.$scope.'"]//select[@data-record-type]/option') as $option) {
                $options[] = $option->getAttribute('value');
            }
            $this->assertSame($types, $options);
            $this->assertCount(1, $xpath->query('//*[@data-selection-scope="'.$scope.'"]//select[@data-record-episode]'));
        }
        if (getenv('REFERRAL_UI_EXPORT')) {
            config(['cache.default' => 'array']);
            $this->fakeSignatureService();
            view()->share('errors', new \Illuminate\Support\ViewErrorBag());
            $component->call('seedRecordPickerForLayoutExport')->call('seedDemoProgramForLayoutExport');
            \Illuminate\Support\Facades\Cache::forever(\App\Services\Dictionary\Dictionaries\ServiceDictionary::KEY, [[
                'id' => 'catalog-root', 'name' => 'Медичні послуги', 'code' => 'ROOT', 'is_active' => true,
                'groups' => [[
                    'id' => 'catalog-group', 'name' => 'Діагностичні процедури', 'code' => 'DIAG', 'is_active' => true, 'request_allowed' => true,
                    'services' => [['id' => 'catalog-service', 'name' => 'Неврологічне обстеження', 'code' => 'N0002', 'category' => 'diagnostic_procedure', 'is_active' => true, 'request_allowed' => true]],
                ]],
            ]]);
            \Illuminate\Support\Facades\Cache::forever(\App\Services\Dictionary\Dictionaries\ServiceDictionary::KEY.':fresh', true);
            $catalog = Livewire::test(\App\Livewire\Dictionary\ServiceCatalog::class, [
                'legalEntity' => $this->legalEntity, 'selectionMode' => true, 'requestAllowedOnly' => true,
                'allowGroupSelection' => true, 'showAllSearchFilters' => true,
            ]);
            $selected = $this->referralComponent()->call('seedSelectedReferencesForLayoutTest');
            $prototype = $this->referralComponent(true)->call('seedRecordPickerForLayoutExport')->call('seedDemoProgramForLayoutExport');
            file_put_contents(base_path('storage/cli-testing/referral-layout-fixture.json'), json_encode([
                'html' => $component->html(), 'form' => $component->get('encounterReferralForm'),
                'programs' => $component->get('encounterReferralPrograms'),
                'records' => $component->get('encounterReferralReferenceOptions'), 'catalogHtml' => $catalog->html(),
                'headerHtml' => Livewire::test(\App\Livewire\Components\Header::class)->html(),
                'signatureHtml' => \Illuminate\Support\Facades\Blade::render('<x-electronic-signature-drawer model="showSignatureDrawer" id-prefix="realDrop" />'),
                'prototype' => [
                    'html' => $prototype->html(), 'form' => $prototype->get('encounterReferralForm'),
                    'snapshot' => $prototype->snapshot, 'effects' => $prototype->effects,
                    'programs' => $prototype->get('encounterReferralPrograms'),
                ],
                'selected' => [
                    'html' => $selected->html(), 'form' => $selected->get('encounterReferralForm'),
                    'programs' => $selected->get('encounterReferralPrograms'),
                ],
                'drawerHeaders' => [Livewire::test(\App\Livewire\Components\Header::class)->html(), Livewire::test(\App\Livewire\Components\Header::class)->html(), Livewire::test(\App\Livewire\Components\Header::class)->html()],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
    }
    public function test_medical_record_picker_is_scoped_to_the_patient_and_deduplicates_selections(): void
    {
        $own = $this->episode($this->person);
        $other = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE']);
        $foreign = $this->episode($other);
        $component = $this->referralComponent()->call('loadReferralReferenceOptions');
        $options = $component->get('encounterReferralReferenceOptions');
        $this->assertArrayHasKey('episode:'.$own->uuid, $options);
        $this->assertArrayNotHasKey('episode:'.$foreign->uuid, $options);
        $component->call('addReferralReference', 'supporting_info', 'episode', $own->uuid)
            ->call('addReferralReference', 'supporting_info', 'episode', $own->uuid);
        $this->assertCount(1, $component->get('encounterReferralForm')['supporting_info']);
        $component->call('removeReferralReference', 'supporting_info', 'episode', $own->uuid)->assertSet('encounterReferralForm.supporting_info', []);
    }

    public function test_episode_cannot_be_added_as_a_clinical_reason(): void
    {
        $episode = $this->episode($this->person);
        $this->referralComponent()->call('addReferralReference', 'reason_reference', 'episode', $episode->uuid)->assertStatus(404);
    }

    public function test_changing_quantity_unit_updates_the_fhir_unit_label(): void
    {
        $this->referralComponent()->set('encounterReferralForm.quantity_code', 'SESSION')
            ->assertSet('encounterReferralForm.quantity_unit', 'сеанс');
    }

    public function test_draft_preserves_unit_and_supporting_episode_in_fhir_storage(): void
    {
        $episode = $this->episode($this->person);
        $component = $this->referralComponent()->set('encounterReferralForm.quantity_code', 'SESSION')
            ->call('addReferralReference', 'supporting_info', 'episode', $episode->uuid)->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $record = ServiceRequestRequest::wherePersonId($this->person->id)->firstOrFail();
        $resource = Repository::serviceRequest()->getResource($record);
        $this->assertSame('SESSION', data_get($resource, 'quantity.code'));
        $this->assertSame('сеанс', data_get($resource, 'quantity.unit'));
        $this->assertSame($episode->uuid, data_get($resource, 'supportingInfo.0.identifier.value'));
        $this->assertSame('draft', $record->status);
    }

    public function test_forged_reference_is_rejected_before_draft_creation(): void
    {
        $other = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE']);
        $foreign = $this->episode($other);
        $this->referralComponent()->set('encounterReferralForm.supporting_info', [['type' => 'episode', 'uuid' => $foreign->uuid]])
            ->call('saveEncounterReferralDraft')->assertHasErrors(['encounterReferralForm.supporting_info']);
        $this->assertSame(0, ServiceRequestRequest::wherePersonId($this->person->id)->count());
    }

    public function test_program_field_uses_active_service_programs_and_their_backend_settings(): void
    {
        $withPlan = (string) Str::uuid();
        $withoutPlan = (string) Str::uuid();
        $basics = dictionary()->basics();
        $manager = \Mockery::mock(\App\Services\Dictionary\DictionaryManager::class);
        $manager->shouldReceive('basics')->andReturn($basics);
        $manager->shouldReceive('medicalPrograms')->once()->andReturn(collect([
            ['id' => $withoutPlan, 'name' => 'Послуги без плану', 'is_active' => true, 'type' => 'SERVICE',
                'medical_program_settings' => ['care_plan_required' => false]],
            ['id' => $withPlan, 'name' => 'Програма медичних гарантій', 'isActive' => true, 'type' => 'SERVICE',
                'medicalProgramSettings' => ['carePlanRequired' => true]],
            ['id' => (string) Str::uuid(), 'name' => 'Ліки', 'is_active' => true, 'type' => 'MEDICATION'],
            ['id' => (string) Str::uuid(), 'name' => 'Неактивна', 'is_active' => false, 'type' => 'SERVICE'],
        ]));
        $this->instance(\App\Services\Dictionary\DictionaryManager::class, $manager);
        $component = $this->referralComponent()->call('reloadProgramsForLayoutTest');
        $programs = $component->get('encounterReferralPrograms');
        $this->assertSame([$withoutPlan, $withPlan], array_column($programs, 'id'));
        $this->assertFalse($programs[0]['medical_program_settings']['care_plan_required']);
        $this->assertTrue($programs[1]['medical_program_settings']['care_plan_required']);
        $component->assertSet('encounterReferralForm.program_id', $withPlan);
        $component->assertSee('Послуги без плану')->assertSee('Програма медичних гарантій');
    }

    public function test_programs_can_load_through_the_authenticated_api_when_service_programs_are_not_cached(): void
    {
        $id = (string) Str::uuid();
        $basics = dictionary()->basics();
        $manager = \Mockery::mock(\App\Services\Dictionary\DictionaryManager::class);
        $manager->shouldReceive('basics')->andReturn($basics);
        $manager->shouldReceive('medicalPrograms')->once()->andReturn(collect([['id' => (string) Str::uuid(), 'name' => 'Ліки', 'is_active' => true, 'type' => 'MEDICATION']]));
        $this->instance(\App\Services\Dictionary\DictionaryManager::class, $manager);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-user-token');
        $responder = fn () => Http::response(['data' => [[
            'id' => $id, 'name' => 'Програма з API', 'is_active' => true, 'type' => 'SERVICE',
            'medical_program_settings' => ['care_plan_required' => true],
        ]]]);
        Http::fake($responder);
        $api = new \App\Classes\eHealth\Api\MedicalProgram(Http::getFacadeRoot());
        $api->stub([$responder])->preventStrayRequests();
        $this->instance(\App\Classes\eHealth\Api\MedicalProgram::class, $api);
        $component = $this->referralComponent()->call('reloadProgramsForLayoutTest');
        $this->assertSame($id, $component->get('encounterReferralPrograms')[0]['id']);
        Http::assertSent(fn ($request) => $request['type'] === 'SERVICE' && (string) $request['is_active'] === '1');
    }

    public function test_service_programs_load_from_api_after_a_cache_read_failure(): void
    {
        $id = (string) Str::uuid();
        $basics = dictionary()->basics();
        $manager = \Mockery::mock(\App\Services\Dictionary\DictionaryManager::class);
        $manager->shouldReceive('basics')->andReturn($basics);
        $manager->shouldReceive('medicalPrograms')->once()->andThrow(new \RuntimeException('Unavailable cache'));
        $this->instance(\App\Services\Dictionary\DictionaryManager::class, $manager);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-user-token');
        $responder = fn () => Http::response(['data' => [[
            'id' => $id, 'name' => 'API program', 'is_active' => true, 'type' => 'SERVICE',
            'medical_program_settings' => ['care_plan_required' => false],
        ]]]);
        Http::fake($responder);
        $api = new \App\Classes\eHealth\Api\MedicalProgram(Http::getFacadeRoot());
        $api->stub([$responder])->preventStrayRequests();
        $this->instance(\App\Classes\eHealth\Api\MedicalProgram::class, $api);
        $component = $this->referralComponent()->call('reloadProgramsForLayoutTest');
        $this->assertSame($id, $component->get('encounterReferralPrograms')[0]['id']);
        Http::assertSent(fn ($request) => $request['type'] === 'SERVICE' && (string) $request['is_active'] === '1');
    }

    public function test_auth_method_selection_uses_the_canonical_value_and_survives_draft_storage(): void
    {
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        $methods = [
            ['uuid' => $first, 'type' => 'OTP', 'phone_number' => '+380000000001', 'label' => 'First method'],
            ['uuid' => $second, 'raw' => $second.'|OTP|+380000000002', 'label' => 'Second method'],
        ];
        $component = $this->referralComponent()->set('encounterReferralAuthMethods', $methods);
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $select = (new \DOMXPath($document))->query('//*[@id="encounterReferralInformWith"]')->item(0);
        $this->assertSame('encounterReferralForm.inform_with', $select->getAttribute('wire:model'));
        $options = [];
        foreach ($select->getElementsByTagName('option') as $option) {
            $options[] = $option->getAttribute('value');
        }
        $this->assertSame(['', \App\Services\MedicalEvents\InformWith::formValue($methods[0]), \App\Services\MedicalEvents\InformWith::formValue($methods[1])], $options);
        $component->set('encounterReferralForm.inform_with', $options[2])
            ->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $record = ServiceRequestRequest::wherePersonId($this->person->id)->sole();
        $this->assertSame($second, data_get(Repository::serviceRequest()->getResource($record), 'informWith.authMethodId'));
        $this->referralComponent(true)->assertDontSeeHtml('id="encounterReferralInformWith"');
    }

    public function test_medical_record_picker_accepts_keyed_collections(): void
    {
        $records = collect(['keyed-record' => [
            'uuid' => 'visible-record', 'type' => 'condition', 'typeLabel' => 'Condition',
            'label' => 'Visible collection record', 'date' => '19.11.2026',
        ]]);
        $html = \Illuminate\Support\Facades\Blade::render(
            '<x-encounter.medical-record-picker :records="$records" selection-event="record-picked" is-added-check="false" />',
            compact('records')
        );
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $this->assertCount(1, (new \DOMXPath($document))->query('//*[@data-record-row]'));
        $this->assertStringContainsString('Visible collection record', $html);
    }

    public function test_record_picker_resolves_patient_episodes_and_uses_medical_record_dates(): void
    {
        config(['ehealth.condition.summary_conditions_allowed' => ['A98']]);
        $code = CodeableConcept::create(['text' => 'Профілактика']);
        $code->coding()->create(['code' => 'A98', 'system' => 'eHealth/ICPC2/condition_codes', 'display' => 'Профілактика']);
        $condition = \App\Models\MedicalEvents\Sql\Condition::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'primary_source' => true,
            'context_id' => Identifier::create(['value' => $this->encounter->uuid])->id, 'code_id' => $code->id,
            'clinical_status' => 'active', 'verification_status' => 'confirmed', 'onset_date' => '2026-05-08', 'ehealth_inserted_at' => '2026-05-08 10:00:00',
        ]);
        $component = $this->referralComponent()->call('loadReferralReferenceOptions');
        $record = $component->get('encounterReferralReferenceOptions')['condition:'.$condition->uuid];
        $this->assertSame($this->encounter->episode->value, $record['episode_uuid']);
        $this->assertSame('08.05.2026', $record['date']);
        $component->call('addReferralReference', 'reason_reference', 'condition', $condition->uuid);
        $this->assertSame($condition->uuid, $component->get('encounterReferralForm')['reason_reference'][0]['uuid']);
    }

    public function test_preview_program_is_local_only_and_cannot_create_a_draft(): void
    {
        $basics = dictionary()->basics();
        $manager = \Mockery::mock(\App\Services\Dictionary\DictionaryManager::class);
        $manager->shouldReceive('basics')->andReturn($basics);
        $manager->shouldReceive('medicalPrograms')->andReturn(collect());
        $this->instance(\App\Services\Dictionary\DictionaryManager::class, $manager);
        $originalEnvironment = app()->environment();

        try {
            app()->instance('env', 'local');
            $component = $this->referralComponent()->call('reloadProgramsForLayoutTest');
            $program = $component->get('encounterReferralPrograms')[0];
            $this->assertSame('preview-service-program', $program['id']);
            $this->assertTrue($program['is_demo']);
            $this->assertTrue($program['medical_program_settings']['care_plan_required']);
            $component->assertSet('encounterReferralForm.program_id', '')
                ->set('encounterReferralForm.program_id', 'preview-service-program')
                ->call('saveEncounterReferralDraft')->assertHasErrors(['encounterReferralForm.program_id']);
            $this->assertSame(0, ServiceRequestRequest::wherePersonId($this->person->id)->count());

            app()->instance('env', 'production');
            $component->call('reloadProgramsForLayoutTest')->assertSet('encounterReferralPrograms', []);
            Http::assertNothingSent();
        } finally {
            app()->instance('env', $originalEnvironment);
        }
    }
    public function test_selected_reference_tables_group_episodes_and_remove_only_the_requested_reference(): void
    {
        $component = $this->referralComponent()->call('seedSelectedReferencesForLayoutTest');
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new \DOMXPath($document);
        $this->assertCount(2, $xpath->query('//table[@data-referral-reference-table]'));
        foreach (['reason_reference:reason' => 1, 'supporting_info:supporting' => 3] as $group => $rows) {
            $table = '//*[@data-referral-reference-table="'.$group.'"]';
            $this->assertCount($rows, $xpath->query($table.'/tbody/tr'));
            $headers = [];
            foreach ($xpath->query($table.'/thead/tr/th') as $header) {
                $headers[] = trim($header->textContent);
            }
            $this->assertSame([__('forms.date'), __('forms.name'), __('forms.action')], $headers);
        }
        $this->assertSame('08.05.2026', trim($xpath->query('//*[@data-referral-reference-table="reason_reference:reason"]/tbody/tr/td[1]')->item(0)->textContent));
        $references = $component->get('encounterReferralReferenceOptions');
        $component->call('removeReferralReference', 'supporting_info', 'diagnostic_report', $references['diagnostic_report:c']['uuid']);
        $this->assertCount(2, $component->get('encounterReferralForm')['supporting_info']);
        $this->assertCount(1, $component->get('encounterReferralForm')['reason_reference']);

        $component->call('removeReferralReference', 'supporting_info', 'episode', $references['episode:d']['uuid']);
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new \DOMXPath($document);
        $this->assertCount(1, $xpath->query('//*[@data-referral-reference-table="supporting_info:supporting"]'));
        $this->assertCount(1, $xpath->query('//*[@data-referral-reference-table="supporting_info:supporting"]/tbody/tr'));
        $this->assertCount(1, $xpath->query('//*[@data-reference-field="supporting_info"]//*[@data-reference-add="supporting"]'));
        $this->assertSame($references['observation:b']['uuid'], $component->get('encounterReferralForm')['supporting_info'][0]['uuid']);
    }
    public function test_frontend_prototype_renders_preview_and_signature_without_clinical_submit_bindings(): void
    {
        $component = $this->referralComponent(true);
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new \DOMXPath($document);
        $component->assertSeeHtml('data-referral-prototype-root')->assertSeeHtml('data-referral-prototype-review');
        $form = $xpath->query('//form[@class="space-y-6"]')->item(0);
        $this->assertFalse($form->hasAttribute('wire:submit.prevent'));
        $quantityUnit = $xpath->query('//*[@id="encounterReferralQuantityUnit"]')->item(0);
        $this->assertFalse($quantityUnit->hasAttribute('wire:model.live'));
        $this->assertSame('encounterReferralForm.quantity_code', $quantityUnit->getAttribute('wire:model'));
        $this->assertSame('Помилка', trim($xpath->query('//*[@id="referralPreviewErrorTitle"]')->item(0)->textContent));
        $component->assertDontSee('forms.or_upload_from_device');
        $this->assertCount(2, $xpath->query('//form[@class="space-y-6"]//button[contains(@class, "item-add")]'));
        foreach (['Knedp', 'KeyFile', 'Password'] as $suffix) {
            $input = $xpath->query('//*[@id="referralPreview'.$suffix.'"]')->item(0);
            $this->assertNotNull($input);
            $this->assertFalse($input->hasAttribute('wire:model'));
        }
        $this->assertSame(0, ServiceRequestRequest::wherePersonId($this->person->id)->count());
        Http::assertNothingSent();
    }
    public function test_reused_signature_drawer_preserves_real_merge_bindings(): void
    {
        $signature = \Mockery::mock(\App\Contracts\SignatureProvider::class);
        $signature->shouldReceive('getCertificateAuthorities')->once()->andReturn([]);
        $this->instance(\App\Contracts\SignatureProvider::class, $signature);
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());
        $html = view('livewire.preperson.parts.drawers.merge-signature', ['errors' => new \Illuminate\Support\ViewErrorBag()])->render();
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        foreach (['mergeKnedp' => 'form.knedp', 'mergeKeyFile' => 'form.keyContainerUpload', 'mergePassword' => 'form.password'] as $id => $property) {
            $this->assertSame($property, $xpath->query('//*[@id="'.$id.'"]')->item(0)->getAttribute('wire:model'));
        }
        $this->assertCount(1, $xpath->query('//button[@*[name()="wire:click" and .="sign"]]'));
        $this->assertStringContainsString('showMergeFinalConsentDrawer = true', $html);
        Http::assertNothingSent();
    }
    public function test_available_observations_and_reports_do_not_need_summary_code_configuration(): void
    {
        config(['ehealth.observation.summary_observations_allowed' => [], 'ehealth.diagnostic_report.summary_diagnostic_reports_allowed' => []]);
        $code = CodeableConcept::create(['text' => 'Available observation']);
        $code->coding()->create(['code' => 'test', 'system' => 'test']);
        $context = Identifier::create(['value' => $this->encounter->uuid]);
        $observations = [];
        for ($index = 0; $index < 23; $index++) {
            $observations[] = \App\Models\MedicalEvents\Sql\Observation::create([
                'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'status' => 'valid',
                'code_id' => $code->id, 'context_id' => $context->id, 'primary_source' => true,
                'issued' => '2026-11-01T09:00:00Z', 'effective_date_time' => '2026-11-01T09:00:00Z',
            ]);
        }
        $report = \App\Models\MedicalEvents\Sql\DiagnosticReport::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'status' => 'final',
            'code_id' => Identifier::create(['value' => (string) Str::uuid(), 'display_value' => 'Available diagnostic report'])->id, 'encounter_id' => $context->id, 'primary_source' => true, 'recorded_by_id' => $this->encounter->performer_id,
            'issued' => '2026-11-01T09:00:00Z', 'effective_date_time' => '2026-11-01T09:00:00Z',
        ]);
        $component = $this->referralComponent()->call('loadReferralReferenceOptions', 'supporting_info')
            ->call('searchReferralReferenceOptions', '', 'observation')->assertSet('encounterReferralReferenceHasMore', true);
        $this->assertCount(20, $component->get('encounterReferralReferenceOptions'));
        // Access checks query the selected UUID even when it is absent from the current picker page.
        $loadedUuids = array_column($component->get('encounterReferralReferenceOptions'), 'uuid');
        $outsidePage = collect($observations)->first(fn ($observation): bool => !in_array($observation->uuid, $loadedUuids, true));
        $this->assertNotNull($outsidePage);
        $this->assertNotContains($outsidePage->uuid, $loadedUuids);
        $component->call('addReferralReference', 'supporting_info', 'observation', $outsidePage->uuid);
        $this->assertSame($outsidePage->uuid, $component->get('encounterReferralForm')['supporting_info'][0]['uuid']);
        $component->call('loadMoreReferralReferenceOptions')->assertSet('encounterReferralReferenceHasMore', false);
        $this->assertCount(23, $component->get('encounterReferralReferenceOptions'));
        $component->call('searchReferralReferenceOptions', 'Available diagnostic report', 'diagnostic_report');
        $this->assertArrayHasKey('diagnostic_report:'.$report->uuid, $component->get('encounterReferralReferenceOptions'));
        $component->call('addReferralReference', 'supporting_info', 'diagnostic_report', $report->uuid)
            ->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $this->assertCount(2, Repository::serviceRequest()->getResource(ServiceRequestRequest::wherePersonId($this->person->id)->sole())['supportingInfo']);
    }

    public function test_reason_filter_can_load_older_episodes_independently_of_record_types(): void
    {
        for ($index = 0; $index < 23; $index++) {
            $this->episode($this->person);
        }
        $component = $this->referralComponent()->call('loadReferralReferenceOptions', 'reason_reference')
            ->assertSet('encounterReferralEpisodesHasMore', true);
        $this->assertCount(20, $component->get('encounterReferralEpisodes'));
        $loaded = array_column($component->get('encounterReferralEpisodes'), 'uuid');
        $oldest = Episode::forPatient($this->person)->orderBy('id')->first();
        $this->assertNotContains($oldest->uuid, $loaded);
        $component->call('loadMoreReferralEpisodeOptions')->assertSet('encounterReferralEpisodesHasMore', false);
        $this->assertCount(24, $component->get('encounterReferralEpisodes'));
        $this->assertContains($oldest->uuid, array_column($component->get('encounterReferralEpisodes'), 'uuid'));
        $component->call('searchReferralReferenceOptions', '', 'condition', $oldest->uuid)
            ->assertSet('encounterReferralReferenceEpisode', $oldest->uuid);
    }

    public function test_quantity_unit_is_derived_from_the_validated_code(): void
    {
        $this->referralComponent()->set('encounterReferralForm.quantity_unit', 'forged-unit')
            ->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $resource = Repository::serviceRequest()->getResource(ServiceRequestRequest::wherePersonId($this->person->id)->sole());
        $this->assertSame('PIECE', data_get($resource, 'quantity.code'));
        $this->assertSame(dictionary()->basics()->byName('SERVICE_UNIT')->asCodeDescription()->all()['PIECE'], data_get($resource, 'quantity.unit'));
    }

    public function test_new_reference_uses_the_checked_pair_even_with_a_forged_identifier(): void
    {
        $episode = $this->episode($this->person);
        $foreign = (string) Str::uuid();
        $this->referralComponent()->set('encounterReferralForm.supporting_info', [[
            'type' => 'episode', 'uuid' => $episode->uuid,
            'identifier' => ['value' => $foreign, 'type' => ['coding' => [['code' => 'condition', 'system' => 'eHealth/resources']]]],
        ]])->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $record = ServiceRequestRequest::wherePersonId($this->person->id)->sole();
        $reference = Repository::serviceRequest()->getResource($record)['supportingInfo'][0];
        $this->assertSame($episode->uuid, data_get($reference, 'identifier.value'));
        $this->assertSame('episode', data_get($reference, 'identifier.type.coding.0.code'));
        $this->assertStringNotContainsString($foreign, json_encode($reference));
        $this->assertArrayNotHasKey('uuid', $reference);
    }

    public function test_legacy_reference_projection_survives_validation_and_cannot_be_replaced(): void
    {
        $component = $this->referralComponent()->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $legacy = ['displayValue' => 'Existing medical record description'];
        // This is the form projection used for display-only references by the DTO in #1230.
        $component->call('loadLegacyReferencesForTest', [$legacy])
            ->set('encounterReferralForm.note', 'Edited note')->call('validateReferencesForTest')->assertHasNoErrors();
        $this->assertSame([$legacy], $component->get('encounterReferralForm')['supporting_info']);
        $this->assertSame('Edited note', $component->get('encounterReferralForm')['note']);
        $component->set('encounterReferralForm.supporting_info', [['displayValue' => 'Forged description']])
            ->call('saveEncounterReferralDraft')->assertHasErrors(['encounterReferralForm.supporting_info']);
    }

    public function test_text_only_references_are_visible_and_removed_individually(): void
    {
        $first = ['displayValue' => 'First saved reference'];
        $second = ['displayValue' => 'Second saved reference'];
        $component = $this->referralComponent()->call('loadLegacyReferencesForTest', [$first, $second])
            ->assertSee('First saved reference')->assertSee('Second saved reference');
        $component->call('removeReferralReference', 'supporting_info', '', '', md5(json_encode($second)))
            ->assertSet('encounterReferralForm.supporting_info', [$first]);
        $component->call('validateReferencesForTest')->assertHasNoErrors();
    }

    private function referralComponent(bool $prototype = false)
    {
        Livewire::component('referral-layout-fixture', ReferralLayoutFixture::class);

        return Livewire::test('referral-layout-fixture', ['personId' => $this->person->id, 'encounterId' => $this->encounter->id, 'legalEntityId' => $this->legalEntity->id, 'referralPrototypeUi' => $prototype]);
    }

    private function episode(Person $person): Episode
    {
        return Episode::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'active', 'name' => 'Епізод для направлення',
            'managing_organization_id' => Identifier::create(['value' => $this->legalEntity->uuid])->id]);
    }
}

class ReferralLayoutFixture extends EncounterReferralCreate
{
    public bool $referralPrototypeUi = false;
    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null, ?Encounter $encounter = null, ?int $referral = null): void
    {
        $this->uuid = Person::findOrFail($this->personId)->uuid;
        $this->encounterUuid = Encounter::findOrFail($this->encounterId)->uuid;
        $this->patientFullName = 'Тестовий пацієнт';
        $start = now()->addMinutes(30)->startOfMinute();
        $this->encounterReferralPrograms = [['id' => (string) Str::uuid(), 'name' => 'Програма медичних гарантій', 'medical_program_settings' => ['care_plan_required' => false]]];
        $this->encounterReferralForm = [
            'service_id' => (string) Str::uuid(), 'category' => 'diagnostic_procedure', 'priority' => 'routine',
            'quantity' => 1, 'quantity_code' => 'PIECE', 'quantity_unit' => 'шт.', 'quantity_system' => 'SERVICE_UNIT',
            'started_at' => $start->format('d.m.Y'), 'ended_at' => $start->copy()->addMonths(3)->format('d.m.Y'),
            'start_time' => $start->format('H:i'), 'end_time' => $start->format('H:i'),
            'program_id' => $this->encounterReferralPrograms[0]['id'], 'note' => '', 'patient_instruction' => '', 'inform_with' => '',
            'reason_reference' => [], 'supporting_info' => [], 'performer' => '', 'location_reference' => '', 'performer_type' => '',
        ];
    }
    public function validateReferencesForTest(): void
    {
        $this->validateEncounterReferralReferences();
    }

    public function loadLegacyReferencesForTest(array $references): void
    {
        $this->encounterReferralOriginalReferences = ['supporting_info' => $references];
        $this->encounterReferralForm['supporting_info'] = $references;
    }

    public function reloadProgramsForLayoutTest(): void
    {
        $this->loadEncounterReferralPrograms();
        $this->encounterReferralForm['program_id'] = $this->resolveDefaultEncounterReferralProgramId();
    }
    public function seedDemoProgramForLayoutExport(): void
    {
        $this->encounterReferralPrograms[] = [
            'id' => 'preview-service-program', 'name' => __('encounters.referral_layout.test_program'),
            'is_demo' => true, 'medical_program_settings' => ['care_plan_required' => true],
        ];
    }
    public function seedSelectedReferencesForLayoutTest(): void
    {
        $this->seedRecordPickerForLayoutExport();
        $this->seedDemoProgramForLayoutExport();
        $this->encounterReferralForm['reason_reference'] = [$this->encounterReferralReferenceOptions['condition:a']];
        $this->encounterReferralForm['supporting_info'] = [
            $this->encounterReferralReferenceOptions['episode:d'],
            $this->encounterReferralReferenceOptions['diagnostic_report:c'],
            $this->encounterReferralReferenceOptions['observation:b'],
        ];
    }
    public function seedRecordPickerForLayoutExport(): void
    {
        $episodeA = '70a9e15b-b71b-4caf-8f2e-ff247e8a5600';
        $episodeB = '70a9e15b-b71b-4caf-8f2e-ff247e8a5601';
        $this->encounterReferralEpisodes = [
            ['uuid' => $episodeA, 'display' => 'Профілактика (Активний) від 08.05.2026'],
            ['uuid' => $episodeB, 'display' => 'Обстеження (Активний) від 08.06.2026'],
        ];
        $this->encounterReferralReferenceOptions = [
            'condition:a' => ['uuid' => '70a9e15b-b71b-4caf-8f2e-ff247e8a5602', 'type' => 'condition', 'typeLabel' => 'Стан/діагноз', 'label' => 'A98 Підтримання здоров’я / Профілактика', 'date' => '08.05.2026', 'episode_uuid' => $episodeA],
            'observation:b' => ['uuid' => '70a9e15b-b71b-4caf-8f2e-ff247e8a5603', 'type' => 'observation', 'typeLabel' => 'Спостереження', 'label' => 'Лабораторне спостереження', 'date' => '08.06.2026', 'episode_uuid' => $episodeB],
            'diagnostic_report:c' => ['uuid' => '70a9e15b-b71b-4caf-8f2e-ff247e8a5604', 'type' => 'diagnostic_report', 'typeLabel' => 'Діагностичний звіт', 'label' => 'Комп’ютерна томографія', 'date' => '08.05.2026', 'episode_uuid' => $episodeA],
            'episode:d' => ['uuid' => $episodeA, 'type' => 'episode', 'typeLabel' => 'Епізод', 'label' => 'Профілактика', 'date' => '08.05.2026', 'episode_uuid' => $episodeA],
        ];
    }
}
