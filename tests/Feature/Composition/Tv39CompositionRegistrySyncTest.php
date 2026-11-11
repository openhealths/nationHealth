<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Livewire\Composition\Concerns\InteractsWithCompositions;
use App\Livewire\Person\Records\PatientCompositions;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Period;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Tv39CompositionRegistrySyncTest extends TestCase
{
    use CompositionTestFixtures;
    use RefreshCompositionDatabase;

    #[DataProvider('patientKinds')]
    public function test_minimal_published_summary_adopts_only_the_validated_detail_author(string $kind): void
    {
        ['person' => $person, 'employee' => $employee, 'legalEntity' => $entity] = $this->compositionFixture();
        $patient = $kind === 'preperson' ? $this->compositionPreperson() : $person;
        $document = $this->document($patient->uuid, $kind);
        $summary = $this->summary($document);
        if ($kind === 'preperson') {
            $document['episodeOfCare'] = $summary['episodeOfCare'];
        }
        $before = $this->storageCounts();
        $this->assertArrayNotHasKey('author', $summary);
        $this->assertArrayNotHasKey('subject', $summary);
        $this->assertNotSame($employee->uuid, $document['author']['value']);
        $this->fakeEHealth([
            '*searchComposition*' => Http::response(['data' => [$summary]]),
            $this->detailUrl($patient->uuid, $summary) => function () use ($before, $document) {
                $this->assertSame($before, $this->storageCounts(), 'No summary may be written before full validation.');

                return Http::response(['data' => $document]);
            },
        ]);
        $mount = $kind === 'preperson' ? ['preperson' => $patient] : ['personId' => $patient->id];

        Livewire::test(PatientCompositions::class, ['legalEntity' => $entity] + $mount)
            ->call('sync')->assertSee(__('compositions.messages.sync_success'))
            ->assertDontSee(__('compositions.errors.invalid_response'));

        $record = Repository::composition()->findForPatient($patient, $document['identifier']['value']);
        $this->assertNotNull($record);
        $this->assertSame($document['author']['value'], $record->authorUuid);
        $this->assertSame($patient->uuid, $record->patientUuid);
        $this->assertSame($document['title'], $record->title);
        $this->assertSame($summary['episodeOfCare']['value'], $record->episodeOfCareUuid);
        Http::assertSentCount(2);
    }

    public static function patientKinds(): array
    {
        return [['person'], ['preperson']];
    }

    #[DataProvider('invalidDetails')]
    public function test_invalid_later_detail_leaves_all_records_and_relations_unchanged(string $case): void
    {
        ['person' => $person, 'legalEntity' => $entity] = $this->compositionFixture();
        $first = $this->document($person->uuid);
        $firstSummary = $this->summary($first);
        $existing = Repository::composition()->store(array_replace($first, ['title' => 'Original local title']), $person, $firstSummary['episodeOfCare']['value']);
        $original = $existing->toDetail();
        $second = $this->document($person->uuid);
        $secondSummary = $this->summary($second);
        $knownRecord = null;
        if ($case === 'known_author') {
            $secondSummary['author'] = $this->compositionReference('employee', (string) Str::uuid())['identifier'];
        } elseif ($case === 'known_local_author') {
            $known = $second;
            $known['author']['value'] = (string) Str::uuid();
            $knownRecord = Repository::composition()->store($known, $person, $secondSummary['episodeOfCare']['value']);
            $knownDetails = $knownRecord->toDetail();
        } elseif ($case === 'missing_author') {
            unset($second['author']);
        } elseif ($case === 'malformed_author') {
            $second['author']['value'] = 'invalid-uuid';
        } elseif ($case === 'null_author') {
            $second['author'] = null;
        } elseif ($case === 'wrong_type') {
            $second['type']['coding'][0]['code'] = 'NEWBORN';
        } elseif ($case === 'wrong_episode') {
            $second['episodeOfCare'] = $this->compositionReference('episode', (string) Str::uuid())['identifier'];
        } elseif ($case === 'wrong_episode_kind') {
            $second['episodeOfCare'] = $secondSummary['episodeOfCare'];
            $second['episodeOfCare']['type']['coding'][0]['code'] = 'encounter';
        } elseif ($case === 'malformed_event') {
            $second['event'] = null;
        } else {
            [$field, $part] = explode(':', $case);
            if ($part === 'value') {
                $second[$field]['value'] = (string) Str::uuid();
            } else {
                $second[$field]['type']['coding'][0]['code'] = $field === 'subject' ? 'preperson' : 'person';
            }
        }
        $before = $this->storageCounts();
        Bus::fake();
        $this->fakeEHealth([
            '*searchComposition*' => Http::response(['data' => [$firstSummary, $secondSummary]]),
            $this->detailUrl($person->uuid, $firstSummary) => Http::response(['data' => $first]),
            $this->detailUrl($person->uuid, $secondSummary) => Http::response(['data' => $second]),
        ]);

        Livewire::test(PatientCompositions::class, ['legalEntity' => $entity, 'personId' => $person->id])
            ->call('sync')->assertSee(__('compositions.errors.invalid_response'))
            ->assertDontSee(__('compositions.messages.sync_success'));

        $this->assertSame($before, $this->storageCounts());
        $this->assertSame($original, $existing->fresh()->toDetail());
        if ($knownRecord) {
            $this->assertSame($knownDetails, $knownRecord->fresh()->toDetail());
        } else {
            $this->assertDatabaseMissing('compositions', ['uuid' => $secondSummary['identifier']['value']]);
        }
        Http::assertSentCount(3);
        Bus::assertNothingDispatched();
    }

    public static function invalidDetails(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'known_author', 'known_local_author', 'missing_author', 'malformed_author', 'null_author', 'author:kind',
            'identifier:value', 'identifier:kind', 'subject:value', 'subject:kind',
            'encounter:value', 'encounter:kind', 'wrong_type', 'wrong_episode',
            'wrong_episode_kind', 'malformed_event',
        ]);
    }

    public function test_malformed_later_search_page_has_no_detail_reads_or_storage(): void
    {
        ['person' => $person, 'legalEntity' => $entity] = $this->compositionFixture();
        config(['ehealth.api.page_size' => 1]);
        $first = $this->summary($this->document($person->uuid));
        $second = $this->summary($this->document($person->uuid));
        unset($second['status']);
        $before = $this->storageCounts();
        $this->fakeEHealth(['*searchComposition*' => Http::sequence()->push(['data' => [$first]])->push(['data' => [$second]])]);

        Livewire::test(PatientCompositions::class, ['legalEntity' => $entity, 'personId' => $person->id])
            ->call('sync')->assertSee(__('compositions.errors.invalid_response'))
            ->assertDontSee(__('compositions.messages.sync_success'));

        $this->assertSame($before, $this->storageCounts());
        Http::assertSentCount(2);
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/episode/'));
    }

    public function test_storage_refusal_rolls_back_an_earlier_valid_document(): void
    {
        ['person' => $person, 'legalEntity' => $entity] = $this->compositionFixture();
        $first = $this->document($person->uuid);
        $firstSummary = $this->summary($first);
        $foreign = $this->compositionPreperson();
        $second = $this->document($foreign->uuid, 'preperson');
        $foreignRecord = Repository::composition()->store($second, $foreign);
        $original = $foreignRecord->toDetail();
        $second['subject'] = $this->compositionReference('person', $person->uuid)['identifier'];
        $secondSummary = $this->summary($second);
        $before = $this->storageCounts();
        $this->fakeEHealth([
            '*searchComposition*' => Http::response(['data' => [$firstSummary, $secondSummary]]),
            $this->detailUrl($person->uuid, $firstSummary) => Http::response(['data' => $first]),
            $this->detailUrl($person->uuid, $secondSummary) => Http::response(['data' => $second]),
        ]);

        try {
            Livewire::test(PatientCompositions::class, ['legalEntity' => $entity, 'personId' => $person->id])->call('sync');
            $this->fail('An existing Composition must not be reassigned to another patient.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('A Composition cannot be reassigned to another patient.', $exception->getMessage());
        }

        $this->assertSame($before, $this->storageCounts());
        $this->assertSame($original, $foreignRecord->fresh()->toDetail());
        $this->assertDatabaseMissing('compositions', ['uuid' => $first['identifier']['value']]);
    }

    public function test_search_permission_does_not_grant_a_full_detail_read(): void
    {
        ['person' => $person, 'legalEntity' => $entity] = $this->compositionFixture(scopes: ['composition:search']);
        $summary = $this->summary($this->document($person->uuid));
        $this->fakeEHealth(['*searchComposition*' => Http::response(['data' => [$summary]])]);

        Livewire::test(PatientCompositions::class, ['legalEntity' => $entity, 'personId' => $person->id])
            ->call('sync')->assertSee(__('compositions.messages.sync_success'));

        $record = Repository::composition()->findForPatient($person, $summary['identifier']['value']);
        $this->assertNotNull($record);
        $this->assertNull($record->authorUuid);
        $this->assertSame($summary['title'], $record->title);
        Http::assertSentCount(1);
    }

    public function test_focus_search_without_subject_cannot_adopt_a_document_for_the_viewed_patient(): void
    {
        ['person' => $person, 'legalEntity' => $entity] = $this->compositionFixture();
        $summary = $this->summary($this->document($person->uuid));
        $before = $this->storageCounts();
        $this->fakeEHealth(['*searchComposition*' => Http::response(['data' => [$summary]])]);

        Livewire::test(PatientCompositions::class, ['legalEntity' => $entity, 'personId' => $person->id])
            ->set('filterSectionFocusUuid', (string) Str::uuid())->call('search')
            ->assertSet('focusSearchResults.0.identifier.value', $summary['identifier']['value']);

        $this->assertSame($before, $this->storageCounts());
        Http::assertSentCount(1);
    }

    public function test_stored_summary_without_author_or_type_can_read_valid_full_details(): void
    {
        ['person' => $person] = $this->compositionFixture();
        $document = $this->document($person->uuid);
        $summary = $this->summary($document);
        unset($summary['type']);
        $summary['subject'] = $document['subject'];
        $record = Repository::composition()->store($summary, $person);
        $reader = new class
        {
            use InteractsWithCompositions;

            public function read(Composition $record): array
            {
                return $this->fetchComposition($record);
            }
        };
        $this->fakeEHealth([$this->detailUrl($person->uuid, $summary) => Http::response(['data' => $document])]);

        $this->assertSame($document, $reader->read($record));
        $this->assertNull($record->fresh()->authorUuid);
        Http::assertSentCount(1);
    }

    private function document(string $patientUuid, string $kind = 'person'): array
    {
        return $this->nativeCompositionDocument([
            'identifier' => ['value' => (string) Str::uuid()],
            'status' => 'FINAL', 'title' => 'Validated full conclusion',
            'author' => ['value' => (string) Str::uuid()],
            'subject' => $this->compositionReference($kind, $patientUuid)['identifier'],
            'section' => ['focus' => $this->compositionReference($kind, $patientUuid)['identifier']],
            'encounter' => ['value' => (string) Str::uuid()],
        ]);
    }

    private function summary(array $document): array
    {
        return array_replace(Arr::only($document, ['identifier', 'status', 'title', 'type', 'date', 'encounter']), [
            'title' => 'Minimal search summary',
            'episodeOfCare' => $this->compositionReference('episode', (string) Str::uuid())['identifier'],
        ]);
    }

    private function detailUrl(string $patientUuid, array $summary): string
    {
        return '*/'.$patientUuid.'/composition/'.$summary['identifier']['value'].'/episode/'.$summary['episodeOfCare']['value'].'/encounter/'.$summary['encounter']['value'];
    }

    private function storageCounts(): array
    {
        return [Composition::count(), CompositionOperation::count(), Identifier::count(), CodeableConcept::count(), Coding::count(), Period::count()];
    }
}
