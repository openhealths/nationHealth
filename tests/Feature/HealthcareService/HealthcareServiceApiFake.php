<?php

declare(strict_types=1);

namespace Tests\Feature\HealthcareService;

use App\Classes\eHealth\Api\HealthcareService as HealthcareServiceApi;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

/**
 * Fake of the eHealth healthcare service client. The container gives a new client on every resolve, as in the
 * application; each one answers the prepared way, without network access, and records its requests here. Only the
 * transport is replaced: the client still builds the request, validates the response and reports errors as it does
 * in the application.
 */
final class HealthcareServiceApiFake
{
    /**
     * Requests the clients received, in the order they were sent.
     *
     * @var Request[]
     */
    public array $receivedRequests = [];

    /**
     * Put a client answering with the given response into the container, where the application takes it from.
     *
     * @param  int  $status
     * @param  array  $body
     * @return self
     */
    public static function respond(int $status, array $body): self
    {
        return self::bind(static fn (): PromiseInterface => Factory::response($body, $status));
    }

    /**
     * Put a client that can't connect to eHealth into the container, where the application takes it from.
     *
     * @return self
     */
    public static function unreachable(): self
    {
        return self::bind(Factory::failedConnection());
    }

    /**
     * Make the container build a client answering every request with the given answer.
     *
     * @param  Closure  $answer
     * @return self
     */
    private static function bind(Closure $answer): self
    {
        $fake = new self();

        app()->bind(
            HealthcareServiceApi::class,
            static fn (): HealthcareServiceApi => new HealthcareServiceApi(app(Factory::class))
                ->stub([
                    static function (Request $request) use ($fake, $answer): PromiseInterface {
                        $fake->receivedRequests[] = $request;

                        return $answer($request);
                    }
                ])
                ->preventStrayRequests()
        );

        return $fake;
    }
}
