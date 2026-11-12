<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\Signature\SignatureException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Qualified electronic signature (KEP) provider used to sign data before it is sent to eHealth.
 */
interface SignatureProvider
{
    /**
     * Sign JSON-serializable data.
     *
     * @param  array  $data
     * @param  string  $knedp
     * @param  TemporaryUploadedFile  $keyContainer
     * @param  string  $password
     * @param  string  $taxId
     * @param  string|null  $edrpou
     * @return string Signed container in base64
     * @throws SignatureException
     */
    public function signData(
        array $data,
        string $knedp,
        TemporaryUploadedFile $keyContainer,
        string $password,
        string $taxId,
        ?string $edrpou = null
    ): string;

    /**
     * Sign content that is already base64-encoded, e.g. co-sign a contract that the NHSU signed first.
     *
     * @param  string  $base64Data
     * @param  string  $knedp
     * @param  TemporaryUploadedFile  $keyContainer
     * @param  string  $password
     * @param  string  $taxId
     * @param  string|null  $edrpou
     * @return string Signed container in base64
     * @throws SignatureException
     */
    public function signBase64Data(
        string $base64Data,
        string $knedp,
        TemporaryUploadedFile $keyContainer,
        string $password,
        string $taxId,
        ?string $edrpou = null
    ): string;

    /**
     * Certificate authorities (KNEDP) the provider supports.
     *
     * @return array
     */
    public function getCertificateAuthorities(): array;
}
