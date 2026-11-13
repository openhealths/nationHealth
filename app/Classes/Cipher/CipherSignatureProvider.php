<?php

declare(strict_types=1);

namespace App\Classes\Cipher;

use App\Classes\Cipher\Api\CipherRequest;
use App\Contracts\SignatureProvider;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherEncodingException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\Cipher\CipherResponseException;
use App\Exceptions\Cipher\CipherValidationException;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Signs data through the Cipher service: runs the session steps of {@see CipherRequest} in order and checks the key.
 */
class CipherSignatureProvider implements SignatureProvider
{
    /**
     * Sign JSON-serializable data.
     *
     * @param  array  $data
     * @param  string  $knedp
     * @param  string  $keyContainer  Key container in base64
     * @param  string  $password
     * @param  string  $taxId
     * @param  string|null  $edrpou
     * @return string
     * @throws CipherConnectionException|CipherResponseException|CipherValidationException|CipherEncodingException
     */
    public function signData(
        array $data,
        string $knedp,
        string $keyContainer,
        string $password,
        string $taxId,
        ?string $edrpou = null
    ): string {
        try {
            $encodedData = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CipherEncodingException(
                'Failed to encode data signature: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }

        return $this->signBase64Data(base64_encode($encodedData), $knedp, $keyContainer, $password, $taxId, $edrpou);
    }

    /**
     * Sign content that is already base64-encoded.
     * Steps: https://docs.cipher.com.ua/spaces/CCSUOS/pages/8618379/%D0%92%D0%B8%D0%BA%D0%BE%D0%BD%D0%B0%D0%BD%D0%BD%D1%8F+%D1%82%D0%B8%D0%BF%D0%BE%D0%B2%D0%B8%D1%85+%D0%B7%D0%B0%D0%B4%D0%B0%D1%87#id-%D0%92%D0%B8%D0%BA%D0%BE%D0%BD%D0%B0%D0%BD%D0%BD%D1%8F%D1%82%D0%B8%D0%BF%D0%BE%D0%B2%D0%B8%D1%85%D0%B7%D0%B0%D0%B4%D0%B0%D1%87-%D0%A1%D1%82%D0%B2%D0%BE%D1%80%D0%B5%D0%BD%D0%BD%D1%8F%D0%95%D0%9F.1
     *
     * @param  string  $base64Data
     * @param  string  $knedp
     * @param  string  $keyContainer  Key container in base64
     * @param  string  $password
     * @param  string  $taxId
     * @param  string|null  $edrpou
     * @return string
     * @throws CipherConnectionException|CipherResponseException|CipherValidationException
     */
    public function signBase64Data(
        string $base64Data,
        string $knedp,
        string $keyContainer,
        string $password,
        string $taxId,
        ?string $edrpou = null
    ): string {
        $cipher = new CipherRequest();
        $ticketUuid = $cipher->createSession()->getTicketUuid();

        try {
            $cipher->loadSessionData($ticketUuid, $base64Data);
            $cipher->setSessionParameters($ticketUuid, $knedp);
            $cipher->uploadKeyFile($ticketUuid, $keyContainer);
            $this->assertKeyCanSign($cipher->verifyKeyContainer($ticketUuid, $password)->response, $taxId, $edrpou);
            $cipher->initiateSignatureCreation($ticketUuid, $password);

            return $cipher->getSignedData($ticketUuid)->getBase64Data();
        } finally {
            $this->deleteSession($cipher, $ticketUuid);
        }
    }

    /**
     * Read the key owner's data (name, tax ID) from the key container.
     *
     * @param  string  $knedp
     * @param  string  $keyContainer  Key container in base64
     * @param  string  $password
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException|CipherValidationException
     */
    public function getPersonalData(
        string $knedp,
        string $keyContainer,
        string $password
    ): CipherResponse|PromiseInterface {
        $cipher = new CipherRequest();
        $ticketUuid = $cipher->createSession()->getTicketUuid();

        try {
            // Cipher needs session data before it accepts a key, so an empty JSON object is loaded
            $cipher->loadSessionData($ticketUuid, base64_encode('[]'));
            $cipher->setSessionParameters($ticketUuid, $knedp);
            $cipher->uploadKeyFile($ticketUuid, $keyContainer);

            return $cipher->verifyKeyContainer($ticketUuid, $password);
        } finally {
            $this->deleteSession($cipher, $ticketUuid);
        }
    }

    /**
     * Certificate authorities supported by Cipher, cached for 7 days.
     *
     * @return array
     */
    public function getCertificateAuthorities(): array
    {
        // A failed fetch is not cached, so the next request asks Cipher again instead of keeping an empty list
        try {
            return Cache::remember(
                'knedp_certificate_authority',
                now()->addDays(7),
                static fn (): array => new CipherRequest()->getCertificateAuthority()->response['ca']
            );
        } catch (CipherException|CipherConnectionException $exception) {
            Log::channel('cipher_errors')->error('Error fetching certificate authorities from Cipher', [
                'message' => $exception->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Refuse to sign with a key that cannot be used, has expired or belongs to someone other than the signer.
     *
     * @param  Response  $keyInfo  Response of the key container verification
     * @param  string  $taxId
     * @param  string|null  $edrpou
     * @return void
     * @throws CipherValidationException
     */
    protected function assertKeyCanSign(Response $keyInfo, string $taxId, ?string $edrpou = null): void
    {
        if (!$keyInfo['signature']['canBeUsed']) {
            throw new CipherValidationException(__('validation.custom.cipher.kepNotValid'), $keyInfo);
        }

        if (CarbonImmutable::parse($keyInfo['signature']['certificateInfo']['notAfter']['value'])->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new CipherValidationException(__('validation.custom.cipher.kepTimeExpired'), $keyInfo);
        }

        $keyOwner = Arr::get($keyInfo, 'signature.certificateInfo.extensionsCertificateInfo.value.personalData.value');

        if (($keyOwner['drfou']['value'] ?? '') !== $taxId) {
            throw new CipherValidationException(__('validation.custom.cipher.drfouDiffer'), $keyInfo);
        }

        // An empty EDRPOU in the key may belong to a sole proprietor key; eHealth decides on those later
        $keyEdrpou = $keyOwner['edrpou']['value'] ?? '';
        if ($edrpou && $keyEdrpou !== '' && $keyEdrpou !== $edrpou) {
            throw new CipherValidationException(__('validation.custom.cipher.edrpouDiffer'), $keyInfo);
        }
    }

    /**
     * Delete the Cipher session together with the loaded data and key.
     * A failure here is only logged, so it does not hide the signature or the original error.
     *
     * @param  CipherRequest  $cipher
     * @param  string  $ticketUuid
     * @return void
     */
    protected function deleteSession(CipherRequest $cipher, string $ticketUuid): void
    {
        try {
            $cipher->deleteSession($ticketUuid);
        } catch (CipherException|CipherConnectionException $exception) {
            Log::channel('cipher_errors')->warning('Failed to delete Cipher session', [
                'ticketUuid' => $ticketUuid,
                'message' => $exception->getMessage()
            ]);
        }
    }
}
