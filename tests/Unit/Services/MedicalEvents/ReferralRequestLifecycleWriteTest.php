<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Tests\TestCase;

class ReferralRequestLifecycleWriteTest extends TestCase
{
    public function test_extract_signed_create_entity_unwraps_job_result_data(): void
    {
        $service = app(ReferralRequestLifecycleService::class);
        $method = new \ReflectionMethod(ReferralRequestLifecycleService::class, 'extractSignedCreateEntity');
        $method->setAccessible(true);

        $entity = $method->invoke($service, [
            'status' => 'processed',
            'result' => [
                'data' => [
                    'id' => 'sr-uuid',
                    'status' => 'active',
                    'requisition' => '0000-AAAA-BBBB-CCCC',
                ],
            ],
        ]);

        $this->assertSame('sr-uuid', $entity['id']);
        $this->assertSame('active', $entity['status']);
        $this->assertSame('0000-AAAA-BBBB-CCCC', $entity['requisition']);
    }
}
