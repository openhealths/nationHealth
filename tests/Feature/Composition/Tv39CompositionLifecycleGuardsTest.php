<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionConfiguration;
use App\Enums\Composition\CompositionType;
use App\Livewire\Composition\CompositionTempDisabilityCreate;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Models\MergeRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\SignatureService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Tv39CompositionLifecycleGuardsTest extends TestCase
{
    use CompositionTestFixtures;
    use RefreshCompositionDatabase;

    public static function wrongDocumentContexts(): array
    {
        return ['identifier' => ['identifier.value'], 'subject' => ['subject.value'], 'author' => ['author.value'],
            'encounter' => ['encounter.value'], 'type' => ['type.coding.0.code'], 'status' => ['status']];
    }

    #[DataProvider('wrongDocumentContexts')]
    public function test_worker_rejects_a_native_document_outside_its_operation_context(string $path): void
    {
        $fixture = $this->compositionFixture();
        $uuid = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $episode = (string) Str::uuid();
        $job = (string) Str::uuid();
        $operation = Repository::compositionOperation()->store(['id' => $job, 'status' => 'PENDING'], CompositionAsyncOperation::CREATE, $fixture['person'], [
            'composition_type' => CompositionType::TEMP_DISABILITY, 'author_uuid' => $fixture['employee']->uuid,
            'encounter_uuid' => $encounter, 'episode_uuid' => $episode,
        ]);
        $native = $this->document($fixture, $fixture['person'], $uuid, $encounter);
        $native['status'] = 'PRELIMINARY';
        data_set($native, $path, match ($path) {
            'status' => 'FINAL', 'type.coding.0.code' => 'NEWBORN', default => (string) Str::uuid()
        });
        $this->fakeEHealth([
            '*composition/job/*' => Http::response(['data' => ['id' => $job, 'status' => 'DONE', 'eta' => now()->toIso8601String(),
                'links' => [['entity' => 'eHealth/resources', 'href' => '/composition/'.$uuid]]]]),
            '*' => Http::response($native),
        ]);
        $this->processCompositionWorker($job);
        $this->assertSame('PENDING', $operation->fresh()->status);
        $this->assertNull($operation->fresh()->compositionId);
        $this->assertSame(0, Composition::count());
        $this->assertNotEmpty($operation->fresh()->job->response_data['errors']);
    }

    public static function continuationCases(): array
    {
        return ['valid' => ['valid', null], 'overlap' => ['overlap', 'related_period_overlap'],
            'changed focus' => ['focus', 'care_focus_required'], 'not final' => ['status', 'related_not_final'],
            'category changed' => ['category', 'related_category_mismatch'],
            'unreadable identity' => ['identity', 'invalid_response'], 'another author' => ['author', 'related_not_author'],
            'total chain limit' => ['total', 'period_too_long'], 'cycle' => ['cycle', 'related_unreadable']];
    }

    #[DataProvider('continuationCases')]
    public function test_continuation_rechecks_the_fresh_chain_before_creation(string $case, ?string $error): void
    {
        [$component, $fixture, $patient, $encounter] = $this->ready();
        $uuid = (string) Str::uuid();
        $native = $this->document($fixture, $patient, $uuid, (string) Str::uuid());
        $previous = Repository::composition()->store($native, $patient, (string) Str::uuid());
        $component->set('form.relatesToCode', 'appends')->set('form.relatesToTargetUuid', $uuid);
        $settings = config('compositions.emal');
        switch ($case) {
            case 'overlap': $component->set('form.eventPeriodStart', today()->subDay()->format('d.m.Y'));
                break;
            case 'focus': $native['section']['focus']['value'] = (string) Str::uuid();
                break;
            case 'status': $native['status'] = 'PRELIMINARY';
                break;
            case 'category': $native['category']['coding'][0]['code'] = 'COVID19';
                break;
            case 'identity': $native['subject']['value'] = (string) Str::uuid();
                break;
            case 'author': $previous->author->update(['value' => (string) Str::uuid()]);
                break;
            case 'total': $settings[CompositionConfiguration::TOTAL_SPAN->value]['SICKNESS'] = 2;
                break;
            case 'cycle': $native['relatesTo'] = ['code' => 'appends', 'targetIdentifier' => $this->compositionReference('composition', $uuid)['identifier']];
                break;
        }
        config(['compositions.emal' => $settings]);
        $this->responses($native);
        Bus::fake();
        $component->call('submitComposition');
        if ($error === null) {
            $component->assertHasNoErrors()->assertSet('asyncJobStatus', 'PENDING');
            Http::assertSent(static fn ($request): bool => $request->method() === 'POST');
        } else {
            $component->assertSee(__('compositions.errors.'.$error))->assertSet('asyncJobId', null);
            Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
            Bus::assertNothingDispatched();
            $this->assertSame(0, CompositionOperation::count());
        }
    }

    public static function refinementCases(): array
    {
        return ['signed pair' => ['SIGNED', true, true], 'approved only' => ['APPROVED', true, false],
            'foreign pair' => ['SIGNED', false, false], 'no merge' => [null, true, false]];
    }

    #[DataProvider('refinementCases')]
    public function test_post_identification_refinement_requires_the_signed_patient_pair(?string $status, bool $samePatient, bool $allowed): void
    {
        [$component, $fixture, $patient] = $this->ready(identified: true);
        $preperson = $this->compositionPreperson();
        $native = $this->document($fixture, $preperson, (string) Str::uuid(), (string) Str::uuid());
        $previous = Repository::composition()->store($native, $preperson, (string) Str::uuid());
        if ($status !== null) {
            MergeRequest::create(['uuid' => (string) Str::uuid(), 'master_person_id' => $samePatient ? $patient->id : Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE'])->id,
                'merge_person_id' => $preperson->id, 'status' => $status]);
        }
        $component->set('form.relatesToCode', 'replaces')->set('form.relatesToTargetUuid', $previous->uuid);
        $this->responses($native);
        $component->call('submitComposition');
        if ($allowed) {
            $component->assertHasNoErrors()->assertSet('asyncJobStatus', 'PENDING');
            Http::assertSent(static fn ($request): bool => $request->method() === 'POST');
        } else {
            $component->assertHasErrors('form.guard')->assertSee(__('compositions.errors.related_not_clarifiable'));
            Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
            $this->assertSame(0, CompositionOperation::count());
        }
    }

    public function test_enabled_preperson_duplicate_check_reads_native_search_before_post(): void
    {
        [$component, $fixture, $patient] = $this->ready();
        $settings = config('compositions.emal');
        $settings[CompositionConfiguration::DISABILITY_DUPLICATE_ENABLED->value] = true;
        config(['compositions.emal' => $settings]);
        $native = $this->document($fixture, $patient, (string) Str::uuid(), (string) Str::uuid());
        $this->fakeEHealth(['*searchComposition*' => Http::response(['data' => [$native]])]);
        $component->call('submitComposition')->assertHasErrors('form.guard')->assertSee(__('compositions.errors.preperson_duplicate'));
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
    }

    public static function laterChainLinks(): array
    {
        return ['later extension' => ['appends', 'related_not_last'], 'later replacement' => ['replaces', 'related_not_last'],
            'unrelated conclusion' => ['unrelated', null], 'missing read context' => ['context', 'related_unreadable']];
    }

    #[DataProvider('laterChainLinks')]
    public function test_refinement_reads_unsynchronized_later_links_before_post(string $case, ?string $error): void
    {
        [$component, $fixture, $patient] = $this->ready(identified: true);
        $native = $this->document($fixture, $patient, (string) Str::uuid(), (string) Str::uuid());
        $previous = Repository::composition()->store($native, $patient, (string) Str::uuid());
        $later = $this->document($fixture, $patient, (string) Str::uuid(), (string) Str::uuid());
        if ($case !== 'unrelated') {
            $later['relatesTo'] = ['code' => $case === 'replaces' ? 'replaces' : 'appends',
                'targetIdentifier' => $this->compositionReference('composition', $previous->uuid)['identifier']];
        }
        $summary = ['identifier' => $later['identifier'], 'status' => 'FINAL', 'encounter' => $later['encounter'],
            'episodeOfCare' => $this->compositionReference('episode', (string) Str::uuid())['identifier']];
        if ($case === 'context') {
            unset($summary['episodeOfCare']);
        }
        $component->set('form.relatesToCode', 'replaces')->set('form.relatesToTargetUuid', $previous->uuid);
        $this->fakeEHealth([
            '*authentication_methods*' => Http::response(['data' => []]),
            '*searchComposition*' => Http::response(['data' => [$summary]]),
            '*'.$later['identifier']['value'].'*' => Http::response($later),
            '*/composition' => Http::response(['data' => ['id' => (string) Str::uuid(), 'status' => 'PENDING', 'eta' => now()->toIso8601String(), 'links' => []]]),
            '*' => Http::response($native),
        ]);
        Bus::fake();
        $component->call('submitComposition');
        if ($error === null) {
            $component->assertHasNoErrors()->assertSet('asyncJobStatus', 'PENDING');
            Http::assertSent(static fn ($request): bool => $request->method() === 'POST');
        } else {
            $component->assertHasErrors('form.guard')->assertSee(__('compositions.errors.'.$error));
            Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
            Bus::assertNothingDispatched();
            $this->assertSame(0, CompositionOperation::count());
        }
    }

    #[DataProvider('identificationBeforeSigning')]
    public function test_refinement_rechecks_the_signed_pair_immediately_before_kep(bool $signed): void
    {
        [$component, $fixture, $patient, $encounter] = $this->ready(identified: true, signing: true);
        $preperson = $this->compositionPreperson();
        $previousNative = $this->document($fixture, $preperson, (string) Str::uuid(), (string) Str::uuid());
        $previous = Repository::composition()->store($previousNative, $preperson, (string) Str::uuid());
        $merge = MergeRequest::create(['uuid' => (string) Str::uuid(), 'master_person_id' => $patient->id,
            'merge_person_id' => $preperson->id, 'status' => 'SIGNED']);
        $native = $this->document($fixture, $patient, (string) Str::uuid(), $encounter);
        $native['status'] = 'PRELIMINARY';
        $native['relatesTo'] = ['code' => 'replaces', 'targetIdentifier' => $this->compositionReference('composition', $previous->uuid)['identifier']];
        $this->fakeSignatureService('signed-composition');
        $this->fakeEHealth([
            '*authentication_methods*' => Http::response(['data' => []]),
            '*searchComposition*' => Http::response(['data' => []]), '*integrationData*' => Http::response(['data' => []]),
            '*'.$previous->uuid.'*' => Http::response($previousNative),
            '*/composition' => Http::response(['data' => ['id' => (string) Str::uuid(), 'status' => 'PENDING', 'eta' => now()->toIso8601String(), 'links' => []]]),
            '*/composition/job/*' => Http::response(['data' => ['id' => (string) Str::uuid(), 'status' => 'DONE', 'eta' => now()->toIso8601String(),
                'links' => [['entity' => 'eHealth/resources', 'href' => '/composition/'.$native['identifier']['value']]]]]),
            '*/sign' => Http::response(['data' => ['id' => (string) Str::uuid(), 'status' => 'PENDING', 'eta' => now()->toIso8601String(), 'links' => []]]),
            '*' => Http::response($native),
        ]);
        $component->set('form.relatesToCode', 'replaces')->set('form.relatesToTargetUuid', $previous->uuid)
            ->call('submitComposition')->tap(fn ($test) => $this->processCompositionWorker($test->get('asyncJobId')))
            ->call('pollAsyncJob')->assertHasNoErrors()->assertSet('compositionUuid', $native['identifier']['value']);
        if (!$signed) {
            $merge->update(['status' => 'APPROVED']);
            // Changing the public form cannot turn the prior preperson into the actual current patient.
            $component->set('form.subjectUuid', $preperson->uuid);
        }
        $component->call('openSigningModal')
            ->set('form.knedp', 'ca-1')->set('form.password', 'secret')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 8))->call('sign');
        if ($signed) {
            $component->assertHasNoErrors()->assertSet('showSignatureModal', false);
            app(SignatureService::class)->shouldHaveReceived('signData')->once();
            Http::assertSent(static fn ($request): bool => $request->method() === 'PATCH');
        } else {
            $component->assertHasErrors('form.guard')->assertSee(__('compositions.errors.related_not_clarifiable'))
                ->assertSet('showSignatureModal', true);
            app(SignatureService::class)->shouldNotHaveReceived('signData');
            Http::assertNotSent(static fn ($request): bool => $request->method() === 'PATCH');
            $this->assertSame(1, CompositionOperation::count());
        }
    }

    public static function identificationBeforeSigning(): array
    {
        return ['signed pair' => [true], 'pair no longer signed' => [false]];
    }

    public static function pregnancyContinuations(): array
    {
        return ['next day' => ['valid', true], 'gap' => ['gap', false], 'already appended' => ['appended', false]];
    }

    #[DataProvider('pregnancyContinuations')]
    public function test_pregnancy_continuation_starts_next_day_and_is_allowed_only_once(string $case, bool $allowed): void
    {
        [$component, $fixture, $patient] = $this->ready(identified: true);
        $native = $this->document($fixture, $patient, (string) Str::uuid(), (string) Str::uuid());
        $native['category']['coding'][0]['code'] = 'PREGNANCY';
        if ($case === 'appended') {
            $native['relatesTo'] = ['code' => 'appends', 'targetIdentifier' => $this->compositionReference('composition', (string) Str::uuid())['identifier']];
        }
        $previous = Repository::composition()->store($native, $patient, (string) Str::uuid());
        $start = $case === 'gap' ? today()->addDay() : today();
        $component->set('form.category', 'PREGNANCY')->set('form.relatesToCode', 'appends')->set('form.relatesToTargetUuid', $previous->uuid)
            ->set('form.eventPeriodStart', $start->format('d.m.Y'))->set('form.eventPeriodEnd', $start->addDays(13)->format('d.m.Y'));
        $this->responses($native);
        Bus::fake();
        $component->call('submitComposition');
        if ($allowed) {
            $component->assertHasNoErrors()->assertSet('asyncJobStatus', 'PENDING');
            Http::assertSent(static fn ($request): bool => $request->method() === 'POST');
        } else {
            $component->assertHasErrors('form.guard')->assertSee(__('compositions.errors.related_period_overlap'));
            Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
            Bus::assertNothingDispatched();
            $this->assertSame(0, CompositionOperation::count());
        }
    }

    private function ready(bool $identified = false, bool $signing = false): array
    {
        $scopes = ['composition:create', 'composition:read', 'composition:search'];
        if ($signing) {
            $scopes[] = 'composition:sign';
        }
        $fixture = $this->compositionFixture(scopes: $scopes);
        $patient = $identified ? $fixture['person'] : $this->compositionPreperson();
        $encounter = (string) Str::uuid();
        $this->fakeEHealth([
            '*authentication_methods*' => Http::response(['data' => []]),
            '*' => Http::response($this->eHealthBody([$this->compositionEncounter($encounter, $fixture['employee']->uuid)])),
        ]);
        $component = Livewire::test(CompositionTempDisabilityCreate::class, array_merge(
            ['legalEntity' => $fixture['legalEntity']],
            $identified ? ['personId' => $patient->id] : ['preperson' => $patient]
        ))
            ->call('selectEncounter', $encounter)->call('skipAuthMethod')
            ->set('acknowledgedUnidentifiedErln', true)
            ->set('form.eventPeriodStart', today()->format('d.m.Y'))->set('form.eventPeriodEnd', today()->format('d.m.Y'));

        return [$component, $fixture, $patient, $encounter];
    }

    private function document(array $fixture, Person|Preperson $patient, string $uuid, string $encounter): array
    {
        $subject = $this->compositionReference($patient instanceof Preperson ? 'preperson' : 'person', $patient->uuid)['identifier'];

        return $this->nativeCompositionDocument([
            'identifier' => ['value' => $uuid], 'status' => 'FINAL', 'date' => now()->toIso8601String(),
            'subject' => $subject, 'section' => ['focus' => $subject],
            'author' => ['value' => $fixture['employee']->uuid], 'encounter' => ['value' => $encounter],
            'custodian' => ['value' => $fixture['legalEntity']->uuid],
            'event' => [['period' => ['start' => today()->subDays(3)->toIso8601String(), 'end' => today()->subDay()->endOfDay()->toIso8601String()]]],
        ]);
    }

    private function responses(array $native): void
    {
        $this->fakeEHealth([
            '*authentication_methods*' => Http::response(['data' => []]), '*searchComposition*' => Http::response(['data' => []]),
            '*/composition' => Http::response(['data' => ['id' => (string) Str::uuid(), 'status' => 'PENDING', 'eta' => now()->toIso8601String(), 'links' => []]]),
            '*' => Http::response($native),
        ]);
    }
}
