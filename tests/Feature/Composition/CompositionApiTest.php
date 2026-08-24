<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Classes\eHealth\Api\Patient\Composition;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Locks the Composition endpoints to the SwaggerHub contract `ehealthua/compositions`
 * 2.39.2.
 *
 * Every path here was wrong in the original implementation, which is the whole reason
 * these assertions are spelled out one endpoint at a time rather than folded into a
 * loop: a regression in any single path silently breaks one user-facing action, and the
 * test name should say which one.
 */
class CompositionApiTest extends TestCase
{
    private const string PATIENT_ID = '7075e0e2-6b57-47fd-aff7-324806efa7e5';

    private const string COMPOSITION_ID = '89678f60-4cdc-4fe3-ae83-e8b3ebd35c59';

    private const string EPISODE_ID = 'c7c41d7e-f0e5-4118-b5be-fedfb5a1e8ed';

    private const string ENCOUNTER_ID = 'e39ee5ae-2644-4f04-8e64-bb359866e907';

    public function test_create_posts_to_the_collection_endpoint_without_a_patient_in_the_path(): void
    {
        $this->fakeSuccess();

        $this->makeApi()->create(['data' => 'base64-signed-payload']);

        $this->assertRequest('POST', '/api/patients/composition');
    }

    public function test_create_sends_the_signed_payload_under_the_data_key(): void
    {
        $this->fakeSuccess();

        $this->makeApi()->create(['data' => 'base64-signed-payload']);

        Http::assertSent(static function (Request $request): bool {
            return $request->data() === ['data' => 'base64-signed-payload'];
        });
    }

    public function test_async_job_status_is_read_from_the_composition_job_endpoint(): void
    {
        $this->fakeSuccess();

        $this->makeApi()->getAsyncJobStatus(self::COMPOSITION_ID);

        $this->assertRequest('GET', '/api/patients/composition/job/' . self::COMPOSITION_ID);
    }

    public function test_get_by_id_addresses_the_full_episode_and_encounter_context(): void
    {
        $this->fakeSuccess();

        $this->makeApi()->getById(
            self::PATIENT_ID,
            self::COMPOSITION_ID,
            self::EPISODE_ID,
            self::ENCOUNTER_ID
        );

        $this->assertRequest('GET', $this->contextPath());
    }

    public function test_search_uses_the_dedicated_search_endpoint_rather_than_a_patient_collection(): void
    {
        $this->fakeSuccess([]);

        $this->makeApi()->search(['subject' => self::PATIENT_ID, 'type' => 'TEMP_DISABILITY']);

        $this->assertRequest('GET', '/api/patients/searchComposition');

        Http::assertSent(static function (Request $request): bool {
            return $request['subject'] === self::PATIENT_ID
                && $request['type'] === 'TEMP_DISABILITY';
        });
    }

    public function test_sign_patches_the_sign_action(): void
    {
        $this->fakeSuccess();

        $this->makeApi()->sign(self::COMPOSITION_ID, ['data' => 'signed']);

        $this->assertRequest('PATCH', '/api/patients/composition/' . self::COMPOSITION_ID . '/sign');
    }

    public function test_cancel_patches_the_cancel_action(): void
    {
        $this->fakeSuccess();

        $this->makeApi()->cancel(self::COMPOSITION_ID, ['data' => 'signed']);

        $this->assertRequest('PATCH', '/api/patients/composition/' . self::COMPOSITION_ID . '/cancel');
    }

    public function test_print_form_is_read_from_the_context_path(): void
    {
        $this->fakeSuccess();

        $this->makeApi()->getPrintForm(
            self::PATIENT_ID,
            self::COMPOSITION_ID,
            self::EPISODE_ID,
            self::ENCOUNTER_ID
        );

        $this->assertRequest('GET', $this->contextPath() . '/printForm');
    }

    public function test_print_form_forwards_an_explicit_template_only_when_given(): void
    {
        $this->fakeSuccess();
        $api = $this->makeApi();

        $api->getPrintForm(self::PATIENT_ID, self::COMPOSITION_ID, self::EPISODE_ID, self::ENCOUNTER_ID);

        Http::assertSent(static function (Request $request): bool {
            return !str_contains($request->url(), 'templateId');
        });

        $api->getPrintForm(self::PATIENT_ID, self::COMPOSITION_ID, self::EPISODE_ID, self::ENCOUNTER_ID, 'TPL-1');

        Http::assertSent(static function (Request $request): bool {
            return str_contains($request->url(), 'templateId=TPL-1');
        });
    }

    public function test_integration_data_is_read_from_the_context_path(): void
    {
        $this->fakeSuccess();

        $this->makeApi()->getIntegrationData(
            self::PATIENT_ID,
            self::COMPOSITION_ID,
            self::EPISODE_ID,
            self::ENCOUNTER_ID
        );

        $this->assertRequest('GET', $this->contextPath() . '/integrationData');
    }

    public function test_erln_resend_patches_the_erln_endpoint(): void
    {
        $this->fakeSuccess();

        $this->makeApi()->resendErln(self::COMPOSITION_ID);

        $this->assertRequest('PATCH', '/api/patients/composition/' . self::COMPOSITION_ID . '/erln');
    }

    /**
     * The signed conclusion is a large base64 blob carrying personal data, so it must
     * never reach the log verbatim.
     */
    public function test_signed_payload_is_redacted_from_request_logs(): void
    {
        $this->fakeSuccess();
        Log::spy();

        $signed = str_repeat('A', 600);

        $this->makeApi()->create(['data' => $signed]);

        Log::shouldHaveReceived('debug')
            ->withArgs(function (string $message, array $context) use ($signed): bool {
                $encoded = json_encode($context);

                return !str_contains($encoded, $signed)
                    && str_contains($encoded, 'base64_signed_content_redacted');
            })
            ->once();
    }

    private function contextPath(): string
    {
        return '/api/patients/' . self::PATIENT_ID
            . '/composition/' . self::COMPOSITION_ID
            . '/episode/' . self::EPISODE_ID
            . '/encounter/' . self::ENCOUNTER_ID;
    }

    private function assertRequest(string $method, string $path): void
    {
        Http::assertSent(static function (Request $request) use ($method, $path): bool {
            return $request->method() === $method
                && (parse_url($request->url(), PHP_URL_PATH) ?? '') === $path;
        });
    }

    private function fakeSuccess(mixed $data = ['status' => 'PENDING']): void
    {
        Http::fake(['*' => Http::response(['data' => $data, 'meta' => []], 200)]);
    }

    private function makeApi(): Composition
    {
        $factory = Http::getFacadeRoot();
        $api = new Composition($factory);

        // Transfer the factory's protected $stubCallbacks to the PendingRequest instance.
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs);

        return $api;
    }
}
