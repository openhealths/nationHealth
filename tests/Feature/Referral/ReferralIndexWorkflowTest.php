<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Patient\ServiceRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\Referral\ReferralIndex;
use App\Models\Relations\Party;
use App\Models\User;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use App\Services\SignatureService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReferralIndexWorkflowTest extends TestCase
{
    private const string UUID = '11111111-1111-4111-8111-111111111111';
    private const string PATIENT = '22222222-2222-4222-8222-222222222222';
    private const string EMZ = '33333333-3333-4333-8333-333333333333';

    private ReferralUser $referralUser;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $user = (new ReferralUser())->forceFill(['id' => 1, 'email' => 'referral-fixture@example.com']);
        $user->setRelation('party', new Party(['tax_id' => '0000000000']));
        $this->actingAs($user);
        $this->referralUser = $user;
        // Register anonymous component paths for each fresh test application.
        $this->app['view']->addNamespace('__components', resource_path('views/components'));
        $this->mock(SignatureService::class)->shouldReceive('getCertificateAuthorities')->andReturn([]);
    }

    public function test_initial_page_does_not_invent_medical_data(): void
    {
        Livewire::test(ReferralIndex::class)
            ->assertSet('hasSearched', false)
            ->assertSet('searchResults', [])
            ->assertDontSee('AX854-654T')
            ->assertDontSee('mock-uuid');
    }

    public function test_filters_apply_to_api_results_and_accept_status_aliases(): void
    {
        $this->mock(ServiceRequest::class)->shouldReceive('searchForServiceRequestsByParams')
            ->once()->with(['requisition' => '1234-5678-9012-3456'])
            ->andReturn(new EHealthResponse(new Response(200, [], json_encode(['data' => [
                $this->row('active'),
                [...$this->row('entered-in-error'), 'id' => 'error-row'],
            ]]))));
        Livewire::test(ReferralIndex::class)
            ->set('requisition', '1234567890123456')
            ->set('patient', 'ШЕВЧЕНКО')
            ->set('status', ['entered_in_error'])
            ->call('search')->assertHasNoErrors()
            ->assertSeeHtml('wire:key="referral-error-row"')
            ->assertDontSeeHtml('wire:key="referral-'.self::UUID.'"')
            ->set('patient', 'Інший пацієнт')
            ->assertSee(__('referrals.messages.not_found'))
            ->call('resetFilters')->assertSet('searchResults', [])->assertSet('status', []);
    }

    #[DataProvider('actionStates')]
    public function test_actions_depend_on_clinical_and_program_state(string $status, string $program, string $action, bool $allowed): void
    {
        $component = new ReferralIndex();
        $this->assertSame($allowed, $component->canAct([...$this->row($status), 'program_processing_status' => $program], $action));
    }

    public static function actionStates(): array
    {
        return [
            ['active', 'new', 'process', true],
            ['active', 'in_progress', 'process', false],
            ['active', 'in_progress', 'complete', true],
            ['active', 'in_progress', 'cancel_referral', false],
            ['in_progress', 'in_progress', 'complete', true],
            ['in_queue', 'in_queue', 'cancel_usage', true],
            ['completed', 'completed', 'process', false],
            ['completed', 'completed', 'complete', false],
            ['draft', 'new', 'cancel_usage', false],
            ['active', 'new', 'recall_referral', true],
        ];
    }

    public function test_in_progress_template_restores_completion_and_does_not_offer_author_actions(): void
    {
        $this->page('in_progress')->assertSeeHtml('openCompleteModal(')
            ->assertSeeHtml('wire:model="selectedEmzUuid"')
            ->assertSee(__('referrals.actions.cancel_usage'))
            ->assertDontSeeHtml('wire:click="openErrorModal(')
            ->assertDontSeeHtml('wire:click="openRecallModal(');
    }

    public function test_read_only_user_cannot_call_cancel_usage_even_if_button_is_bypassed(): void
    {
        $this->referralUser->allowedScopes = ['service_request:read'];
        $this->page('in_progress')->call('openCancelModal', self::UUID)->assertForbidden();
    }

    public function test_client_cannot_replace_the_server_search_response(): void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(ReferralIndex::class)->set('searchResults', [$this->row('active')]);
    }

    public function test_error_confirmation_validates_then_requests_signature_without_changing_status(): void
    {
        $this->page('active')->call('openErrorModal', self::UUID)
            ->call('confirmErrorUsage')->assertHasErrors('errorReason')
            ->assertSet('searchResults.0.status', 'active')
            ->set('errorReason', 'other')->call('confirmErrorUsage')->assertHasErrors('errorReason')
            ->set('errorReason', 'entered_in_error')->call('confirmErrorUsage')
            ->assertHasNoErrors()->assertSet('showSignatureModal', true)
            ->assertSet('actionType', 'cancel_referral')->assertSet('searchResults.0.status', 'active');
    }

    public function test_error_marking_submits_signed_cancel_then_persists_confirmed_status(): void
    {
        $this->expectSignature(['status_reason' => 'entered-in-error']);
        $this->mock(ReferralRequestLifecycleService::class)->shouldReceive('submitSignedCancel')
            ->once()->with('service_request', self::PATIENT, self::UUID, [
                'status_reason' => 'entered-in-error', 'signed_data' => 'fixture-signature', 'signed_data_encoding' => 'base64',
            ])->andReturn(['status' => 'processed', 'result' => [['status' => 'entered-in-error']]]);
        $this->signingPage('cancel_referral')->call('sign')
            ->assertHasNoErrors()->assertSet('searchResults.0.status', 'entered-in-error')
            ->assertSet('persistedStatus', 'entered-in-error')->assertSet('form.password', '')
            ->assertSet('showSignatureModal', false)->assertNotDispatched('notify')
            ->assertSee(__('referrals.messages.error_marked_success'));
    }

    public function test_failed_cancel_keeps_status_and_clears_signing_credentials(): void
    {
        $this->expectSignature(['status_reason' => 'entered-in-error']);
        $this->mock(ReferralRequestLifecycleService::class)->shouldReceive('submitSignedCancel')->once()
            ->andThrow(new \RuntimeException('fixture API failure'));
        $this->signingPage('cancel_referral')->call('sign')->assertSet('searchResults.0.status', 'active')
            ->assertSet('persistedStatus', null)->assertSet('form.password', '')->assertSet('actionType', null);
        $this->assertNull(session('success'));
    }

    public function test_conflicting_remote_status_does_not_mark_cancel_successful(): void
    {
        $this->expectSignature(['status_reason' => 'entered-in-error']);
        $this->mock(ReferralRequestLifecycleService::class)->shouldReceive('submitSignedCancel')->once()
            ->andReturn(['status' => 'processed', 'result' => [['id' => self::UUID, 'status' => 'completed']]]);
        $this->signingPage('cancel_referral')->call('sign')->assertSet('searchResults.0.status', 'active')
            ->assertSet('persistedStatus', null);
        $this->assertNull(session('success'));
    }

    public function test_recall_uses_its_own_signed_operation(): void
    {
        $this->expectSignature(['explanatory_letter' => 'Пацієнт відмовився']);
        $this->mock(ReferralRequestLifecycleService::class)->shouldReceive('submitSignedRecall')->once()
            ->with(self::PATIENT, self::UUID, [
                'explanatory_letter' => 'Пацієнт відмовився', 'signed_data' => 'fixture-signature', 'signed_data_encoding' => 'base64',
            ])->andReturn(['status' => 'processed', 'result' => [['status' => 'recalled']]]);
        $this->signingPage('recall_referral')->call('sign')->assertSet('searchResults.0.status', 'recalled');
    }

    public function test_cancel_usage_restores_availability_without_recinding_the_referral(): void
    {
        $this->mock(ReferralRequestLifecycleService::class)->shouldReceive('cancelUsage')->once()
            ->with(self::UUID, self::PATIENT, ['explanatory_letter' => 'Обрано інший заклад'])->andReturn(['status' => 'active']);
        $this->page('in_progress')->call('openCancelModal', self::UUID)
            ->set('cancelExplanatoryLetter', 'Обрано інший заклад')->call('confirmCancelUsage')
            ->assertSet('searchResults.0.status', 'active')
            ->assertSet('searchResults.0.program_processing_status', 'new')
            ->assertSeeHtml('wire:click="process(');
    }

    public function test_completion_requires_linked_emz_and_preserves_status_on_failure(): void
    {
        $this->mock(ReferralRequestLifecycleService::class)->shouldReceive('completeReferral')->once()
            ->with(self::UUID, self::EMZ, 'encounter')->andThrow(new \RuntimeException('fixture failure'));
        $this->page('in_progress')->call('openCompleteModal', self::UUID)
            ->set('selectedEmzUuid', self::EMZ)->call('confirmComplete')
            ->assertSet('searchResults.0.status', 'in_progress')->assertSet('showCompleteModal', true);
    }

    public function test_successful_completion_updates_status_and_uses_flash_feedback(): void
    {
        $this->mock(ReferralRequestLifecycleService::class)->shouldReceive('completeReferral')->once()
            ->with(self::UUID, self::EMZ, 'encounter')->andReturn(['status' => 'completed']);
        $this->page('in_progress')->call('openCompleteModal', self::UUID)
            ->set('selectedEmzUuid', self::EMZ)->call('confirmComplete')
            ->assertSet('searchResults.0.status', 'completed')->assertSet('showCompleteModal', false)
            ->assertSee(__('referrals.messages.completed'))->assertNotDispatched('notify');
    }

    public function test_cancel_usage_does_not_change_state_when_lifecycle_fails(): void
    {
        $this->mock(ReferralRequestLifecycleService::class)->shouldReceive('cancelUsage')->once()
            ->andThrow(new \RuntimeException('failed job'));
        $this->page('in_progress')->call('openCancelModal', self::UUID)
            ->set('cancelExplanatoryLetter', 'Обрано інший заклад')->call('confirmCancelUsage')
            ->assertSet('searchResults.0.status', 'in_progress')->assertSet('showCancelModal', true);
    }

    public function test_unlinked_emz_never_reaches_lifecycle(): void
    {
        $this->mock(ReferralRequestLifecycleService::class)->shouldNotReceive('completeReferral');
        $this->page('in_progress')->call('openCompleteModal', self::UUID)
            ->set('selectedEmzUuid', self::EMZ)->set('emzLinked', false)->call('confirmComplete')
            ->assertSet('searchResults.0.status', 'in_progress');
    }

    private function row(string $status): array
    {
        return ['id' => self::UUID, 'requisition' => '1234-5678-9012-3456', 'status' => $status,
            'subject' => ['display' => 'Шевченко Т.Г.', 'identifier' => ['value' => self::PATIENT]],
            'program_processing_status' => $status === 'active' ? 'new' : $status];
    }

    private function page(string $status)
    {
        return Livewire::test(ReferralIndexHarness::class)->call('seed', [$this->row($status)]);
    }

    private function signingPage(string $action)
    {
        $page = $this->page('active');
        if ($action === 'cancel_referral') {
            $page->call('openErrorModal', self::UUID)->set('errorReason', 'entered_in_error')->call('confirmErrorUsage');
        } else {
            $page->call('openRecallModal', self::UUID)->set('recallExplanatoryLetter', 'Пацієнт відмовився')->call('confirmRecall');
        }

        return $page->set('form.knedp', 'fixture-provider')->set('form.password', 'fixture-password')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('fixture.dat', 1));
    }

    private function expectSignature(array $payload): void
    {
        app(SignatureService::class)->shouldReceive('signData')->once()
            ->with($payload, 'fixture-password', 'fixture-provider', \Mockery::any(), '0000000000')
            ->andReturn('fixture-signature');
    }
}

class ReferralIndexHarness extends ReferralIndex
{
    public ?string $persistedStatus = null;
    public bool $emzLinked = true;

    public function seed(array $rows): void
    {
        $this->searchResults = $rows;
        $this->hasSearched = true;
    }

    protected function persistSignedStatus(string $uuid, string $patientId, string $status): void
    {
        $this->persistedStatus = $status;
    }

    protected function loadEmzResourcesForComplete(string $referralUuid): void
    {
        $this->selectedEmzUuid = '';
        $this->availableEmzResources = [['uuid' => '33333333-3333-4333-8333-333333333333', 'label' => 'fixture EMZ']];
    }

    protected function assertEmzLinkedToReferral(string $referralUuid, string $resourceType, string $resourceUuid): bool
    {
        return $this->emzLinked;
    }
}

class ReferralUser extends User
{
    public ?array $allowedScopes = null;

    public function can($abilities, $arguments = []): bool
    {
        return $this->allowedScopes === null || in_array($abilities, $this->allowedScopes, true);
    }
}
