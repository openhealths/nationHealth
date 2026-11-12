<?php

declare(strict_types=1);

namespace App\Classes\Cipher\Api;

use App\Classes\Cipher\CipherResponse;
use App\Classes\Cipher\CipherSignatureProvider;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherResponseException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * HTTP client of the Cipher API: one method per endpoint, no signing flow or business rules.
 *
 * @see CipherSignatureProvider for the signing flow
 */
class CipherRequest extends PendingRequest
{
    /**
     * The HTTP request timeout in seconds.
     * Signing operations can sometimes be slow.
     */
    public const int TIMEOUT = 60;

    public function __construct(?Factory $factory = null)
    {
        parent::__construct($factory);

        $this->baseUrl(config('cipher.api.domain'))
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->asJson();
    }

    /**
     * Override the send method for returning Cipher response.
     *
     * @param  string  $method
     * @param  string  $url
     * @param  array  $options
     * @return CipherResponse|Response
     * @throws CipherConnectionException|CipherResponseException
     */
    public function send(string $method, string $url, array $options = []): CipherResponse|Response
    {
        try {
            $response = parent::send($method, $url, $options);
        } catch (ConnectionException $exception) {
            throw new CipherConnectionException($exception->getMessage(), $exception->getCode(), $exception);
        }

        $cipherResponse = new CipherResponse($response);

        if ($response->successful()) {
            return $cipherResponse;
        }

        return $cipherResponse->throw();
    }

    /**
     * Create a separate session for a separate resource (file).
     *
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    public function createSession(): CipherResponse|PromiseInterface
    {
        return $this->post('/ticket');
    }

    /**
     * Loading session data, which is data on which EP operations are performed.
     *
     * @param  string  $ticketUuid
     * @param  string  $base64Data
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    public function loadSessionData(string $ticketUuid, string $base64Data): CipherResponse|PromiseInterface
    {
        return $this->post("/ticket/$ticketUuid/data", ['base64Data' => $base64Data]);
    }

    /**
     * The parameters of verification (creation) operations used in the context of a specific session are set.
     *
     * @param  string  $ticketUuid
     * @param  string  $knedpId
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    public function setSessionParameters(string $ticketUuid, string $knedpId): CipherResponse|PromiseInterface
    {
        return $this->put("/ticket/$ticketUuid/option", [
            'caId' => $knedpId,
            'cadesType' => 'CADES_X_LONG',
            'signatureType' => 'attached',
            'embedDataTs' => 'true'
        ]);
    }

    /**
     * Loading the session key container, use a file container as the key session container.
     *
     * @param  string  $ticketUuid
     * @param  string  $base64File
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    public function uploadKeyFile(string $ticketUuid, string $base64File): CipherResponse|PromiseInterface
    {
        return $this->put("/ticket/$ticketUuid/keyStore", ['base64Data' => $base64File]);
    }

    /**
     * Get information about the keys contained in the key container.
     *
     * @param  string  $ticketUuid
     * @param  string  $password
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    public function verifyKeyContainer(string $ticketUuid, string $password): CipherResponse|PromiseInterface
    {
        return $this->put("/ticket/$ticketUuid/keyStore/verifier", ['keyStorePassword' => $password]);
    }

    /**
     * Initiates the process of asynchronous EP creation for previously loaded data and the session key container.
     *
     * @param  string  $ticketUuid
     * @param  string  $password
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    public function initiateSignatureCreation(string $ticketUuid, string $password): CipherResponse|PromiseInterface
    {
        return $this->post("/ticket/$ticketUuid/ds/creator", ['keyStorePassword' => $password]);
    }

    /**
     * Request to receive EP data in Base64 format. EP data represents session EP data.
     *
     * @param  string  $ticketUuid
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    public function getSignedData(string $ticketUuid): CipherResponse|PromiseInterface
    {
        return $this->get("/ticket/$ticketUuid/ds/base64Data");
    }

    /**
     * When a session (receipt) is deleted, all resources associated with it are deleted: data on which operations are performed, CEP (generated or downloaded).
     *
     * @param  string  $ticketUuid
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    public function deleteSession(string $ticketUuid): CipherResponse|PromiseInterface
    {
        return $this->delete("/ticket/$ticketUuid");
    }

    /**
     * Obtain information about KNEDP, which is supported by the Service.
     *
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    public function getCertificateAuthority(): CipherResponse|PromiseInterface
    {
        return $this->get('/certificateAuthority/supported');
    }
}
