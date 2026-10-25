<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\MedicationRequest\DraftResult;
use App\Dto\MedicationRequest\EhealthDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MedicationRequestDocumentTest extends TestCase
{
    public static function envelopes(): iterable
    {
        $raw = ['id' => 'request-id', 'status' => 'NEW', 'future_extension' => ['coding' => [], 'quantity' => 0, 'flag' => false]];
        yield 'flat' => [$raw, $raw];
        yield 'data' => [['data' => $raw], $raw];
        yield 'list' => [[$raw], $raw];
        yield 'data list' => [['data' => [$raw]], $raw];
        yield 'empty' => [[], []];
    }

    #[DataProvider('envelopes')]
    public function test_document_unwrapping_preserves_every_raw_field(array $response, array $expected): void
    {
        $this->assertSame($expected, EhealthDocument::unwrap($response));
    }

    public function test_resolved_draft_document_has_priority_over_create_response_without_reconstruction(): void
    {
        $raw = ['id' => 'accepted-id', 'person' => ['id' => 'patient-id'], 'future_extension' => ['list' => [], 'zero' => 0]];
        $result = new DraftResult(['data' => ['id' => 'old-id']], ['data' => $raw]);

        $this->assertSame($raw, $result->document());
        $this->assertSame('accepted-id', $result->uuid('local-fallback'));
        $this->assertNull($result->requestNumber());
    }

    public function test_raw_create_document_is_retained_when_job_only_reports_metadata(): void
    {
        $raw = ['id' => 'accepted-id', 'unknown' => ['raw' => 'retained']];
        $result = new DraftResult(['data' => $raw], ['id' => 'accepted-id', 'request_number' => 'RX-1', 'status' => 'processed']);

        $this->assertSame($raw, $result->document());
        $this->assertSame('accepted-id', $result->uuid('local-fallback'));
        $this->assertSame('RX-1', $result->requestNumber());
    }
}
