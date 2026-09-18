<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\DomainLogger;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DomainLoggerTest extends TestCase
{
    public function test_info_and_debug_are_suppressed_when_domain_verbose_is_off(): void
    {
        config(['logging.domain_verbose' => false]);

        Log::shouldReceive('info')->never();
        Log::shouldReceive('debug')->never();

        DomainLogger::info('should not appear', ['k' => 1]);
        DomainLogger::debug('should not appear either');
    }

    public function test_info_and_debug_are_written_when_domain_verbose_is_on(): void
    {
        config(['logging.domain_verbose' => true]);

        Log::shouldReceive('info')
            ->once()
            ->with('verbose breadcrumb', ['count' => 2]);
        Log::shouldReceive('debug')
            ->once()
            ->with('verbose debug', []);

        DomainLogger::info('verbose breadcrumb', ['count' => 2]);
        DomainLogger::debug('verbose debug');
    }

    public function test_warning_and_error_always_pass_through(): void
    {
        config(['logging.domain_verbose' => false]);

        Log::shouldReceive('warning')
            ->once()
            ->with('keep warnings', []);
        Log::shouldReceive('error')
            ->once()
            ->with('keep errors', ['id' => 9]);
        Log::shouldReceive('critical')
            ->once()
            ->with('keep critical', []);

        DomainLogger::warning('keep warnings');
        DomainLogger::error('keep errors', ['id' => 9]);
        DomainLogger::critical('keep critical');
    }

    public function test_logging_config_exposes_volume_control_flags(): void
    {
        $this->assertArrayHasKey('domain_verbose', config('logging'));
        $this->assertArrayHasKey('ehealth_requests', config('logging'));
        $this->assertIsBool(config('logging.domain_verbose'));
        $this->assertIsBool(config('logging.ehealth_requests'));
    }
}
