<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Classes\eHealth\EHealth;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Tv39CompositionContractTest extends TestCase
{
    use CompositionTestFixtures;

    public function test_complete_document_remains_exact_for_signing(): void
    {
        $document = $this->document();
        $this->fakeEHealth(['*' => Http::response(['data' => $document])]);
        $this->assertSame($document, EHealth::composition()->getById('patient', 'composition', 'episode', 'encounter')->validate());
    }

    public function test_documented_pregnancy_category_is_accepted_without_loosening_the_enum(): void
    {
        // TV39 3.8.2.5.4 and Create Composition explicitly require this category, omitted by Swagger 2.39.2.
        $document = $this->document();
        $document['category']['coding'][0]['code'] = 'PREGNANCY';
        $this->fakeEHealth(['*' => Http::response(['data' => $document])]);
        $this->assertSame($document, EHealth::composition()->getById('patient', 'composition', 'episode', 'encounter')->validate());
    }

    #[DataProvider('malformedDocumentFields')]
    public function test_all_document_boundaries_reject_malformed_native_fields(string $field, mixed $value): void
    {
        $document = $this->document();
        data_set($document, $field, $value);
        $this->fakeEHealth(['*' => Http::response(['data' => $document])]);
        $this->expectException(ValidationException::class);
        EHealth::composition()->getById('patient', 'composition', 'episode', 'encounter')->validate();
    }

    public static function malformedDocumentFields(): array
    {
        return [
            ['author.value', 'bad'], ['author.type.coding.0.system', null],
            ['subject.type', null], ['custodian.value', 15], ['identifier.type', null],
            ['category.coding.0.code', 'UNKNOWN'], ['type.coding.0.code', 'BAD'],
            ['section.focus.value', false], ['date', '2026-99-99T10:00:00Z'],
            ['event.0.period.start', 'today'], ['event.0.period', []],
            ['extension.0.valueBoolean', 'false'], ['extension.0.valueCode', 'IS_UNKNOWN'],
            ['relatesTo.0.targetIdentifier.value', 'bad'], ['status', 'DONE'],
        ];
    }

    public function test_missing_required_detail_is_refused(): void
    {
        $document = Arr::except($this->document(), 'encounter');
        $this->fakeEHealth(['*' => Http::response(['data' => $document])]);
        $this->expectException(ValidationException::class);
        EHealth::composition()->getById('patient', 'composition', 'episode', 'encounter')->validate();
    }

    public function test_search_checks_optional_embedded_details_before_display(): void
    {
        $document = $this->document();
        unset($document['author']['type']);
        $this->fakeEHealth(['*' => Http::response(['data' => [$document]])]);
        $this->expectException(ValidationException::class);
        EHealth::composition()->search()->validate();
    }

    #[DataProvider('missingJobFields')]
    public function test_missing_job_field_never_becomes_a_completed_application_result(string $field): void
    {
        $job = ['id' => '89678f60-4cdc-4fe3-ae83-e8b3ebd35c59', 'eta' => '2026-11-11T12:00:00Z', 'links' => [], 'status' => 'DONE'];
        unset($job[$field]);
        $this->fakeEHealth(['*' => Http::response(['data' => $job])]);
        $this->expectException(ValidationException::class);
        EHealth::composition()->getAsyncJobStatus('job')->validate();
    }

    public static function missingJobFields(): array
    {
        return [['id'], ['eta'], ['links'], ['status']];
    }

    public function test_missing_integration_status_is_refused_before_retry_decision(): void
    {
        $this->fakeEHealth(['*' => Http::response(['data' => [['component' => 'ERLN', 'type' => 'CREATE_ERLN_RECORD']]])]);
        $this->expectException(ValidationException::class);
        EHealth::composition()->getIntegrationData('patient', 'composition', 'episode', 'encounter')->validate();
    }

    public function test_documented_nullable_old_integration_date_is_allowed(): void
    {
        $data = [['component' => 'ERLN', 'type' => 'CREATE_ERLN_RECORD', 'statusCode' => 1101, 'statusMessage' => 'OK', 'integrationStatus' => 'DONE', 'taskStatus' => 'DONE', 'updatedAt' => null, 'details' => []]];
        $this->fakeEHealth(['*' => Http::response(['data' => $data])]);
        $this->assertSame($data, EHealth::composition()->getIntegrationData('patient', 'composition', 'episode', 'encounter')->validate());
    }

    public function test_print_response_preserves_native_utf8_bytes(): void
    {
        $body = "Медичний висновок\n<div>MV-01</div>\n";
        $this->fakeEHealth(['*' => Http::response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8'])]);
        $this->assertSame(['content' => $body], EHealth::composition()->getPrintForm('patient', 'composition', 'episode', 'encounter')->validate());
    }

    public function test_json_error_is_not_accepted_as_a_print_document(): void
    {
        $this->fakeEHealth(['*' => Http::response(['error' => 'Unavailable'], 200, ['Content-Type' => 'application/json'])]);
        $this->expectException(ValidationException::class);
        EHealth::composition()->getPrintForm('patient', 'composition', 'episode', 'encounter')->validate();
    }

    public function test_search_all_reads_the_last_offset_page(): void
    {
        $page = [];
        for ($i = 0; $i < 100; $i++) {
            $item = $this->document();
            $item['identifier']['value'] = (string) Str::uuid();
            $page[] = $item;
        }
        $last = $this->document();
        $this->fakeEHealth(['*' => static fn ($request) => Http::response(['data' => (int) $request['offset'] === 0 ? $page : [$last]])]);
        $results = EHealth::composition()->searchAll(['subject' => $last['subject']['value']]);
        $this->assertCount(101, $results);
        $this->assertSame($last, $results[100]);
        Http::assertSentCount(2);
    }

    public function test_search_all_rejects_a_repeated_native_page(): void
    {
        $page = [];
        for ($i = 0; $i < 100; $i++) {
            $item = $this->document();
            $item['identifier']['value'] = (string) Str::uuid();
            $page[] = $item;
        }
        $this->fakeEHealth(['*' => Http::response(['data' => $page])]);
        $this->expectException(ValidationException::class);
        EHealth::composition()->searchAll([]);
    }

    #[DataProvider('invalidCatalogValues')]
    public function test_catalog_native_types_are_checked_before_decoding_settings(string $field, mixed $value): void
    {
        $row = ['id' => (string) Str::uuid(), 'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'DRIVERS']]],
            'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => 'DRIVERS_GROUP1']]],
            'is_active' => true, 'settings' => '{}', 'inserted_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(), 'inserted_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid()];
        $body = $this->eHealthBody([$row]);
        data_set($body, $field, $value);
        $this->fakeEHealth(['*' => Http::response($body)]);
        $this->expectException(ValidationException::class);
        EHealth::configuration()->getCompositions()->validate();
    }

    public static function invalidCatalogValues(): array
    {
        return [['data.0.is_active', '1'], ['data.0.settings', []], ['paging.page_size', '1']];
    }

    private function document(): array
    {
        $uuid = '89678f60-4cdc-4fe3-ae83-e8b3ebd35c59';
        $reference = $this->compositionReference('person', $uuid)['identifier'];

        return [
            'identifier' => $this->compositionReference('composition', $uuid)['identifier'],
            'status' => 'PRELIMINARY', 'title' => 'MV-001', 'date' => '2026-11-11T10:00:00Z',
            'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'TEMP_DISABILITY']]],
            'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => 'SICKNESS']]],
            'author' => $reference, 'subject' => $reference, 'custodian' => $reference, 'encounter' => $reference,
            'section' => ['focus' => $reference],
            'event' => [['period' => ['start' => '2026-11-11T00:00:01Z', 'end' => '2026-11-15T20:59:59Z']]],
            'extension' => [['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => true]],
            'relatesTo' => [['code' => 'replaces', 'targetIdentifier' => $reference]],
        ];
    }
}
