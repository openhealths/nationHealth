<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Enums\Composition\CompositionCategory;
use App\Enums\Composition\CompositionConfiguration;
use App\Enums\Composition\CompositionJobStatus;
use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionType;
use App\Livewire\Composition\CompositionTempDisabilityCreate;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Services\SignatureService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The create half of a disability conclusion (TV 3.8.2.5 – 3.8.2.9).
 *
 * These cover what the audit of PR #645 found broken or merely suggested by the UI: the
 * conclusion has to be KEP-signed before it is sent at all, and the rules the form
 * expresses have to survive a direct call to the Livewire action.
 */
class CompositionCreateFlowTest extends TestCase
{
    use CompositionTestFixtures;
    use RefreshCompositionDatabase;

    /** eHealth UUID of the employee the wizard authors as. */
    private string $authorUuid = '';

    private string $subjectUuid = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-05T10:00:00Z'));
        Storage::fake('local');
    }

    /**
     * createComposition takes the unsigned compositionRequest; KEP is only for sign/cancel.
     */
    public function test_reviewing_the_details_posts_the_composition_request_and_starts_the_job(): void
    {
        $component = $this->readyToSubmit();

        $this->fakeEHealth([
            '*/composition' => Http::response(['data' => ['id' => '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'PENDING']], 200),
            '*' => Http::response($this->eHealthBody([]), 200),
        ]);

        $component->call('reviewDetails')->assertHasNoErrors()
            ->assertSet('asyncJobId', '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6')
            ->assertSet('asyncJobStatus', CompositionJobStatus::PENDING->value)
            ->assertSet('showSignatureModal', false)
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_AWAITING_JOB);

        Http::assertSent(static function ($request): bool {
            if (!str_ends_with($request->url(), '/composition')) {
                return false;
            }

            $body = $request->data();

            return data_get($body, 'type.coding.0.code') === 'TEMP_DISABILITY'
                && !array_key_exists('data', $body);
        });
    }

    public function test_missing_fhir_storage_blocks_submission_before_esoz_request(): void
    {
        $component = $this->readyToSubmit();
        $this->assertContains(DB::connection()->getDatabaseName(), ['testing', 'tv39_1204']);
        Schema::table('compositions', static function (Blueprint $table): void {
            $table->dropColumn('date');
        });
        Http::fake();

        $component->call('submitComposition')
            ->assertHasErrors('form.guard')
            ->assertSet('asyncJobId', null)
            ->assertSee(__('compositions.errors.storage_update_required'));

        Http::assertNothingSent();
    }

    public function test_the_wizard_exposes_submit_composition(): void
    {
        $this->assertTrue(
            method_exists(CompositionTempDisabilityCreate::class, 'submitComposition'),
            'The details step submits via submitComposition without a KEP dialog.'
        );
    }

    public function test_submitting_sends_the_unsigned_payload_and_starts_polling_the_job(): void
    {
        $component = $this->readyToSubmit();

        $this->fakeEHealth([
            '*/composition' => Http::response(['data' => ['id' => '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'PENDING']], 200),
            '*' => Http::response($this->eHealthBody([]), 200),
        ]);

        $component->call('submitComposition')
            ->assertSet('asyncJobId', '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6')
            ->assertSet('asyncJobStatus', CompositionJobStatus::PENDING->value)
            ->assertSet('showSignatureModal', false)
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_AWAITING_JOB);

        Http::assertSent(static function ($request): bool {
            if (!str_ends_with($request->url(), '/composition')) {
                return false;
            }

            $body = $request->data();

            return data_get($body, 'type.coding.0.code') === 'TEMP_DISABILITY'
                && !array_key_exists('data', $body);
        });
    }

    /**
     * TV 3.8.2.6 — the category list is filtered in the UI, but the action is a public
     * endpoint and has to refuse the excluded categories itself.
     */
    public function test_an_unidentified_patient_cannot_be_issued_a_forbidden_category(): void
    {
        $this->fakeSignatureService();

        $component = $this->readyToSubmit();

        $component->set('form.category', CompositionCategory::PREGNANCY->value)
            ->call('reviewDetails')
            ->assertHasErrors('form.category')
            ->assertSet('showSignatureModal', false);

        // The same rule holds when the action is invoked directly rather than through
        // the details step, which is what makes it a guard rather than a UI affordance.
        $component->call('submitComposition')
            ->assertHasErrors('form.category')
            ->assertSet('asyncJobId', null);
    }

    /**
     * TV 3.8.2.6.1 — the no-ERLN warning has to be acknowledged, not merely displayed.
     */
    public function test_the_unidentified_erln_warning_must_be_acknowledged_before_submitting(): void
    {
        $this->fakeSignatureService();

        $component = $this->readyToSubmit(acknowledgeErln: false);

        $component->call('reviewDetails')
            ->assertHasErrors('form.guard')
            ->assertSet('showSignatureModal', false)
            ->assertSet('asyncJobId', null);

        $this->fakeEHealth([
            '*/composition' => Http::response(['data' => ['id' => '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'PENDING']], 200),
            '*' => Http::response($this->eHealthBody([]), 200),
        ]);

        $component->call('acknowledgeUnidentifiedErln')
            ->call('reviewDetails')
            ->assertHasNoErrors()
            ->assertSet('asyncJobId', '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6')
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_AWAITING_JOB);
    }

    /**
     * TV 3.8.1.4.4 / 3.8.2.4.4 — an authentication method must come from the list eHealth
     * returned for this person, otherwise any UUID would end up in INFORM_WITH.
     */
    public function test_an_authentication_method_outside_the_offered_list_is_refused(): void
    {
        ['employee' => $employee, 'legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture();

        $encounterUuid = (string) Str::uuid();
        $offeredUuid = (string) Str::uuid();

        $this->fakeEHealth([
            '*authentication_methods*' => Http::response(
                $this->eHealthBody([$this->nativeAuthenticationMethod($offeredUuid)]),
                200
            ),
            '*' => Http::response(
                $this->eHealthBody([$this->compositionEncounter($encounterUuid, $employee->uuid)]),
                200
            ),
        ]);

        // Livewire's test helper does not route-model-bind a Person mount parameter, so
        // the component is mounted on a preperson and then pointed at the identified
        // person, which is all the authentication step reads.
        $component = Livewire::test(CompositionTempDisabilityCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $this->compositionPreperson(),
        ])
            ->set('form.isUnidentified', false)
            ->set('form.isFocusUnidentified', false)
            ->set('form.sectionFocusUuid', $person->uuid)
            ->call('selectEncounter', $encounterUuid);

        $component->call('selectAuthMethod', (string) Str::uuid())
            ->assertHasErrors('form.informWithUuid')
            ->assertSet('form.informWithUuid', null)
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_AUTH_METHOD);

        $component->call('selectAuthMethod', $offeredUuid)
            ->assertSet('form.informWithUuid', $offeredUuid)
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_DETAILS);
    }

    /**
     * TV 3.8.2.5.4 — pregnancy periods follow the explicit environment mirror of the
     * documented EMAL settings; the modern catalog does not publish these legacy values.
     */
    public function test_a_pregnancy_period_outside_the_configuration_is_refused(): void
    {
        ['employee' => $employee, 'legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture();

        $encounterUuid = (string) Str::uuid();

        $this->fakeEHealth([
            '*authentication_methods*' => Http::response($this->eHealthBody([]), 200),
            '*' => Http::response(
                $this->eHealthBody([$this->compositionEncounter($encounterUuid, $employee->uuid)]),
                200
            ),
        ]);

        $component = Livewire::test(CompositionTempDisabilityCreate::class, [
            'legalEntity' => $legalEntity,
            'personId' => $person->id,
        ])
            ->set('form.isUnidentified', false)
            ->set('form.isFocusUnidentified', false)
            ->set('form.sectionFocusUuid', $person->uuid)
            ->call('selectEncounter', $encounterUuid)
            ->call('skipAuthMethod')
            ->set('form.category', CompositionCategory::PREGNANCY->value)
            ->set('form.eventPeriodStart', '01.09.2026')
            ->set('form.eventPeriodEnd', '10.09.2026');

        $component->call('reviewDetails')
            ->assertHasErrors('form.guard')
            ->assertSet('showSignatureModal', false)
            ->assertSet('asyncJobId', null);

        // 126 days counted from 01.09.2026 inclusive ends on 04.01.2027.
        $this->fakeEHealth([
            '*/composition' => Http::response(['data' => ['id' => '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'PENDING']], 200),
            '*' => Http::response($this->eHealthBody([]), 200),
        ]);

        $component->set('form.eventPeriodEnd', '04.01.2027')
            ->call('reviewDetails')
            ->assertHasNoErrors()
            ->assertSet('asyncJobId', '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6')
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_AWAITING_JOB);
    }

    /**
     * TV 3.8.2.5.4 — a missing environment configuration must block the conclusion.
     * No production defaults may replace the documented legacy EMAL values.
     */
    public function test_a_pregnancy_conclusion_is_blocked_when_the_environment_configuration_is_missing(): void
    {
        ['employee' => $employee, 'legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture();

        $encounterUuid = (string) Str::uuid();

        $this->fakeEHealth([
            '*authentication_methods*' => Http::response($this->eHealthBody([]), 200),
            '*' => Http::response(
                $this->eHealthBody([$this->compositionEncounter($encounterUuid, $employee->uuid)]),
                200
            ),
        ]);

        config(['compositions.emal' => null]);

        Livewire::test(CompositionTempDisabilityCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $this->compositionPreperson(),
        ])
            ->set('form.isUnidentified', false)
            ->set('form.isFocusUnidentified', false)
            ->set('form.sectionFocusUuid', $person->uuid)
            ->call('selectEncounter', $encounterUuid)
            ->call('skipAuthMethod')
            ->set('form.category', CompositionCategory::PREGNANCY->value)
            ->set('form.eventPeriodStart', '01.09.2026')
            ->set('form.eventPeriodEnd', '04.01.2027')
            ->call('reviewDetails')
            ->assertHasErrors('form.guard')
            ->assertSet('showSignatureModal', false);
    }

    /**
     * The end-to-end lifecycle: unsigned create, job polling, read-back, KEP sign, and the
     * refreshed FINAL copy (TV 3.8.2.5 → 3.8.2.9).
     */
    public function test_create_poll_read_sign_and_poll_again_ends_in_a_signed_conclusion(): void
    {
        $this->fakeSignatureService('signed-composition');

        $compositionUuid = (string) Str::uuid();
        // Signing the draft is part of this flow, so the author needs the sign scope too.
        $component = $this->readyToSubmit(scopes: [
            'composition:create',
            'composition:read',
            'composition:search',
            'composition:sign',
        ]);
        $encounterUuid = $component->get('form.encounterUuid');
        $episodeUuid = $component->get('episodeUuid');

        $this->fakeEHealth([
            '*/composition' => Http::response(['data' => ['id' => '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'PENDING']], 200),
            '*/composition/job/*' => Http::response([
                'data' => ['id' => '5d566a2a-3d4c-4510-9e21-ed7f68050524', 'eta' => '2026-09-05T12:00:00Z', 'links' => [],
                    'status' => 'DONE',
                    'links' => [['entity' => 'eHealth/resources', 'href' => "/composition/$compositionUuid"]],
                ],
            ], 200),
            '*/sign' => Http::response(
                ['data' => ['id' => '1548388a-fdec-5d79-8581-ed5b8f5f8a6a', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'PENDING']],
                200
            ),
            '*integrationData*' => Http::response($this->eHealthBody([]), 200),
            '*' => Http::response([
                'data' => $this->compositionPayload($compositionUuid, $encounterUuid, CompositionStatus::PRELIMINARY),
            ], 200),
        ]);

        $component->call('submitComposition')
            ->tap(fn ($test) => $this->processCompositionWorker($test->get('asyncJobId')))
            ->call('pollAsyncJob')
            ->assertSet('compositionUuid', $compositionUuid)
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_REVIEW)
            ->assertSee(__('compositions.create_temp_disability.created'))
            ->assertSee('alert-message fixed top-6 right-2', false)
            ->assertDontSee('status-alert-green', false)
            ->assertNotDispatched('flashMessage');

        Http::assertSent(static function ($request): bool {
            if (!str_ends_with($request->url(), '/composition') || str_contains($request->url(), '/job/')) {
                return false;
            }

            return data_get($request->data(), 'type.coding.0.code') === 'TEMP_DISABILITY'
                && !array_key_exists('data', $request->data());
        });

        $stored = Composition::whereUuid($compositionUuid)->first();
        $this->assertNotNull($stored, 'The conclusion is mirrored locally once eHealth returns it.');
        $this->assertSame(CompositionStatus::PRELIMINARY, $stored->status);
        $this->assertSame($episodeUuid, $stored->episodeOfCareUuid);
        $this->assertSame('719a23fe-c9b6-5b6f-8d9f-c36da885d0d6', $stored->latestOperation->remoteJobId);
        $this->assertSame('DONE', $stored->latestOperation->status);

        // Signing is only offered to the author, so the local row must carry them.
        $author = Identifier::create(['value' => $this->authorUuid]);
        $stored->update(['author_id' => $author->id]);

        $component->set('compositionDetail.title', 'Forged client data');

        $component->call('openSigningModal')
            ->set('form.knedp', 'ca-1')
            ->set('form.password', 'secret')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 8))
            ->call('sign')
            ->assertHasNoErrors()
            ->assertSet('showSignatureModal', false);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/sign'));
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/sign')
            && $request->data() === ['data' => 'signed-composition']);

        app(SignatureService::class)->shouldHaveReceived('signData')
            ->withArgs(fn (array $payload): bool => $payload === $this->compositionPayload(
                $compositionUuid,
                $encounterUuid,
                CompositionStatus::PRELIMINARY
            ))->once();

        $component
            ->assertSet('asyncJobId', '1548388a-fdec-5d79-8581-ed5b8f5f8a6a')
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_AWAITING_JOB);

        $this->fakeEHealth([
            '*/composition/job/*' => Http::response([
                'data' => ['id' => '5d566a2a-3d4c-4510-9e21-ed7f68050524', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'DONE', 'links' => [['entity' => 'eHealth/resources', 'href' => "/composition/$compositionUuid"]]],
            ], 200),
            '*integrationData*' => Http::response($this->eHealthBody([]), 200),
            '*' => Http::response([
                'data' => $this->compositionPayload($compositionUuid, $encounterUuid, CompositionStatus::FINAL),
            ], 200),
        ]);

        $component->tap(fn ($test) => $this->processCompositionWorker($test->get('asyncJobId')))
            ->call('pollAsyncJob')
            ->assertSee(__('compositions.create_temp_disability.signed', ['number' => 'ТН-0001']))
            ->assertSee('alert-message fixed top-6 right-2', false)
            ->assertDontSee('status-alert-green', false)
            ->assertNotDispatched('flashMessage');

        $component->tap(fn ($test) => $this->processCompositionWorker($test->get('asyncJobId')))
            ->call('pollAsyncJob')
            ->assertDontSee(__('compositions.create_temp_disability.signed', ['number' => 'ТН-0001']));

        $this->assertSame(
            CompositionStatus::FINAL,
            Composition::whereUuid($compositionUuid)->first()->status
        );
        $stored->refresh();
        $this->assertSame(2, $stored->operations()->count());
        $this->assertSame('1548388a-fdec-5d79-8581-ed5b8f5f8a6a', $stored->latestOperation->remoteJobId);
        $this->assertSame('DONE', $stored->latestOperation->status);
    }

    /**
     * A disability conclusion as getComposition returns it.
     *
     * @return array<string, mixed>
     */
    public function test_repeated_submission_does_not_create_a_second_conclusion(): void
    {
        $component = $this->readyToSubmit();
        $this->fakeEHealth([
            '*/composition' => Http::response(['data' => ['id' => '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'PENDING']]),
        ]);

        $component->call('submitComposition')->call('submitComposition');

        $this->assertCount(1, Http::recorded(
            static fn ($request): bool =>
            $request->method() === 'POST' && str_ends_with($request->url(), '/composition')
        ));
    }

    public function test_done_job_can_resolve_the_conclusion_on_a_later_poll(): void
    {
        $component = $this->readyToSubmit();
        $this->fakeEHealth([
            '*/composition' => Http::response(['data' => ['id' => '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'PENDING']]),
            '*/composition/job/*' => Http::response(['data' => ['id' => '5d566a2a-3d4c-4510-9e21-ed7f68050524', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'DONE']]),
            '*searchComposition*' => Http::response(['data' => []]),
        ]);
        $component->call('submitComposition')->tap(fn ($test) => $this->processCompositionWorker($test->get('asyncJobId')))
            ->call('pollAsyncJob')
            ->assertSet('compositionUuid', null)
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_AWAITING_JOB);

        $uuid = (string) Str::uuid();
        $payload = $this->compositionPayload($uuid, $component->get('form.encounterUuid'), CompositionStatus::PRELIMINARY);
        $this->fakeEHealth([
            '*/composition/job/*' => Http::response(['data' => ['id' => '5d566a2a-3d4c-4510-9e21-ed7f68050524', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'DONE']]),
            '*searchComposition*' => Http::response(['data' => [$payload]]),
            '*integrationData*' => Http::response(['data' => []]),
            '*' => Http::response(['data' => $payload]),
        ]);

        $component->tap(fn ($test) => $this->processCompositionWorker($test->get('asyncJobId')))
            ->call('pollAsyncJob')
            ->assertSet('compositionUuid', $uuid)
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_REVIEW)
            ->assertSet('asyncJobErrors', []);
    }

    public function test_a_done_job_does_not_select_the_latest_of_multiple_search_matches(): void
    {
        $component = $this->readyToSubmit();
        $encounterUuid = $component->get('form.encounterUuid');
        $first = $this->compositionPayload((string) Str::uuid(), $encounterUuid, CompositionStatus::PRELIMINARY);
        $latest = $this->compositionPayload((string) Str::uuid(), $encounterUuid, CompositionStatus::PRELIMINARY);
        $latest['date'] = '2026-09-01T11:00:00Z';
        $this->fakeEHealth([
            '*/composition' => Http::response(['data' => ['id' => '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'PENDING']]),
            '*/composition/job/*' => Http::response(['data' => ['id' => '5d566a2a-3d4c-4510-9e21-ed7f68050524', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'DONE']]),
            '*searchComposition*' => Http::response(['data' => [$first, $latest]]),
            '*integrationData*' => Http::response(['data' => []]),
            '*' => Http::response(['data' => $latest]),
        ]);

        $component->call('submitComposition')->tap(fn ($test) => $this->processCompositionWorker($test->get('asyncJobId')))
            ->call('pollAsyncJob')
            ->assertSet('compositionUuid', null)
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_AWAITING_JOB)
            ->assertSet('asyncJobErrors', [__('compositions.errors.created_not_found')])
            ->assertSee(__('compositions.async.retry_read'))
            ->assertDontSeeHtml('wire:click="openSigningModal"');
        $this->assertSame(0, Composition::count());
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/episode/'));
    }

    public function test_pending_status_is_translated(): void
    {
        $component = $this->readyToSubmit();
        $this->fakeEHealth([
            '*/composition' => Http::response(['data' => ['id' => '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'PENDING']]),
        ]);

        $component->call('submitComposition')
            ->assertSee('Очікує обробки')
            ->assertDontSee('Статус: PENDING');
    }

    public function test_done_job_with_unreadable_details_can_retry_without_creating_another_conclusion(): void
    {
        $component = $this->readyToSubmit();
        $uuid = (string) Str::uuid();
        $this->fakeEHealth([
            '*/composition' => Http::response(['data' => ['id' => '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'PENDING']]),
            '*/composition/job/*' => Http::response(['data' => ['id' => '5d566a2a-3d4c-4510-9e21-ed7f68050524', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'DONE', 'links' => [['entity' => 'eHealth/resources', 'href' => "/composition/$uuid"]]]]),
            '*' => Http::response(['status' => 'PRELIMINARY']),
        ]);

        $component->call('submitComposition')->tap(fn ($test) => $this->processCompositionWorker($test->get('asyncJobId')))
            ->call('pollAsyncJob')
            ->assertSet('asyncJobStatus', 'DONE')
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_AWAITING_JOB)
            ->assertSet('asyncJobErrors', [__('compositions.errors.created_unreadable')])
            ->assertSee(__('compositions.async.retry_read'))
            ->assertDontSee(__('compositions.create_temp_disability.job_failed'))
            ->assertDontSee('wire:poll.3s', false);

        $this->assertCount(1, Http::recorded(static fn ($request): bool => $request->method() === 'POST'));
        $payload = $this->compositionPayload($uuid, $component->get('form.encounterUuid'), CompositionStatus::PRELIMINARY);
        $this->fakeEHealth([
            '*/composition/job/*' => Http::response(['data' => ['id' => '5d566a2a-3d4c-4510-9e21-ed7f68050524', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'DONE']]),
            '*searchComposition*' => Http::response(['data' => [$payload]]),
            '*integrationData*' => Http::response(['data' => []]),
            '*' => Http::response($payload),
        ]);

        $component->tap(fn ($test) => $this->processCompositionWorker($test->get('asyncJobId')))
            ->call('pollAsyncJob')
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_REVIEW)
            ->assertSet('asyncJobErrors', []);
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
    }

    public function test_an_unavailable_job_status_shows_a_retry_instead_of_an_endless_spinner(): void
    {
        $component = $this->readyToSubmit();
        $this->fakeEHealth([
            '*/composition' => Http::response(['data' => ['id' => '719a23fe-c9b6-5b6f-8d9f-c36da885d0d6', 'eta' => '2026-09-05T12:00:00Z', 'links' => [], 'status' => 'PENDING']]),
            '*/composition/job/*' => Http::response([], 503),
        ]);

        $component->call('submitComposition')->tap(fn ($test) => $this->processCompositionWorker($test->get('asyncJobId')))
            ->call('pollAsyncJob')
            ->assertSet('asyncJobStatus', 'PENDING')
            ->assertSet('asyncJobErrors', [__('compositions.errors.job_status_unavailable')])
            ->assertSee(__('compositions.async.retry_read'))
            ->assertDontSee('wire:poll.3s', false);
    }

    private function compositionPayload(
        string $uuid,
        string $encounterUuid,
        CompositionStatus $status
    ): array {
        return $this->nativeCompositionDocument([
            'identifier' => ['value' => $uuid],
            'status' => $status->value,
            'title' => 'ТН-0001',
            'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => CompositionType::TEMP_DISABILITY->value]]],
            'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => CompositionCategory::SICKNESS->value]]],
            'encounter' => ['value' => $encounterUuid],
            'author' => ['value' => $this->authorUuid],
            'subject' => $this->compositionReference('preperson', $this->subjectUuid)['identifier'],
            'section' => ['focus' => $this->compositionReference('preperson', $this->subjectUuid)['identifier']],
            'event' => [['period' => ['start' => '2026-09-01T00:00:01Z', 'end' => '2026-09-05T20:59:59Z']]],
            'date' => '2026-09-01T10:00:00Z',
        ]);
    }

    public static function signingPreflightCases(): array
    {
        return ['exact deadline' => ['boundary', null], 'expired' => ['expired', 'signing_deadline_expired'],
            'future date' => ['future', 'signing_deadline_expired'], 'fresh category' => ['category', 'author_not_found']];
    }

    #[DataProvider('signingPreflightCases')]
    public function test_signing_revalidates_the_document_deadline_and_category_before_kep(string $case, ?string $error): void
    {
        $this->fakeSignatureService('signed-composition');
        $component = $this->readyToSubmit(scopes: ['composition:create', 'composition:read', 'composition:search', 'composition:sign']);
        $uuid = (string) Str::uuid();
        $encounter = $component->get('form.encounterUuid');
        $native = $this->compositionPayload($uuid, $encounter, CompositionStatus::PRELIMINARY);
        $this->fakeEHealth([
            '*/composition' => Http::response(['data' => ['id' => (string) Str::uuid(), 'status' => 'PENDING', 'eta' => now()->toIso8601String(), 'links' => []]]),
            '*/composition/job/*' => Http::response(['data' => ['id' => (string) Str::uuid(), 'status' => 'DONE', 'eta' => now()->toIso8601String(),
                'links' => [['entity' => 'eHealth/resources', 'href' => '/composition/'.$uuid]]]]),
            '*integrationData*' => Http::response(['data' => []]),
            '*' => Http::response(['data' => $native]),
        ]);
        $component->call('submitComposition')->tap(fn ($test) => $this->processCompositionWorker($test->get('asyncJobId')))
            ->call('pollAsyncJob')->call('openSigningModal');
        if ($case === 'boundary' || $case === 'expired') {
            $this->travelTo(CarbonImmutable::parse($native['date'])->addHours($case === 'boundary' ? 120 : 121));
        } elseif ($case === 'future') {
            $native['date'] = now()->addMinute()->toIso8601String();
        } else {
            $settings = config('compositions.emal');
            $settings[CompositionConfiguration::AUTHOR_TYPES->value]['SPECIALIST'] = ['SICKNESS'];
            config(['compositions.emal' => $settings]);
            $native['category']['coding'][0]['code'] = 'COVID19';
        }
        $settings = config('compositions.emal');
        $settings[CompositionConfiguration::DISABILITY_SIGN_DELAY_ENABLED->value] = true;
        config(['compositions.emal' => $settings]);
        $this->fakeEHealth([
            '*integrationData*' => Http::response(['data' => []]),
            '*/sign' => Http::response(['data' => ['id' => (string) Str::uuid(), 'status' => 'PENDING', 'eta' => now()->toIso8601String(), 'links' => []]]),
            '*' => Http::response(['data' => $native]),
        ]);
        $component->set('form.knedp', 'ca-1')->set('form.password', 'secret')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 8))->call('sign');
        if ($error === null) {
            $component->assertHasNoErrors()->assertSet('showSignatureModal', false);
            app(SignatureService::class)->shouldHaveReceived('signData')->once();
            Http::assertSent(static fn ($request): bool => $request->method() === 'PATCH');
            $this->assertSame(2, CompositionOperation::count());
        } else {
            $component->assertHasErrors('form.guard')->assertSet('showSignatureModal', true)->assertSee(__('compositions.errors.'.$error));
            app(SignatureService::class)->shouldNotHaveReceived('signData');
            Http::assertNotSent(static fn ($request): bool => $request->method() === 'PATCH');
            $this->assertSame(1, CompositionOperation::count());
        }
    }

    /**
     * A wizard sitting on the details step with a valid, submittable form.
     */
    private function readyToSubmit(
        bool $acknowledgeErln = true,
        array $scopes = ['composition:create', 'composition:read', 'composition:search']
    ): Testable {
        ['employee' => $employee, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: $scopes);

        $this->authorUuid = $employee->uuid;

        $encounterUuid = (string) Str::uuid();
        $episodeUuid = (string) Str::uuid();

        $this->fakeEHealth([
            '*' => Http::response(
                $this->eHealthBody([
                    $this->compositionEncounter($encounterUuid, $employee->uuid, $episodeUuid),
                ]),
                200
            ),
        ]);

        $subject = $this->compositionPreperson();
        $this->subjectUuid = $subject->uuid;
        $component = Livewire::test(CompositionTempDisabilityCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $subject,
        ])
            ->call('selectEncounter', $encounterUuid)
            ->call('skipAuthMethod')
            ->set('form.category', CompositionCategory::SICKNESS->value)
            ->set('form.eventPeriodStart', '01.09.2026')
            ->set('form.eventPeriodEnd', '05.09.2026');

        if ($acknowledgeErln) {
            $component->call('acknowledgeUnidentifiedErln');
        }

        return $component;
    }
}
