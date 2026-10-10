<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Classes\Cipher\Api\CipherApi;
use App\Services\SignatureService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MedicationRequestPayloads;
use Tests\TestCase;

class MedicationRequestPayloadsTest extends TestCase
{
    public static function legacyPayloads(): iterable
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/../../Fixtures/Mapping/medication-request-outbound.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures as $name => $fixture) {
            yield $name => [$fixture];
        }
    }

    #[DataProvider('legacyPayloads')]
    public function test_create_prequalify_and_signing_fallback_match_the_unchanged_mapper(array $fixture): void
    {
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $mapper = app(MedicationRequestPayloads::class);
        $now = CarbonImmutable::parse('2026-10-05 12:15:30', 'Europe/Kyiv');
        $args = [$fixture['data'], $fixture['uuids'], $now, $fixture['carePlanUuid']];
        foreach (['create' => 'create', 'prequalify' => 'prequalify', 'signedContent' => 'signedContent'] as $key => $method) {
            $payload = $mapper->$method(...$args);
            $this->assertSame($fixture[$key], $payload, $key);
            $this->assertSame(json_encode($fixture[$key], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $key.' signing JSON bytes');
        }
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_signature_service_receives_the_original_fallback_json_bytes(): void
    {
        $fixture = iterator_to_array(self::legacyPayloads())['full dosage'][0];
        $cipher = Mockery::mock(CipherApi::class);
        $cipher->shouldReceive('sendSession')->once()->with(
            json_encode($fixture['signedContent'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'test-password',
            base64_encode('synthetic-key'),
            'test-knedp',
            '0000000000'
        )->andReturn('signed-erx');
        $file = UploadedFile::fake()->createWithContent('test.dat', 'synthetic-key');
        $upload = Mockery::mock(UploadedFile::class);
        $upload->shouldReceive('exists')->once()->andReturnTrue();
        $upload->shouldReceive('getClientOriginalExtension')->once()->andReturn('dat');
        $upload->shouldReceive('getRealPath')->once()->andReturn($file->getRealPath());
        $payload = app(MedicationRequestPayloads::class)->signedContent(
            $fixture['data'],
            $fixture['uuids'],
            CarbonImmutable::parse('2026-10-05 12:15:30', 'Europe/Kyiv'),
            $fixture['carePlanUuid']
        );

        $this->assertSame('signed-erx', (new SignatureService($cipher))->signData($payload, 'test-password', 'test-knedp', $upload, '0000000000'));
    }
}
