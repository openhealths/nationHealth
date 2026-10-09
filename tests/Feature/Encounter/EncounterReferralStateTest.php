<?php

declare(strict_types=1);

namespace Tests\Feature\Encounter;

use App\Livewire\Encounter\EncounterEdit;
use App\Enums\Person\EncounterStatus;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Services\SignatureService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EncounterReferralStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->mock(SignatureService::class)->shouldReceive('getCertificateAuthorities')->andReturn([]);
    }

    public function test_referral_signature_renders_only_the_creation_handler(): void
    {
        Livewire::test(EncounterReferralStateHarness::class)
            ->set('actionType', 'sign_referral')
            ->set('showSignatureModal', true)
            ->assertSeeHtml('wire:click="sign"')
            ->assertDontSeeHtml('wire:click="cancelSelectedEncounter"')
            ->call('sign')
            ->assertSet('signedAction', 'sign_referral');
    }

    public function test_cancellation_signature_renders_only_the_cancellation_handler(): void
    {
        Livewire::test(EncounterReferralStateHarness::class)
            ->set('actionType', 'cancel_encounter')
            ->set('showSignatureModal', true)
            ->assertSeeHtml('wire:click="cancelSelectedEncounter"')
            ->assertDontSeeHtml('wire:click="sign"');
    }

    #[DataProvider('unrelatedSignatureActions')]
    public function test_misrouted_cancellation_does_not_validate_an_unrelated_signature(?string $action): void
    {
        Livewire::test(EncounterReferralStateHarness::class)
            ->set('actionType', $action)
            ->set('showSignatureModal', true)
            ->call('cancelSelectedEncounter')
            ->assertHasNoErrors()
            ->assertSet('actionType', $action)
            ->assertSet('showSignatureModal', true);
    }

    public static function unrelatedSignatureActions(): array
    {
        return ['referral' => ['sign_referral'], 'prescription' => ['sign_eprescription'], 'encounter' => [null]];
    }

    public function test_cancellation_requires_its_reason_and_explanation(): void
    {
        Livewire::test(EncounterReferralStateHarness::class)
            ->set('actionType', 'cancel_encounter')
            ->call('cancelSelectedEncounter')
            ->assertHasErrors([
                'cancellationForm.cancellationReason',
                'cancellationForm.explanatoryLetter',
            ]);
    }

    public function test_search_selection_clears_results_and_only_the_changed_field_errors(): void
    {
        $service = $this->service();
        Livewire::test(EncounterReferralStateHarness::class)
            ->set('encounterReferralServiceSearch', '12')
            ->set('encounterReferralHasSearched', true)
            ->set('encounterReferralServiceResults', [$service])
            ->call('seedError', 'encounterReferralForm.service_id')
            ->call('seedError', 'encounterReferralForm.category')
            ->call('seedError', 'encounterReferralForm.quantity')
            ->call('seedError', 'form.password')
            ->call('selectEncounterReferralService', $service['id'])
            ->assertSet('encounterReferralSelectedService', $service)
            ->assertSet('encounterReferralForm.service_id', $service['id'])
            ->assertSet('encounterReferralForm.category', 'laboratory_procedure')
            ->assertSet('encounterReferralServiceSearch', '')
            ->assertSet('encounterReferralHasSearched', false)
            ->assertSet('encounterReferralServiceResults', [])
            ->assertHasNoErrors(['encounterReferralForm.service_id', 'encounterReferralForm.category'])
            ->assertHasErrors(['encounterReferralForm.quantity', 'form.password']);
        Http::assertNothingSent();
    }

    public function test_catalog_selection_clears_results_and_closes_the_catalog(): void
    {
        $service = $this->service();
        Livewire::test(EncounterReferralStateHarness::class)
            ->set('encounterReferralServiceSearch', '12')
            ->set('encounterReferralHasSearched', true)
            ->set('encounterReferralServiceResults', [$service])
            ->call('seedError', 'encounterReferralForm.service_id')
            ->call('selectEncounterReferralServiceFromCatalog', $service)
            ->assertSet('encounterReferralSelectedService', $service)
            ->assertSet('encounterReferralForm.service_id', $service['id'])
            ->assertSet('encounterReferralServiceSearch', '')
            ->assertSet('encounterReferralHasSearched', false)
            ->assertSet('encounterReferralServiceResults', [])
            ->assertHasNoErrors(['encounterReferralForm.service_id'])
            ->assertDispatched('encounter-referral-service-catalog-close');
        Http::assertNothingSent();
    }

    public function test_editing_a_referral_field_clears_only_its_stale_error(): void
    {
        Livewire::test(EncounterReferralStateHarness::class)
            ->call('seedError', 'encounterReferralForm.quantity')
            ->call('seedError', 'encounterReferralForm.service_id')
            ->set('encounterReferralForm.quantity', 2)
            ->assertHasNoErrors(['encounterReferralForm.quantity'])
            ->assertHasErrors(['encounterReferralForm.service_id']);
    }

    public function test_closing_the_drawer_clears_only_referral_errors(): void
    {
        Livewire::test(EncounterReferralStateHarness::class)
            ->call('seedError', 'encounterReferralForm.quantity')
            ->call('seedError', 'encounterReferralForm.service_id')
            ->call('seedError', 'form.password')
            ->call('closeEncounterReferralDrawer')
            ->assertHasNoErrors(['encounterReferralForm.quantity', 'encounterReferralForm.service_id'])
            ->assertHasErrors(['form.password']);
    }

    public function test_opening_the_drawer_clears_old_errors_without_clearing_signature_errors(): void
    {
        Livewire::test(EncounterReferralStateHarness::class)
            ->call('seedError', 'encounterReferralForm.quantity')
            ->call('seedError', 'form.password')
            ->call('openEncounterReferralDrawer')
            ->assertSet('showEncounterReferralDrawer', true)
            ->assertHasNoErrors(['encounterReferralForm.quantity'])
            ->assertHasErrors(['form.password']);
    }

    public function test_invalid_service_selection_preserves_the_current_service(): void
    {
        $service = $this->service();
        Livewire::test(EncounterReferralStateHarness::class)
            ->set('encounterReferralSelectedService', $service)
            ->set('encounterReferralForm.service_id', $service['id'])
            ->set('encounterReferralServiceResults', [$service])
            ->call('seedError', 'encounterReferralForm.service_id')
            ->call('selectEncounterReferralService', 'not-in-results')
            ->assertSet('encounterReferralForm.service_id', $service['id'])
            ->assertSet('encounterReferralSelectedService', $service)
            ->assertHasErrors(['encounterReferralForm.service_id']);
        Http::assertNothingSent();
    }

    private function service(): array
    {
        return ['id' => 'bf112058-af5d-45c4-aea0-f6ee6783fcb7', 'code' => '12515-00', 'name' => 'Обстеження', 'category' => 'laboratory_procedure'];
    }
}

class EncounterReferralStateHarness extends EncounterEdit
{
    public string $signedAction = '';

    public function boot(): void
    {
    }

    public function mount(?LegalEntity $legalEntity = null, int $encounterId = 0, ?Person $person = null, ?Preperson $preperson = null): void
    {
        $this->isReadonly = true;
    }

    public function seedError(string $key): void
    {
        $this->addError($key, 'Required');
    }

    public function signEncounterReferral(): void
    {
        $this->signedAction = 'sign_referral';
    }

    protected function resolveEncounterModelForStandalone(): ?Encounter
    {
        return new Encounter(['status' => EncounterStatus::FINISHED->value]);
    }

    protected function loadEncounterReferralAuthMethods(Encounter $encounter): void
    {
    }

    protected function loadEncounterReferralPrograms(): void
    {
    }

    public function render(): View
    {
        return view()->file(__DIR__.'/Fixtures/referral-signature.blade.php');
    }
}
