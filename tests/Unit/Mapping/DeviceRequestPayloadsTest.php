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
use Tests\Support\DeviceRequestPayloads;
use Tests\TestCase;

class DeviceRequestPayloadsTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Kyiv');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $inputs = require $directory.'/device-request-inputs.php';
        $baseline = json_decode(file_get_contents($directory.'/device-request-baseline.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($inputs as $name => $input) {
            yield $name => [$input, $baseline['cases'][$name], $baseline['now']];
        }
    }

    #[DataProvider('contracts')]
    public function test_dto_mapping_preserves_prequalify_and_signed_bytes_without_io(array $input, array $expected, string $now): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        // The DTO must use the supplied clock, even when the application's clock changes.
        CarbonImmutable::setTestNow('2030-01-01T00:00:00+00:00');
        $args = [$input['data'], $input['uuids'], CarbonImmutable::parse($now), $input['carePlanUuid'] ?? null, $input['activityUuid'] ?? null];
        $payloads = app(DeviceRequestPayloads::class);

        $this->assertSame($expected['prequalify'], $payloads->prequalify(...$args));
        $this->assertSame($expected['signedCreate'], $payloads->signedCreate(...$args));
        $this->assertSame($expected['signedJson'], json_encode($payloads->signedCreate(...$args), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_local_status_and_extra_fields_cannot_override_the_wire_contract(): void
    {
        [$input, $expected, $now] = iterator_to_array(self::contracts())['definition_care_plan'];
        $input['data'] += ['status' => 'draft', 'id' => 'wrong-id', 'authored_on' => 'wrong-time', 'private_note' => 'local-only', 'program' => ['injected' => true]];
        $args = [$input['data'], $input['uuids'], CarbonImmutable::parse($now), $input['carePlanUuid'], $input['activityUuid']];
        $payloads = app(DeviceRequestPayloads::class);

        $this->assertSame($expected['signedCreate'], $payloads->signedCreate(...$args));
        $this->assertSame($expected['prequalify'], $payloads->prequalify(...$args));
    }

    public function test_signature_service_receives_the_captured_device_json(): void
    {
        [$input, $expected, $now] = iterator_to_array(self::contracts())['definition_care_plan'];
        $cipher = Mockery::mock(CipherApi::class);
        $cipher->shouldReceive('sendSession')->once()->with($expected['signedJson'], 'test-password', base64_encode('synthetic-key'), 'test-knedp', '0000000000')->andReturn('signed-device');
        $file = UploadedFile::fake()->createWithContent('test.dat', 'synthetic-key');
        $upload = Mockery::mock(UploadedFile::class);
        $upload->shouldReceive('exists')->once()->andReturnTrue();
        $upload->shouldReceive('getClientOriginalExtension')->once()->andReturn('dat');
        $upload->shouldReceive('getRealPath')->once()->andReturn($file->getRealPath());
        $payload = app(DeviceRequestPayloads::class)->signedCreate($input['data'], $input['uuids'], CarbonImmutable::parse($now), $input['carePlanUuid'], $input['activityUuid']);

        $this->assertSame('signed-device', (new SignatureService($cipher))->signData($payload, 'test-password', 'test-knedp', $upload, '0000000000'));
    }
}
