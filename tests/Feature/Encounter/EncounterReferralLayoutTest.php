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
        $backLinks = $xpath->query('//header[contains(concat(" ", normalize-space(@class), " "), " page-header ")]//a[contains(concat(" ", normalize-space(@class), " "), " button-minor ")]');
        $this->assertCount(1, $backLinks);
        $this->assertSame(route('persons.encounters.package.show', [
            'legalEntity' => $this->legalEntity->id, 'person' => $this->person->id, 'encounter' => $this->encounter->id,
        ]), $backLinks->item(0)->getAttribute('href'));
        $this->assertSame(__('forms.back'), trim($backLinks->item(0)->textContent));
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
            $this->assertSame('text', $xpath->query('//*[@id="'.$id.'"]')->item(0)->getAttribute('type'));
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
            \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('cli-testing'));
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
    public function test_auth_method_options_render_the_canonical_value(): void
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

    public function test_selected_reference_tables_show_dates_and_groups(): void
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
    }
    public function test_frontend_prototype_renders_preview_and_signature_without_clinical_submit_bindings(): void
    {
        $component = $this->referralComponent(true);
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new \DOMXPath($document);
        $component->assertSeeHtml('data-referral-prototype-root')->assertSeeHtml('data-referral-prototype-review');
        $this->assertCount(0, $xpath->query('//header[contains(concat(" ", normalize-space(@class), " "), " page-header ")]//a[contains(concat(" ", normalize-space(@class), " "), " button-minor ")]'));
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
        $this->assertCount(1, $xpath->query('//select[@*[name()="wire:model" and .="form.knedp"]]'));
        $this->assertCount(1, $xpath->query('//*[@data-method="sign"]'));
        foreach (['//input[@type="file"]', '//input[@type="password"]'] as $query) {
            $this->assertFalse($xpath->query($query)->item(0)->hasAttribute('wire:model'));
        }
        $this->assertCount(1, $xpath->query('//button[@*[name()="wire:click" and .="sign"]]'));
        $this->assertStringContainsString('showMergeFinalConsentDrawer = true', $html);
        Http::assertNothingSent();
    }
    public function test_text_only_references_are_visible(): void
    {
        $first = ['displayValue' => 'First saved reference'];
        $second = ['displayValue' => 'Second saved reference'];
        $this->referralComponent()->set('encounterReferralForm.supporting_info', [$first, $second])
            ->assertSee('First saved reference')->assertSee('Second saved reference');
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
    public array $encounterReferralReferenceOptions = [];
    public array $encounterReferralEpisodes = [];
    public bool $encounterReferralReferenceHasMore = false;
    public bool $encounterReferralEpisodesHasMore = false;

    // The browser simulates remote results; the route test mounts the real component.
    public function searchReferralReferenceOptions(string $query = '', string $type = '', string $episode = ''): void
    {
        throw new \LogicException('The browser picker fixture must not send a server request.');
    }

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
