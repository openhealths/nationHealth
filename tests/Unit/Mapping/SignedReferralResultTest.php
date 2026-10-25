<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\ServiceRequest\SignedReferralResult;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class SignedReferralResultTest extends TestCase
{
    public static function envelopes(): iterable
    {
        $entity = ['id' => 'request-id', 'status' => 'ACTIVE', 'requisition' => 'SR-1'];
        foreach ([$entity, ['result' => $entity], ['result' => [$entity]], ['result' => ['data' => $entity]], ['result' => ['data' => [$entity]]], ['data' => $entity], ['data' => [$entity]]] as $index => $response) {
            yield 'entity envelope '.$index => [$response, ['uuid' => 'request-id', 'status' => 'ACTIVE', 'request_number' => 'SR-1']];
        }
        foreach (['processed', 'completed', 'success', 'pending', 'processing', 'accepted', 'queued', ''] as $status) {
            yield 'job status '.$status => [['status' => $status], ['status' => 'active']];
        }
        yield 'clinical status retained' => [['id' => 'request-id', 'status' => 'recalled'], ['uuid' => 'request-id', 'status' => 'recalled']];
    }

    #[DataProvider('envelopes')]
    public function test_signed_creation_metadata_preserves_entity_unwrapping_and_status_policy(array $response, array $expected): void
    {
        $this->assertSame($expected, app(ObjectMapperInterface::class)->map(SignedReferralResult::entity($response), SignedReferralResult::class)->toPatch());
    }
}
