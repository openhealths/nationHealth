<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Classes\eHealth\EHealth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompositionResponseValidationTest extends TestCase
{
    use CompositionTestFixtures;

    public function test_details_preserve_the_exact_document_for_signing(): void
    {
        $payload = $this->document();
        $this->fakeEHealth(['*' => Http::response(['data' => $payload])]);

        $this->assertSame($payload, EHealth::composition()->getById('patient', 'composition', 'episode', 'encounter')->validate());
    }

    public function test_search_preserves_details_and_date_when_provided(): void
    {
        $payload = $this->document();
        $this->fakeEHealth(['*' => Http::response(['data' => [$payload]])]);
        $validated = EHealth::composition()->search()->validate()[0];

        foreach (['date', 'author', 'custodian', 'section', 'extension', 'relatesTo'] as $field) {
            $this->assertSame($payload[$field], $validated[$field]);
        }
    }

    public function test_a_write_response_without_a_job_identifier_is_rejected(): void
    {
        $this->fakeEHealth(['*' => Http::response(['data' => ['status' => 'PENDING']])]);
        $this->expectException(ValidationException::class);

        EHealth::composition()->create([])->validate();
    }

    public function test_invalid_nested_reference_is_rejected_before_signing(): void
    {
        $payload = $this->document();
        $payload['section']['focus']['value'] = 'not-a-uuid';
        $this->fakeEHealth(['*' => Http::response(['data' => $payload])]);
        $this->expectException(ValidationException::class);

        EHealth::composition()->getById('patient', 'composition', 'episode', 'encounter')->validate();
    }

    public function test_job_errors_in_links_and_response_data_are_normalized(): void
    {
        $this->fakeEHealth(['*' => Http::response(['data' => [
            'status' => 'FAILED',
            'response_data' => ['error' => ['code' => 1172, 'message' => 'Invalid date']],
            'links' => [['error' => ['message' => 'Another failure']]],
        ]])]);

        $job = EHealth::composition()->getAsyncJobStatus('job')->validate();

        $this->assertSame([__('errors.ehealth.rules.1172'), 'Another failure'], $job['errors']);
    }

    private function document(): array
    {
        $uuid = '89678f60-4cdc-4fe3-ae83-e8b3ebd35c59';

        return [
            'identifier' => ['value' => $uuid],
            'status' => 'PRELIMINARY',
            'date' => '2026-09-01T10:00:00Z',
            'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'TEMP_DISABILITY']]],
            'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => 'SICKNESS']]],
            'author' => ['value' => $uuid],
            'custodian' => ['value' => $uuid],
            'section' => ['focus' => ['value' => $uuid]],
            'extension' => [['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => true]],
            'relatesTo' => ['code' => 'replaces', 'targetIdentifier' => ['value' => $uuid]],
            'event' => [['period' => ['start' => '2026-09-01T00:00:01Z', 'end' => '2026-09-05T20:59:59Z']]],
        ];
    }
}
