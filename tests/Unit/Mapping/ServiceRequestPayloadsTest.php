<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Classes\Cipher\Api\CipherApi;
use App\Mapping\EHealth\Referral\ServiceRequestInput;
use App\Mapping\EHealth\Referral\ServiceRequestPayloads;
use App\Mapping\Transforms\FhirIdentifier;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\SignatureService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\TransformCallableInterface;
use Tests\TestCase;

class ServiceRequestPayloadsTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $inputs = require $directory.'/service-request-inputs.php';
        $baseline = json_decode(file_get_contents($directory.'/service-request-baseline.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($inputs as $name => $input) {
            yield $name => [$input, $baseline['cases'][$name], $baseline['now']];
        }
    }

    #[DataProvider('contracts')]
    public function test_object_mapping_preserves_prequalify_and_exact_signed_json(array $input, array $expected, string $now): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $source = $this->source($input, $now);
        $payloads = app(ServiceRequestPayloads::class);

        $this->assertSame($expected['prequalify'], $payloads->prequalify($source));
        $this->assertSame($expected['signedCreate'], $payloads->signedCreate($source));
        $this->assertSame($expected['signedJson'], json_encode($payloads->signedCreate($source), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    #[DataProvider('contracts')]
    public function test_legacy_entrypoints_preserve_the_captured_contract(array $input, array $expected, string $now): void
    {
        CarbonImmutable::setTestNow($now);
        $mapper = new ServiceRequestMapper();
        $arguments = [$input['data'], $input['uuids'], $input['carePlanUuid'] ?? null, $input['activityUuid'] ?? null];

        $this->assertSame($expected['prequalify'], $mapper->toPrequalifyPayload(...$arguments));
        $this->assertSame($expected['signedCreate'], $mapper->toCreateSignedContent(...$arguments));
        $this->assertSame($expected['legacySignedEnvelope'], $mapper->toCreateSignedPayload(...$arguments));
    }

    public function test_signature_service_receives_the_original_json_bytes(): void
    {
        [$input, $expected, $now] = iterator_to_array(self::contracts())['care_plan_all_fields'];
        $cipher = Mockery::mock(CipherApi::class);
        $cipher->shouldReceive('sendSession')->once()->with(
            $expected['signedJson'],
            'test-password',
            base64_encode('synthetic-key'),
            'test-knedp',
            '0000000000'
        )->andReturn('synthetic-signed-content');

        $keyFile = UploadedFile::fake()->createWithContent('test.dat', 'synthetic-key');
        $upload = Mockery::mock(UploadedFile::class);
        $upload->shouldReceive('exists')->once()->andReturnTrue();
        $upload->shouldReceive('getClientOriginalExtension')->once()->andReturn('dat');
        $upload->shouldReceive('getRealPath')->once()->andReturn($keyFile->getRealPath());

        $signed = (new SignatureService($cipher))->signData(
            app(ServiceRequestPayloads::class)->signedCreate($this->source($input, $now)),
            'test-password',
            'test-knedp',
            $upload,
            '0000000000',
        );

        $this->assertSame('synthetic-signed-content', $signed);
    }

    public function test_mapping_uses_the_supplied_time_instead_of_the_current_clock(): void
    {
        [$input, $expected, $now] = iterator_to_array(self::contracts())['care_plan_all_fields'];
        $source = $this->source($input, $now);
        CarbonImmutable::setTestNow('2030-01-01T00:00:00+00:00');

        $this->assertSame($expected['signedCreate'], app(ServiceRequestPayloads::class)->signedCreate($source));
    }

    public function test_nested_collections_use_the_laravel_transform_locator(): void
    {
        $this->app->instance(FhirIdentifier::class, new class implements TransformCallableInterface
        {
            public function __invoke(mixed $value, object $source, ?object $target): array
            {
                return ['value' => $value, 'system' => 'test-locator'];
            }
        });
        [$input, , $now] = iterator_to_array(self::contracts())['care_plan_all_fields'];
        $payload = app(ServiceRequestPayloads::class)->prequalify($this->source($input, $now));

        $this->assertSame('test-locator', $payload['service_request']['based_on'][0]['identifier']['system']);
        $this->assertSame('test-locator', $payload['service_request']['supporting_info'][0]['identifier']['system']);
        $this->assertSame('test-locator', $payload['programs'][0]['identifier']['system']);
    }

    private function source(array $input, string $now): ServiceRequestInput
    {
        return ServiceRequestInput::fromArray(
            $input['data'],
            $input['uuids'],
            CarbonImmutable::parse($now)->setTimezone(config('app.timezone')),
            $input['carePlanUuid'] ?? null,
            $input['activityUuid'] ?? null,
        );
    }
}
