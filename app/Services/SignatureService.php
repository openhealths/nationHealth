<?php

declare(strict_types=1);

namespace App\Services;

use App\Classes\Cipher\Api\CipherApi;
use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\Cipher\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SignatureService
{
    protected CipherApi $cipherApi;

    public function __construct(CipherApi $cipherApi)
    {
        $this->cipherApi = $cipherApi;
    }

    /**
     * Sends data for signing using Cipher API.
     * The file processing logic is now handled inside this service.
     */
    public function signData(
        array $dataToSign,
        string $password,
        string $knedp,
        ?UploadedFile $keyFile,
        string $taxId
    ): string|array {
        Log::debug('SignatureService: signData starting', [
            'knedp' => $knedp,
            'taxId' => $taxId,
            'keyFileName' => $keyFile?->getClientOriginalName(),
            'keyFileSize' => $keyFile?->getSize(),
            'passwordLength' => strlen($password),
        ]);

        try {
            $base64FileContent = $this->getBase64KepFileContent($keyFile);

            $signedContent = $this->cipherApi->sendSession(
                json_encode($dataToSign, JSON_THROW_ON_ERROR),
                $password,
                $base64FileContent,
                $knedp,
                $taxId
            );

            if (is_array($signedContent)) {
                Log::error('SignatureService: signing failed, returned errors', [
                    'errors' => $signedContent
                ]);
                $errorMessage = collect($signedContent)->flatten()->first() ?? __('forms.invalid_kep_password');
                throw new RuntimeException((string) $errorMessage);
            }

            if (empty($signedContent) || !is_string($signedContent)) {
                Log::error('SignatureService: signing returned empty/invalid response', [
                    'response_type' => gettype($signedContent)
                ]);
                throw new RuntimeException(__('employees.errors.signature_failed_unexpected'));
            }

            Log::debug('SignatureService: signData completed successfully');

            return $signedContent;

        } catch (ApiException $e) {
            Log::error('SignatureService: ApiException caught', [
                'message' => $e->getMessage(),
                'errors' => $e->getErrors()
            ]);
            $errors = $e->getErrors();
            $errorMessage = collect($errors)->flatten()->first() ?? __('forms.invalid_kep_password');

            throw new RuntimeException((string) $errorMessage);
        } catch (\Exception $e) {
            Log::error('SignatureService: unexpected error occurred: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            throw new RuntimeException($e->getMessage());
        }
    }

    /**
     * ADDED: Processes the uploaded KEP file and returns its base64 content.
     * This logic was moved from the Form Object.
     */
    private function getBase64KepFileContent(?UploadedFile $keyFile): string
    {
        if (!$keyFile || !$keyFile->exists()) {
            throw new \RuntimeException(__('Please upload a KEP file.'));
        }

        $fileContents = file_get_contents($keyFile->getRealPath());

        if ($fileContents === false) {
            throw new \RuntimeException(__('Could not read KEP file content.'));
        }

        return base64_encode($fileContents);
    }

    /**
     * Retrieves supported certificate authorities from Cipher API, cached for 7 days.
     *
     * @return array An array of certificate authorities.
     */
    public function getCertificateAuthorities(): array
    {
        return Cache::remember('knedp_certificate_authority', now()->addDays(7), function () {
            try {
                return new CipherRequest()->getCertificateAuthority()->response['ca'];
            } catch (ApiException $e) {
                Log::error("Error fetching certificate authorities from Cipher API: " . $e->getMessage(), ['errors' => $e->getErrors()]);

                return [];
            } catch (\Exception $e) {
                Log::error("General error fetching certificate authorities: " . $e->getMessage(), ['exception' => $e]);

                return [];
            }
        });
    }
}
