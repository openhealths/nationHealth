<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Livewire\Person\Records\PatientSummary;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class PatientSummaryViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('uk');
        $this->withoutExceptionHandling();
        $this->withoutVite();
        $this->actingAs(User::factory()->make(['id' => 1]));
        Gate::before(static fn (): bool => false);
        $this->instance('legalEntity', (new LegalEntity())->forceFill(['id' => 1, 'uuid' => '00000000-0000-4000-8000-000000000001']));
        Livewire::component('patient-summary-view-fixture', PatientSummaryViewFixture::class);
    }

    public function test_loaded_cards_and_pagination_are_still_rendered_after_a_refresh_error(): void
    {
        $component = Livewire::test('patient-summary-view-fixture')->assertSee('Контроль глікемії');
        $component->call('failRefresh');
        $html = $component->html();
        $xpath = $this->xpath($html);
        $observation = '//*[@id="block-observations"]';
        $this->assertSame(1, $xpath->query('//*[@role="alert" and contains(., "Не вдалося оновити записи")]')->length);
        $this->assertSame(1, $xpath->query($observation.'//article[contains(., "Контроль глікемії")]')->length);
        $values = $xpath->evaluate('string('.$observation.'//dt[normalize-space(.)="Значення"]/following-sibling::dd[1])');
        $this->assertSame("< 0 ммоль/л\nНі", trim($values));
        $this->assertSame(1, $xpath->query($observation.'//button[normalize-space(.)="'.__('general.show_more').'"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="block-immunizations"]//button[normalize-space(.)="'.__('general.show_more').'"]')->length);

        $empty = '//*[@id="block-episodes"]';
        $this->assertSame(1, $xpath->query($empty.'//*[@role="status"]/p[normalize-space(.)="'.__('forms.no_data').'"]')->length);
        $this->assertSame(0, $xpath->query($empty.'//article')->length);

        // The diagnosis row ID is not a Condition ID. Its base fields still render without a detail link.
        $diagnosis = '//*[@id="block-diagnoses"]//article[contains(., "Первинний діагноз")]';
        $this->assertSame(1, $xpath->query($diagnosis)->length);
        $this->assertSame(0, $xpath->query($diagnosis.'//a[@href]')->length);

        $impression = '//*[@id="block-clinicalImpressions"]//article';
        foreach ([
            __('forms.ehealth_id') => '00000000-0000-4000-8000-000000000005',
            __('encounters.episode_id') => '00000000-0000-4000-8000-000000000006',
        ] as $label => $uuid) {
            $this->assertSame($uuid, $xpath->evaluate('normalize-space('.$impression.'//div[normalize-space(.)="'.$label.'"]/following-sibling::div[1])'));
        }

        foreach (['Попередній стан' => 'попередній', 'Диференціальний стан' => 'диференціальний'] as $title => $status) {
            $condition = '//*[@id="block-conditions"]//article[.//div[normalize-space(.)="'.$title.'"]]';
            foreach ([
                'Джерело інформації' => 'Зі слів пацієнта',
                'Статус верифікації' => $status,
                __('patient-summary.diagnosis_role') => 'Основний',
            ] as $label => $value) {
                $this->assertSame($value, $xpath->evaluate('normalize-space('.$condition.'//dt[normalize-space(.)="'.$label.'"]/following-sibling::dd[1])'));
            }
        }

        $deferred = $xpath->query('//*[starts-with(@id, "block-") and .//*[@data-summary-unavailable]]');
        $this->assertCount(12, $deferred);
        foreach ($deferred as $section) {
            $this->assertSame(0, $xpath->query('.//article | .//*[@*[starts-with(name(), "wire:click")] or @*[contains(., "$wire.")]]', $section)->length, $section->getAttribute('id'));
        }

        // Playwright consumes this exact render, including the notification and existing records.
        if ($fixturePath = getenv('SUMMARY_UI_EXPORT_PATH')) {
            $this->assertNotFalse(file_put_contents($fixturePath, $html));
        }
    }

    public function test_episode_menu_only_offers_edit_for_a_manageable_active_episode_without_a_pending_submission(): void
    {
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(static fn () => DB::rollBack());
        config(['ehealth.episode.allow_other_le_employees_to_manage_episode' => false]);
        $doctor = Employee::factory()->create();
        $colleague = Employee::factory()->create();
        $this->actingAs(User::factory()->make(['id' => 1, 'party_id' => $doctor->partyId]));

        $episodes = [
            ['id' => 21, 'name' => 'Власний', 'status' => 'active', 'careManager' => ['identifier' => ['value' => $doctor->uuid]]],
            ['id' => 22, 'name' => 'Інший лікар', 'status' => 'active', 'careManager' => ['identifier' => ['value' => $colleague->uuid]]],
            ['id' => 23, 'name' => 'На опрацюванні', 'status' => 'active', 'careManager' => ['identifier' => ['value' => $doctor->uuid]], 'hasPendingSubmission' => true],
            ['id' => 24, 'name' => 'Закритий', 'status' => 'closed', 'careManager' => ['identifier' => ['value' => $doctor->uuid]]],
        ];
        $html = Livewire::test('patient-summary-view-fixture')->set('episodes', $episodes)->html();
        $xpath = $this->xpath($html);

        foreach ($episodes as $episode) {
            $card = '//*[@id="block-episodes"]//article[.//div[normalize-space(.)="'.$episode['name'].'"]]';
            $parameters = [legalEntity(), 'person' => 1, 'episode' => $episode['id']];
            $view = route('persons.episodes.view', $parameters);
            $this->assertSame(1, $xpath->query($card.'//a[@href="'.$view.'"]')->length, $episode['name']);

            $editLinks = $xpath->query($card.'//a[normalize-space(.)="'.__('forms.edit').'"]');
            if ($episode['id'] === 21) {
                $this->assertCount(1, $editLinks);
                $this->assertSame(route('persons.episodes.edit', $parameters), $editLinks->item(0)->getAttribute('href'));
            } else {
                $this->assertCount(0, $editLinks, $episode['name']);
            }
        }
    }

    public function test_diagnosis_cards_have_unique_stable_keys_when_they_reference_the_same_condition(): void
    {
        $diagnosis = [
            'condition' => ['identifier' => ['value' => '00000000-0000-4000-8000-000000000008']],
            'code' => ['text' => 'Спільний стан'],
            'role' => ['text' => 'Основний'],
        ];
        $diagnoses = [$diagnosis + ['rank' => 1], $diagnosis + ['rank' => 2]];
        $component = Livewire::test('patient-summary-view-fixture')->set('diagnoses', $diagnoses);
        $keys = $this->diagnosisKeys($component->html());
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);

        $component->call('failRefresh');
        $this->assertSame($keys, $this->diagnosisKeys($component->html()));

        $component->set('diagnoses', array_reverse($diagnoses));
        $this->assertSame(array_reverse($keys), $this->diagnosisKeys($component->html()));

        $component->set('diagnoses', [$diagnoses[0], $diagnoses[0], $diagnoses[1]]);
        $duplicateKeys = $this->diagnosisKeys($component->html());
        $this->assertCount(3, $duplicateKeys);
        $this->assertCount(3, array_unique($duplicateKeys));
        $this->assertSame($keys[0], $duplicateKeys[0]);
        $this->assertSame($keys[1], $duplicateKeys[2]);
    }

    private function diagnosisKeys(string $html): array
    {
        $cards = $this->xpath($html)->query('//*[@id="block-diagnoses"]//article');

        return array_map(static fn ($card): string => $card->getAttribute('wire:key'), iterator_to_array($cards));
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }
}

