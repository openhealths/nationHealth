<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealthResponse;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared behaviour of the medication, service and device request lifecycles.
 *
 * Each of them targets a different eHealth endpoint group but treats the answers the same
 * way: unwrap the `data` envelope, name the request type when logging a failure, and resolve
 * a prequalify job before trusting its verdict.
 */
abstract class EHealthRequestLifecycleService
{
    public function __construct(
        protected readonly EHealthJobResolver $jobResolver,
    ) {
    }

    /**
     * How this request type is named in log messages.
     */
    abstract protected function requestType(): string;

    /**
     * @param  Closure(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    protected function callEHealth(string $operation, Closure $call): array
    {
        try {
            $response = $call();
        } catch (Throwable $exception) {
            Log::error($this->requestType().' '.$operation.' failed: '.$exception->getMessage());

            throw $exception;
        }

        return $response['data'] ?? $response;
    }

    /**
     * Prequalify answers with a job whose verdict decides whether the medical program may be
     * used, so the job is resolved and inspected before anything is written locally.
     *
     * @param  EHealthResponse|array<string, mixed>  $response
     *
     * @throws \App\Exceptions\EHealth\EHealthValidationException when the program is rejected
     */
    protected function runPrequalify(EHealthResponse|array $response): void
    {
        $data = $response instanceof EHealthResponse ? $response->getData() : $response;

        $this->jobResolver->assertPrequalifyValid($this->jobResolver->resolve($data));
    }

    /**
     * KEP signing needs the signer's tax id. It arrives with the rest of the signing form so
     * that signing does not depend on an authenticated session.
     *
     * @param  array<string, mixed>  $formData
     */
    protected function resolveSignerTaxId(array $formData): string
    {
        $taxId = trim((string) ($formData['signer_tax_id'] ?? ''));

        if ($taxId === '') {
            throw new \InvalidArgumentException(__('care-plan.signer_tax_id_required'));
        }

        return $taxId;
    }
}
