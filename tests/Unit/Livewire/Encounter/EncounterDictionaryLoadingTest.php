<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Encounter;

use App\Livewire\EncounterPackage\EncounterPackageComponent as EncounterComponent;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\User;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use App\Services\Dictionary\Collections\DeviceDefinitionCollection;
use App\Services\Dictionary\Collections\RuleEngineRuleCollection;
use App\Services\Dictionary\Collections\ServiceCollection;
use App\Services\Dictionary\DictionaryManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class EncounterDictionaryLoadingTest extends TestCase
{
    public function test_boot_preserves_hydrated_dictionaries_without_loading_catalogs(): void
    {
        Http::preventStrayRequests();
        $manager = Mockery::mock(DictionaryManager::class);
        $manager->shouldNotReceive('basics', 'services', 'ruleEngineRules', 'deviceDefinitions');
        $this->instance(DictionaryManager::class, $manager);
        $legalEntity = new LegalEntity();
        $legalEntity->setRelation('type', new LegalEntityType(['name' => 'OUTPATIENT']));
        $this->app->instance('legalEntity', $legalEntity);
        $employee = new Employee();
        $employee->employeeType = 'DOCTOR';
        $user = Mockery::mock(User::class);
        $user->shouldReceive('getEncounterWriterEmployee')->once()->andReturn($employee);
        Auth::shouldReceive('user')->once()->andReturn($user);
        $component = new EncounterDictionaryLoadingHarness();
        $component->dictionaries = ['custom/services' => [['id' => 'retained-service']]];

        $component->boot();

        $this->assertSame(['custom/services' => [['id' => 'retained-service']]], $component->dictionaries);
        Http::assertNothingSent();
    }

    public function test_initial_custom_catalogs_do_not_load_rule_engine_details(): void
    {
        Http::preventStrayRequests();
        $manager = Mockery::mock(DictionaryManager::class);
        $manager->shouldReceive('basics')->once()->andReturn(new BasicDictionaryCollection([
            ['name' => 'eHealth/ICF/classifiers', 'values' => []],
            ['name' => 'eHealth/assistive_products', 'values' => []],
        ]));
        $manager->shouldReceive('services')->once()->andReturn(new ServiceCollection());
        $manager->shouldReceive('deviceDefinitions')->once()->andReturn(new DeviceDefinitionCollection());
        $manager->shouldNotReceive('ruleEngineRules');
        $this->instance(DictionaryManager::class, $manager);
        $component = new EncounterDictionaryLoadingHarness();

        $component->loadInitialCustomDictionaries();

        $this->assertSame([], $component->dictionaries['custom/rule_engine_details']);
        Http::assertNothingSent();
    }

    public function test_rule_details_are_loaded_on_demand_once_per_component(): void
    {
        $manager = Mockery::mock(DictionaryManager::class);
        $manager->shouldReceive('ruleEngineRules')->once()->andReturn(new RuleEngineRuleCollection([
            ['id' => 'rule-id', 'code' => ['code' => 'category'], 'details' => ['items' => [['value' => ['string' => 'Rule description']]]]],
        ]));
        $this->instance(DictionaryManager::class, $manager);
        $component = new EncounterDictionaryLoadingHarness();

        $component->loadClinicalImpressionRuleDetails();
        $component->loadClinicalImpressionRuleDetails();

        $this->assertSame('Rule description', $component->dictionaries['custom/rule_engine_details']['category']['items'][0]['value']['string']);
        $this->assertArrayNotHasKey('details', $component->dictionaries['custom/rule_engine_rule_list'][0]);
    }
}

class EncounterDictionaryLoadingHarness extends EncounterComponent
{
    public function loadInitialCustomDictionaries(): void
    {
        $this->loadCustomDictionaries();
    }
}
