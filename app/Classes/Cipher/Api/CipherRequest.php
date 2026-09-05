<?php

declare(strict_types=1);

namespace App\Classes\Cipher\Api;

use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherEncodingException;
use App\Exceptions\Cipher\CipherResponseException;
use App\Exceptions\Cipher\CipherValidationException;
use Carbon\Carbon;
use Illuminate\Http\Client\Factory;
use App\Classes\Cipher\CipherResponse;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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
     * Used for sign data form forms.
     * Extended docs with steps: https://docs.cipher.com.ua/spaces/CCSUOS/pages/8618379/%D0%92%D0%B8%D0%BA%D0%BE%D0%BD%D0%B0%D0%BD%D0%BD%D1%8F+%D1%82%D0%B8%D0%BF%D0%BE%D0%B2%D0%B8%D1%85+%D0%B7%D0%B0%D0%B4%D0%B0%D1%87#id-%D0%92%D0%B8%D0%BA%D0%BE%D0%BD%D0%B0%D0%BD%D0%BD%D1%8F%D1%82%D0%B8%D0%BF%D0%BE%D0%B2%D0%B8%D1%85%D0%B7%D0%B0%D0%B4%D0%B0%D1%87-%D0%A1%D1%82%D0%B2%D0%BE%D1%80%D0%B5%D0%BD%D0%BD%D1%8F%D0%95%D0%9F.1
     *
     * @param  array  $dataSignature
     * @param  string  $knedp
     * @param  TemporaryUploadedFile  $uploadedFile
     * @param  string  $password
     * @param  string  $taxId
     * @param  string|null  $edrpou
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException|CipherValidationException|CipherEncodingException
     */
    public function signData(
        array $dataSignature,
        string $knedp,
        TemporaryUploadedFile $uploadedFile,
        string $password,
        string $taxId,
        ?string $edrpou = null
    ): CipherResponse|PromiseInterface {
        $ticketUuid = $this->createSession()->getTicketUuid();

        try {
            $encodedSignature = json_encode($dataSignature, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CipherEncodingException(
                'Failed to encode data signature: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }

        $this->loadSessionData($ticketUuid, base64_encode($encodedSignature));
        $this->setSessionParameters($ticketUuid, $knedp);

        $base64File = $this->convertFileToBase64($uploadedFile);
        $this->uploadKeyFile($ticketUuid, $base64File);

        $this->verifyWithFileContainer($ticketUuid, $password, $taxId, $edrpou);

        $this->initiateSignatureCreation($ticketUuid, $password);

        $signedData = $this->getSignedData($ticketUuid);

        $this->deleteSession($ticketUuid);

        return $signedData;
    }

    /**
     * Request to Cipher API to get personal data by provided key.
     *
     * @param  string  $knedp
     * @param  TemporaryUploadedFile  $uploadedFile
     * @param  string  $password
     * @return CipherResponse|PromiseInterface|null
     * @throws CipherConnectionException|CipherResponseException|CipherEncodingException
     */
    public function getPersonalData(
        string $knedp,
        TemporaryUploadedFile $uploadedFile,
        string $password
    ): null|CipherResponse|PromiseInterface {
        $ticketUuid = $this->createSession()->getTicketUuid();

        try {
            $encodedEmpty = json_encode([], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CipherEncodingException(
                'Failed to encode empty payload: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }

        $this->loadSessionData($ticketUuid, base64_encode($encodedEmpty));
        $this->setSessionParameters($ticketUuid, $knedp);

        $base64File = $this->convertFileToBase64($uploadedFile);
        $this->uploadKeyFile($ticketUuid, $base64File);

        return $this->verifyKeyContainer($ticketUuid, $password);
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
    protected function createSession(): CipherResponse|PromiseInterface
    {
        return $this->post('/ticket');
    }

    /**
     * Loading session data, which is data on which EP operations are performed.
     *
     * @param  string  $ticketUuid
     * @param  string  $base64File
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    protected function loadSessionData(string $ticketUuid, string $base64File): CipherResponse|PromiseInterface
    {
        return $this->post("/ticket/$ticketUuid/data", ['base64Data' => $base64File]);
    }

    /**
     * The parameters of verification (creation) operations used in the context of a specific session are set.
     *
     * @param  string  $ticketUuid
     * @param  string  $knedpId
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    protected function setSessionParameters(string $ticketUuid, string $knedpId): CipherResponse|PromiseInterface
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
    protected function uploadKeyFile(string $ticketUuid, string $base64File): CipherResponse|PromiseInterface
    {
        return $this->put("/ticket/$ticketUuid/keyStore", ['base64Data' => $base64File]);
    }

    /**
     * Check if some important data received from the forms are have the same value as in the DS FileContainer
     *
     * @param  $ticketUuid
     * @param  $password
     * @param  string  $taxId
     * @param  string|null  $edrpou
     * @return void
     * @throws CipherConnectionException|CipherResponseException|CipherValidationException
     */
    protected function verifyWithFileContainer($ticketUuid, $password, string $taxId, ?string $edrpou = null): void
    {
        // Get needed data contains into the key
        $response = $this->verifyKeyContainer($ticketUuid, $password)->response;

        // If KEP key is not valid (ex. very old one)
        if (!$response['signature']['canBeUsed']) {
            throw new CipherValidationException(__('validation.custom.cipher.kepNotValid'), $response);
        }

        $keyData = Arr::get($response, 'signature.certificateInfo.extensionsCertificateInfo.value.personalData.value');

        // Get value of 'edrpou' field for key's owner {string|null}
        $inKeyEdrpou = $keyData['edrpou']['value'] ?? '';

        // Get value of 'drfou' (IPN) field for key's owner {string|null}
        $inKeyDrfou = $keyData['drfou']['value'] ?? '';

        // Get last date when validity period is valid
        $endDate = $response['signature']['certificateInfo']['notAfter']['value'];
        $expirationDate = Carbon::parse($endDate);

        if ($expirationDate <= Carbon::now()) {
            throw new CipherValidationException(__('validation.custom.cipher.kepTimeExpired'), $response);
        }

        // Compare the provided taxId with the one in the key
        if ($inKeyDrfou !== $taxId) {
            throw new CipherValidationException(__('validation.custom.cipher.drfouDiffer'), $response);
        }

        /**
         * If EDRPOU is provided, check the key's EDRPOU value. Empty value in the key might be associated with the FOP key,
         * this will be determined later by the service provider response
         */
        if ($edrpou && !empty($inKeyEdrpou) && $inKeyEdrpou !== $edrpou) {
            throw new CipherValidationException(__('validation.custom.cipher.edrpouDiffer'), $response);
        }
    }

    /**
     * Initiates the process of asynchronous EP creation for previously loaded data and the session key container.
     *
     * @param  string  $ticketUuid
     * @param  string  $password
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    protected function initiateSignatureCreation(string $ticketUuid, string $password): CipherResponse|PromiseInterface
    {
        return $this->post("/ticket/$ticketUuid/ds/creator", ['keyStorePassword' => $password]);
    }

    /**
     * Get information about the keys contained in the key container.
     *
     * @param  string  $ticketUuid
     * @param  string  $password
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    protected function verifyKeyContainer(string $ticketUuid, string $password): CipherResponse|PromiseInterface
    {
        return $this->put("/ticket/$ticketUuid/keyStore/verifier", ['keyStorePassword' => $password]);
    }

    /**
     * Request to receive EP data in Base64 format. EP data represents session EP data.
     *
     * @param  string  $ticketUuid
     * @return CipherResponse|PromiseInterface
     * @throws CipherConnectionException|CipherResponseException
     */
    protected function getSignedData(string $ticketUuid): CipherResponse|PromiseInterface
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
    protected function deleteSession(string $ticketUuid): CipherResponse|PromiseInterface
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

    protected function convertFileToBase64(TemporaryUploadedFile $keyContainerUpload): ?string
    {
        $fileExtension = $keyContainerUpload->getClientOriginalExtension();
        $filePath = $keyContainerUpload->storeAs('uploads/kep', 'kep.' . $fileExtension, 'public');

        if ($filePath) {
            $fileContents = file_get_contents(storage_path('app/public/' . $filePath));

            if ($fileContents !== false) {
                $base64Content = base64_encode($fileContents);
                Storage::disk('public')->delete($filePath);

                return $base64Content;
            }
        }

        return null;
    }
}