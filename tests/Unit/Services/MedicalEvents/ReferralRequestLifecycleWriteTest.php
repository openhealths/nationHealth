<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Dto\ServiceRequest\SignedReferralResult;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class ReferralRequestLifecycleWriteTest extends TestCase
{
    public function test_extract_signed_create_entity_unwraps_job_result_data(): void
    {
        $entity = SignedReferralResult::entity([
            'status' => 'processed',
            'result' => [
                'data' => [
                    'id' => 'sr-uuid',
                    'status' => 'active',
                    'requisition' => '0000-AAAA-BBBB-CCCC',
                ],
            ],
        ]);

        $result = app(ObjectMapperInterface::class)->map($entity, SignedReferralResult::class)->toPatch();
        $this->assertSame('sr-uuid', $result['uuid']);
        $this->assertSame('active', $result['status']);
        $this->assertSame('0000-AAAA-BBBB-CCCC', $result['request_number']);
    }
}