class PatientSummaryViewFixture extends PatientSummary
{
    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null): void
    {
        $this->personId = 1;
        $this->patientFullName = 'Тестовий пацієнт';
        $this->uuid = '00000000-0000-4000-8000-000000000002';
        $this->observations = [[
            'uuid' => '00000000-0000-4000-8000-000000000003',
            'status' => 'valid',
            'code' => ['text' => 'Контроль глікемії'],
            'value' => ['valueQuantity' => ['value' => 0, 'unit' => 'ммоль/л', 'comparator' => '<']],
            'components' => [['value' => ['valueBoolean' => false]]],
        ]];
        $this->immunizations = [[
            'uuid' => '00000000-0000-4000-8000-000000000004',
            'status' => 'completed',
            'vaccine_code' => ['text' => 'Вакцина'],
            'dose_quantity' => ['value' => 3, 'unit' => 'мл'],
        ]];
        $this->clinicalImpressions = [[
            'uuid' => '00000000-0000-4000-8000-000000000005',
            'status' => 'completed',
            'supportingInfo' => [
                ['identifier' => ['type' => ['coding' => [['code' => 'encounter']]], 'value' => '00000000-0000-4000-8000-000000000007']],
                ['identifier' => ['type' => ['coding' => [['code' => 'episode_of_care']]], 'value' => '00000000-0000-4000-8000-000000000006']],
            ],
        ]];
        $this->diagnoses = [[
            'id' => 73,
            'code' => ['text' => 'Первинний діагноз'],
            'rank' => 1,
        ]];
        $this->dictionaries['eHealth/report_origins'] = ['patient' => 'Зі слів пацієнта'];
        foreach (['provisional' => 'Попередній стан', 'differential' => 'Диференціальний стан'] as $status => $title) {
            $this->conditions[] = [
                'code' => ['text' => $title],
                'clinicalStatus' => 'active',
                'verificationStatus' => $status,
                'role' => ['text' => 'Основний'],
                'reportOrigin' => ['coding' => [['system' => 'eHealth/report_origins', 'code' => 'patient']]],
            ];
        }
        $this->hasMore['observations'] = true;
    }

    public function isEntitySyncing(string $entityConstant): bool
    {
        return false;
    }

    public function failRefresh(): void
    {
        session()->flash('error', 'Не вдалося оновити записи');
    }
}
