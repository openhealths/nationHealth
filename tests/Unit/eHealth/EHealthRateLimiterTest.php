<?php

declare(strict_types=1);

namespace Tests\Unit\EHealth;

use App\Classes\eHealth\EHealthRateLimiter;
use App\Exceptions\EHealth\EHealthRateLimitedException;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Mockery;
use RuntimeException;
use Throwable;

class EHealthRateLimiterTest extends TestCase
{
    public function test_framework_counters_and_rolling_requests_have_separate_storage(): void
    {
        $limiter = new EHealthRateLimiter(Cache::store());
        $limiter->hit('synthetic-group');
        $limiter->reserve('synthetic-group', 2, 10);
        $limiter->reserve('synthetic-group', 2, 10);
        $this->assertSame(1, $limiter->attempts('synthetic-group'));
        $this->expectException(EHealthRateLimitedException::class);
        $limiter->reserve('synthetic-group', 2, 10);
    }

    public function test_longer_cooldown_extends_the_framework_timer(): void
    {
        $this->travelTo(now()->startOfSecond());
        $limiter = new EHealthRateLimiter(Cache::store());
        $limiter->cooldown('synthetic-group', 20);
        $this->travel(10)->seconds();
        $limiter->cooldown('synthetic-group', 600);
        // Use a separate instance to prove that the extended wait is shared through the cache.
        $other = new EHealthRateLimiter(Cache::store());
        try {
            $other->reserve('synthetic-group', 2, 60);
            $this->fail('The longer server wait must be shared.');
        } catch (EHealthRateLimitedException $exception) {
            $this->assertSame(600, $exception->retryAfter());
        }
    }

    public function test_a_failed_cache_write_does_not_admit_a_request(): void
    {
        $cache = Mockery::mock(Repository::class)->makePartial();
        $cache->shouldReceive('lock')->once()->andReturn(Cache::lock('synthetic-write-failure', 5));
        $cache->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default);
        $cache->shouldReceive('put')->once()->andReturn(false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not record the eHealth request count');
        (new EHealthRateLimiter($cache))->reserve('synthetic-group', 2, 60);
    }

    public function test_concurrent_processes_cannot_exceed_the_shared_limit(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('The concurrency check requires pcntl.');
        }

        $directory = sys_get_temp_dir().'/ehealth-rate-limit-'.bin2hex(random_bytes(8));
        $files = new Filesystem();
        $files->makeDirectory($directory, 0755, true);
        try {
            for ($round = 0; $round < 3; $round++) {
                $children = [];
                $startAt = microtime(true) + 0.2;
                for ($index = 0; $index < 6; $index++) {
                    $pid = pcntl_fork();
                    if ($pid === -1) {
                        $this->fail('Could not fork the concurrency check.');
                    }
                    if ($pid === 0) {
                        while (microtime(true) < $startAt) {
                            usleep(1000);
                        }
                        try {
                            $limiter = new EHealthRateLimiter(new Repository(new FileStore(new Filesystem(), $directory)));
                            $limiter->reserve('concurrent-'.$round, 2, 60);
                            exit(0);
                        } catch (EHealthRateLimitedException) {
                            exit(2);
                        } catch (Throwable $exception) {
                            file_put_contents($directory.'/error-'.$index, $exception->getMessage());
                            exit(3);
                        }
                    }
                    $children[] = $pid;
                }
                $accepted = 0;
                $deferred = 0;
                foreach ($children as $pid) {
                    pcntl_waitpid($pid, $status);
                    $this->assertTrue(pcntl_wifexited($status));
                    $result = pcntl_wexitstatus($status);
                    $this->assertContains($result, [0, 2], 'A child failed instead of applying the quota.');
                    $accepted += (int) ($result === 0);
                    $deferred += (int) ($result === 2);
                }
                $this->assertSame(2, $accepted);
                $this->assertSame(4, $deferred);
            }
        } finally {
            $files->deleteDirectory($directory);
        }
    }
}
