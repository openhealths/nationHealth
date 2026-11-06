<?php

declare(strict_types=1);

namespace Tests\Unit\EHealth;

use App\Classes\eHealth\EHealthRequest;
use App\Classes\eHealth\EHealthRateLimiter;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthRateLimitedException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Mockery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EHealthRequestRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        Cache::flush();
        Http::preventStrayRequests();
        $this->fakeHttp(['*' => Http::response(['data' => []])]);
        config()->set('ehealth.rate_limit.employee.max_requests', 2);
        config()->set('ehealth.rate_limit.remote_job.max_requests', 2);
    }

    public function test_list_and_detail_share_a_rolling_token_budget_across_clients(): void
    {
        $this->client()->get('/api/employees');
        $this->client()->get('/api/employees/employee-a');
        $this->travel(59)->seconds();
        $this->assertLimited(fn () => $this->client()->get('/api/employees/employee-b'), 2);
        Http::assertSentCount(2);

        $this->travel(2)->seconds();
        $this->client()->get('/api/employees/employee-b');
        Http::assertSentCount(3);
    }

    public function test_different_tokens_and_hosts_have_independent_budgets(): void
    {
        $this->client()->get('/api/employees');
        $this->client()->get('/api/employees');
        $this->client('other-token')->get('/api/employees');
        $this->client()->baseUrl('https://other.ehealth.invalid')->get('/api/employees');
        Http::assertSentCount(4);
    }

    public function test_job_fallback_paths_share_capacity_and_each_request_is_counted(): void
    {
        $this->fakeHttp(['*/api/jobs/*' => Http::response([], 404), '*' => Http::response(['data' => ['status' => 'pending']])]);
        try {
            $this->client()->get('/api/jobs/job-a');
            $this->fail('The canonical URL is missing in this fixture.');
        } catch (EHealthResponseException $exception) {
            $this->assertSame(404, $exception->getCode());
        }
        $this->client()->get('https://ehealth.invalid/Jobs/job-a');
        $this->assertLimited(fn () => $this->client()->get('/jobs/job-b'), 61);
        Http::assertSentCount(2);
    }

    public function test_employee_budget_uses_the_configured_limit(): void
    {
        config()->set('ehealth.rate_limit.employee.max_requests', 3);
        for ($index = 0; $index < 3; $index++) {
            $this->client()->get('/api/employees/'.$index);
        }
        $this->assertLimited(fn () => $this->client()->get('/api/employees/extra'), 61);
        Http::assertSentCount(3);
    }

    public function test_nonpositive_budget_still_allows_one_request(): void
    {
        config()->set('ehealth.rate_limit.remote_job.max_requests', 0);
        $this->client()->get('/api/jobs/job-a');
        $this->assertLimited(fn () => $this->client()->get('/jobs/job-b'), 61);
        Http::assertSentCount(1);
    }

    public function test_server_cooldown_is_shared_by_other_clients_without_another_http_call(): void
    {
        $this->fakeHttp(['*' => Http::response(['error' => ['message' => 'API rate limit exceeded']], 429, ['Retry-After' => '90'])]);
        $this->assertLimited(fn () => $this->client()->get('/api/employees'), 90);
        $this->travel(61)->seconds();
        $this->assertLimited(fn () => $this->client()->get('/api/employees/employee-a'), 29);
        Http::assertSentCount(1);

        $this->travel(30)->seconds();
        $this->fakeHttp(['*' => Http::response(['data' => []])]);
        $this->client()->get('/api/employees');
        Http::assertSentCount(1);
    }

    public function test_lock_contention_defers_without_sending_or_using_capacity(): void
    {
        $key = 'ehealth-requests:ehealth.invalid:employee:'.hash('sha256', 'synthetic-token');
        $cache = Mockery::mock(\Illuminate\Cache\Repository::class)->makePartial();
        $lock = Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $lock->shouldReceive('block')->once()->with(1, Mockery::type('callable'))->andThrow(new LockTimeoutException());
        $cache->shouldReceive('lock')->once()->with($key.':lock', 5)->andReturn($lock);
        $limitedClient = $this->client(rateLimiter: new EHealthRateLimiter($cache));
        $this->assertLimited(fn () => $limitedClient->get('/api/employees'), 1);
        Http::assertNothingSent();
        $this->client()->get('/api/employees');
        Http::assertSentCount(1);
    }

    public function test_late_429_does_not_shorten_an_existing_longer_cooldown(): void
    {
        $sent = 0;
        $this->fakeHttp(['*' => function () use (&$sent) {
            if (++$sent === 1) {
                $this->assertLimited(fn () => $this->client()->get('/api/employees/employee-a'), 90);

                return Http::response([], 429, ['Retry-After' => '20']);
            }

            return Http::response([], 429, ['Retry-After' => '90']);
        }]);
        $this->assertLimited(fn () => $this->client()->get('/api/employees'), 20);
        $this->travel(21)->seconds();
        $this->assertLimited(fn () => $this->client()->get('/api/employees/employee-b'), 69);
        Http::assertSentCount(2);
    }

    public function test_unrelated_reads_and_mutations_are_unchanged(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $this->client()->get('/api/employee_requests');
            $this->client()->get('/api/employees/employee-a/roles');
            $this->client()->get('/api/jobs/job-a/result');
            $this->client()->patch('/api/employees/employee-a/actions/deactivate');
        }
        Http::assertSentCount(12);
    }

    public function test_another_group_can_define_its_own_method_and_window(): void
    {
        config()->set('ehealth.rate_limit.custom', [
            'methods' => ['POST'],
            'paths' => ['~^/api/custom$~'],
            'max_requests' => 1,
            'window_seconds' => 10,
        ]);
        $this->client()->post('/api/custom');
        $this->assertLimited(fn () => $this->client()->post('/api/custom'), 11);
        $this->travel(11)->seconds();
        $this->client()->post('/api/custom');
        $this->client()->get('/api/custom');
        Http::assertSentCount(3);
    }

    public function test_resolved_base_paths_and_url_templates_use_the_same_quota(): void
    {
        $this->client()->baseUrl('https://ehealth.invalid/api')->get('employees');
        $this->client()->withUrlParameters(['employee' => 'employee-a'])->get('/api/employees/{employee}');
        $this->assertLimited(fn () => $this->client()->get('/api/employees'), 61);
        Http::assertSentCount(2);
    }

    public function test_bearer_scheme_casing_and_spacing_do_not_split_a_token_quota(): void
    {
        $this->client()->get('/api/employees');
        $this->client()->withToken('synthetic-token', 'bearer')->get('/api/employees');
        $this->assertLimited(fn () => $this->client()->withToken('synthetic-token', 'BEARER  ')->get('/api/employees'), 61);
        Http::assertSentCount(2);
    }

    public function test_ambiguous_authorization_headers_are_rejected_before_sending(): void
    {
        // Laravel merges differently cased names into two values; the server's token choice would be ambiguous.
        try {
            $this->client()->send('GET', '/api/employees', ['headers' => ['authorization' => 'Bearer other-token']]);
            $this->fail('Ambiguous credentials must not bypass token accounting.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('exactly one Authorization', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_before_sending_header_changes_are_counted_for_the_final_token(): void
    {
        $client = $this->client()->beforeSending(fn ($request) => $request->toPsrRequest()->withHeader('authorization', 'Bearer changed-token'));
        $client->get('/api/employees');
        $client->get('/api/employees');
        $this->assertLimited(fn () => $this->client('changed-token')->get('/api/employees'), 61);
        $this->client()->get('/api/employees');
        Http::assertSentCount(3);
    }

    public function test_each_laravel_http_retry_is_counted(): void
    {
        $this->fakeHttp(['*' => Http::sequence()->push([], 500)->push(['data' => []])]);
        $this->client()->retry(2, 0)->get('/api/employees');
        $this->assertLimited(fn () => $this->client()->get('/api/employees'), 61);
        Http::assertSentCount(2);
    }

    public function test_retry_cannot_send_again_during_a_server_cooldown(): void
    {
        $this->fakeHttp(['*' => Http::response([], 429, ['Retry-After' => '90'])]);
        $this->assertLimited(fn () => $this->client()->retry(2, 0)->get('/api/employees'), 90);
        Http::assertSentCount(1);
    }

    public function test_a_retry_uses_the_token_selected_by_its_retry_callback(): void
    {
        $this->client()->get('/api/employees');
        $this->fakeHttp(['*' => Http::sequence()->push([], 500)->push(['data' => []])->push(['data' => []])]);
        $this->client()->retry(2, 0, function ($exception, $request): bool {
            $request->withToken('other-token');

            return true;
        })->get('/api/employees');
        $this->assertLimited(fn () => $this->client()->get('/api/employees'), 61);
        $this->client('other-token')->get('/api/employees');
        $this->assertLimited(fn () => $this->client('other-token')->get('/api/employees'), 61);
        Http::assertSentCount(3);
    }

    public function test_unhandled_retry_response_errors_keep_the_ehealth_exception_contract(): void
    {
        $this->fakeHttp(['*' => Http::response([], 500)]);
        try {
            $this->client()->retry(2, 0)->get('/api/employees');
            $this->fail('Two failed attempts must raise an eHealth response error.');
        } catch (EHealthResponseException $exception) {
            $this->assertSame(500, $exception->response->status());
        }
        Http::assertSentCount(2);
    }

    public function test_cooldown_lock_timeout_still_blocks_laravel_http_retry(): void
    {
        $cache = Mockery::mock(\Illuminate\Cache\Repository::class)->makePartial();
        $cache->shouldReceive('lock')->once()->andReturn(Cache::lock('synthetic-admission-retry', 5))->ordered();
        $cache->shouldReceive('get')->withAnyArgs()->andReturnUsing(fn ($key, $default = null) => Cache::get($key, $default));
        $cache->shouldReceive('put')->withAnyArgs()->andReturnUsing(fn (...$args) => Cache::put(...$args));
        $blockedLock = Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $blockedLock->shouldReceive('block')->once()->andThrow(new LockTimeoutException());
        $cache->shouldReceive('lock')->once()->andReturn($blockedLock)->ordered();
        $this->fakeHttp(['*' => Http::response([], 429, ['Retry-After' => '90'])]);
        $client = $this->client(rateLimiter: new EHealthRateLimiter($cache));
        $this->assertLimited(fn () => $client->retry(2, 0)->get('/api/employees'), 90);
        Http::assertSentCount(1);
    }

    public function test_cooldown_lock_timeout_preserves_the_real_429_response(): void
    {
        $cache = Mockery::mock(\Illuminate\Cache\Repository::class)->makePartial();
        $cache->shouldReceive('lock')->once()->andReturn(Cache::lock('synthetic-admission', 5))->ordered();
        $cache->shouldReceive('get')->withAnyArgs()->andReturnUsing(fn ($key, $default = null) => Cache::get($key, $default));
        $cache->shouldReceive('put')->withAnyArgs()->andReturnUsing(fn (...$args) => Cache::put(...$args));
        $blockedLock = Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $blockedLock->shouldReceive('block')->once()->andThrow(new LockTimeoutException());
        $cache->shouldReceive('lock')->once()->andReturn($blockedLock)->ordered();
        $this->fakeHttp(['*' => Http::response(['error' => ['message' => 'Throttled']], 429, ['Retry-After' => '90'])]);
        try {
            $this->client(rateLimiter: new EHealthRateLimiter($cache))->get('/api/employees');
            $this->fail('The original server response must be preserved.');
        } catch (EHealthResponseException $exception) {
            $this->assertSame(429, $exception->response->status());
            $this->assertSame(90, $exception->retryAfter());
            $this->assertSame('Throttled', $exception->response->json('error.message'));
        }
        Http::assertSentCount(1);
    }

    public static function retryHeaders(): array
    {
        return ['seconds' => ['75', 75], 'missing' => ['', 60], 'invalid' => ['invalid', 60], 'zero' => ['0', 1]];
    }

    #[DataProvider('retryHeaders')]
    public function test_retry_after_values(string $header, int $expected): void
    {
        $response = new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => $header]));
        $this->assertSame($expected, (new EHealthResponseException($response))->retryAfter());
    }

    public function test_http_date_retry_after(): void
    {
        $response = new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => now()->addSeconds(90)->toRfc7231String()]));
        $this->assertSame(90, (new EHealthResponseException($response))->retryAfter());
    }

    public function test_local_limit_has_no_server_response_and_uses_the_existing_message(): void
    {
        $this->client()->get('/api/employees');
        $this->client()->get('/api/employees');
        try {
            $this->client()->get('/api/employees');
            $this->fail('Local capacity should be exhausted.');
        } catch (EHealthRateLimitedException $exception) {
            $this->assertSame(61, $exception->retryAfter());
            $exception->handle('Local capacity wait');
            $this->assertSame(__('errors.ehealth.messages.too_many_requests'), session('error'));
        }
        Http::assertSentCount(2);
    }

    private function client(string $token = 'synthetic-token', ?EHealthRateLimiter $rateLimiter = null): RequestRateLimitClient
    {
        $factory = Http::getFacadeRoot();
        $client = new RequestRateLimitClient($factory, rateLimiter: $rateLimiter);
        $client->stub((function () {
            return $this->stubCallbacks;
        })->call($factory));
        $client->preventStrayRequests();

        return $client->baseUrl('https://ehealth.invalid')->withToken($token);
    }

    private function fakeHttp(array $responses): void
    {
        (function (): void {
            $this->stubCallbacks = collect();
        })->call(Http::getFacadeRoot());
        Http::fake($responses);
    }

    private function assertLimited(callable $send, int $delay): void
    {
        try {
            $send();
            $this->fail('The request should wait for shared capacity.');
        } catch (EHealthResponseException|EHealthRateLimitedException $exception) {
            $this->assertSame(429, $exception->getCode());
            $this->assertSame($delay, $exception->retryAfter());
        }
    }
}

class RequestRateLimitClient extends EHealthRequest
{
}
