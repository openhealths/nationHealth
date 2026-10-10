<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\ServiceRequest\EhealthComplete;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\Support\ReferralExecutionHarness;
use Tests\TestCase;

class ReferralCompletionPayloadTest extends TestCase
{
    public static function resourceTypes(): iterable
    {
        foreach (['encounter', 'procedure', 'diagnostic_report'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('resourceTypes')]
    public function test_completion_payload_preserves_the_legacy_reference_and_excludes_extra_fields(string $type): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $uuid = '12345678-1234-1234-1234-123456789012';
        $source = (object) ['basedOn' => [(object) ['uuid' => $uuid, 'type' => $type]], 'status' => 'draft', 'injected' => 'local-only'];

        $payload = app(ObjectMapperInterface::class)->map($source, EhealthComplete::class)->toArray();

        $this->assertSame(['based_on' => [['identifier' => [
            'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]],
            'value' => $uuid,
        ]]]], $payload);
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public static function invalidResources(): iterable
    {
        yield 'unsupported type' => ['encounter-id', 'observation'];
        yield 'missing UUID' => ['', 'encounter'];
    }

    #[DataProvider('invalidResources')]
    public function test_invalid_completion_is_rejected_before_querying_or_sending(string $uuid, string $type): void
    {
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            (new ReferralExecutionHarness())->completeReferral('referral-id', $uuid, $type);
            $this->fail('Invalid completion should not be submitted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame([], $queries);
            Http::assertNothingSent();
        }
    }
}
