<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Classes\eHealth\EHealthResponse;
use App\Contracts\EHealthRequestLifecycleContract;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use App\Services\MedicalEvents\EHealthJobResolver;
use App\Services\MedicalEvents\EHealthRequestLifecycleService;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EHealthRequestLifecycleServiceTest extends TestCase
{
    public function test_medication_and_device_lifecycles_share_the_same_contract(): void
    {
        // The twin standalone forms depend on the contract, not on either concrete service.
        $this->assertInstanceOf(
            EHealthRequestLifecycleContract::class,
            app(MedicationRequestLifecycleService::class)
        );
        $this->assertInstanceOf(
            EHealthRequestLifecycleContract::class,
            app(DeviceRequestLifecycleService::class)
        );
    }

    public function test_passthrough_calls_unwrap_the_data_envelope(): void
    {
        $service = $this->makeLifecycle();

        $this->assertSame(
            ['id' => 'request-uuid'],
            $service->call('Sign', static fn (): array => ['data' => ['id' => 'request-uuid']])
        );
    }

    public function test_passthrough_calls_return_flat_responses_untouched(): void
    {
        $service = $this->makeLifecycle();

        $this->assertSame(
            ['id' => 'request-uuid'],
            $service->call('Sign', static fn (): array => ['id' => 'request-uuid'])
        );
    }

    public function test_failures_are_logged_with_the_request_type_and_rethrown(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(static function (string $message): bool {
                return str_contains($message, 'Test Request Create Draft failed')
                    && str_contains($message, 'eHealth is unreachable');
            });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('eHealth is unreachable');

        $this->makeLifecycle()->call('Create Draft', static function (): array {
            throw new RuntimeException('eHealth is unreachable');
        });
    }

    public function test_signer_tax_id_must_be_supplied_by_the_caller(): void
    {
        $service = $this->makeLifecycle();

        $this->assertSame('1234567890', $service->signerTaxId(['signer_tax_id' => ' 1234567890 ']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('care-plan.signer_tax_id_required'));

        $service->signerTaxId([]);
    }

    public function test_prequalify_validates_the_receipt_before_resolving_the_verdict(): void
    {
        $data = [['program_id' => '50866c08-df21-4ea3-bcc4-e16a374460f4', 'status' => 'VALID']];
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn($data);
        $response->shouldNotReceive('getData');
        $resolver = Mockery::mock(EHealthJobResolver::class);
        $resolver->shouldReceive('resolve')->once()->with($data)->andReturn($data);
        $resolver->shouldReceive('assertPrequalifyValid')->once()->with($data);

        $this->makeLifecycle($resolver)->prequalify($response);
    }

    public function test_malformed_prequalify_receipt_never_reaches_job_resolution(): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andThrow(ValidationException::withMessages(['data' => 'Invalid receipt.']));
        $response->shouldNotReceive('getData');
        $resolver = Mockery::mock(EHealthJobResolver::class);
        $resolver->shouldNotReceive('resolve');
        $resolver->shouldNotReceive('assertPrequalifyValid');
        $this->expectException(ValidationException::class);

        $this->makeLifecycle($resolver)->prequalify($response);
    }

    private function makeLifecycle(?EHealthJobResolver $resolver = null): EHealthRequestLifecycleService
    {
        return new class ($resolver ?? Mockery::mock(EHealthJobResolver::class)) extends EHealthRequestLifecycleService
        {
            protected function requestType(): string
            {
                return 'Test Request';
            }

            /**
             * @param  Closure(): array<string, mixed>  $call
             * @return array<string, mixed>
             */
            public function call(string $operation, Closure $call): array
            {
                return $this->callEHealth($operation, $call);
            }

            /**
             * @param  array<string, mixed>  $formData
             */
            public function signerTaxId(array $formData): string
            {
                return $this->resolveSignerTaxId($formData);
            }

            public function prequalify(EHealthResponse|array $response): void
            {
                $this->runPrequalify($response);
            }
        };
    }
}
