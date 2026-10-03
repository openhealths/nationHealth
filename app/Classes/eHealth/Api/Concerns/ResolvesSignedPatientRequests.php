<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Concerns;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Promise\PromiseInterface;

trait ResolvesSignedPatientRequests
{
    abstract public function createSigned(string $patientId, array $payload): PromiseInterface|EHealthResponse;

    abstract public function cancel(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse;

    abstract public function prequalify(string $patientId, array $payload): PromiseInterface|EHealthResponse;

    public function createSignedAndResolve(string $patientId, string $signedContent): array
    {
        return EHealth::job()->resolve($this->createSigned($patientId, [
            'signed_data' => $signedContent,
            'signed_data_encoding' => 'base64',
        ])->getData());
    }

    public function cancelAndResolve(string $patientId, string $id, array $payload): array
    {
        return EHealth::job()->resolve($this->cancel($patientId, $id, $payload)->getData());
    }

    public function prequalifyAndValidate(string $patientId, array $payload): array
    {
        $job = EHealth::job();
        $result = $job->resolve($this->prequalify($patientId, $payload)->getData());
        $job->assertPrequalifyValid($result);

        return $result;
    }
}
