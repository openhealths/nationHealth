<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class Handler extends ExceptionHandler
{
    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        // The frontend's API calls get JSON errors whatever they accept, a redirect or an HTML page would break them
        $this->shouldRenderJsonWhen(static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());

        // Unknown URLs, missing records and policies denying with 404 all answer the API with the same message
        $this->renderable(static function (HttpExceptionInterface $exception, Request $request): ?JsonResponse {
            if ($exception->getStatusCode() !== Response::HTTP_NOT_FOUND || !$request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => __('errors.not_found')], Response::HTTP_NOT_FOUND);
        });

        $this->reportable(function (\Illuminate\Database\QueryException $exception) {
            \App\Exceptions\MedicalEvents\ClinicalStorageException::fromQueryException($exception, 'unhandledQuery');

            return false;
        });
        $this->renderable(function (\Illuminate\Database\QueryException|\App\Exceptions\MedicalEvents\ClinicalStorageException $exception, $request) {
            $message = $exception instanceof \App\Exceptions\MedicalEvents\ClinicalStorageException
                ? $exception->getMessage() : __('clinical-storage.write_failed');

            return $this->shouldReturnJson($request, $exception)
                ? response()->json(['message' => $message], 503)
                : response($message, 503)->header('Content-Type', 'text/plain; charset=UTF-8');
        });
    }
}
