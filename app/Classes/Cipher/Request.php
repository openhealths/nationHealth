<?php

declare(strict_types=1);

namespace App\Classes\Cipher;

use App\Classes\Cipher\Errors\ErrorHandler;
use App\Classes\Cipher\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

class Request
{
    private string $method;

    private string $url;

    private string $params;

    public function __construct(
        string $method,
        string $url,
        string $params,
    ) {
        $this->method = $method;
        $this->url = $url;
        $this->params = $params;
    }

    /**
     * @throws ApiException
     */
    public function sendRequest()
    {
        $apiBase = config('cipher.api.domain');

        $url = $apiBase . $this->url;

        \Illuminate\Support\Facades\Log::debug("Cipher API Request: {$this->method} {$url}", [
            'body_length' => strlen($this->params)
        ]);

        $response = Http::acceptJson()
            ->withBody($this->params)
            ->{$this->method}($url);

        if ($response->successful()) {
            $success = json_decode($response->body(), true);
            $success['status'] = $response->status();

            return $success ?? [];
        }

        if ($response->failed()) {
            $responseBody = $response->body();
            \Illuminate\Support\Facades\Log::error("Cipher API Request Failed: {$this->method} {$url}", [
                'status' => $response->status(),
                'response' => $responseBody,
                'request_body' => $this->params
            ]);
            $error = json_decode($responseBody, true) ?? [];
            $error = ErrorHandler::handleError($error);
            throw new ApiException($error);
        }

        \Illuminate\Support\Facades\Log::error("Cipher API Unexpected Response: {$this->method} {$url}", [
            'status' => $response->status(),
            'response' => $response->body()
        ]);
        throw new ApiException(['code' => $response->status()], 'Unexpected response');
    }

}
