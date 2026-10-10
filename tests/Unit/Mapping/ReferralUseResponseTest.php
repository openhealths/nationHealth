<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Classes\eHealth\Api\Responses\Collections\ServiceRequestUse;
use App\Dto\ServiceRequest\Model as ServiceRequestModelData;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class ReferralUseResponseTest extends TestCase
{
    public static function responses(): iterable
    {
        yield 'minimal action defaults' => [[], [
            'request_number' => null, 'quantity' => 1, 'program_id' => null,
            'intent' => 'order', 'category' => null, 'service_id' => '',
        ]];
        yield 'full action and falsey values' => [[
            'requisition' => 'SR-1', 'quantity' => ['value' => 0], 'program' => ['id' => 'program'],
            'intent' => '', 'category' => ['coding' => [['code' => 'procedure']]], 'code' => ['coding' => [['code' => 'service']]],
            'note' => 'Do not import', 'employee_id' => 'foreign',
        ], [
            'request_number' => 'SR-1', 'quantity' => 0, 'program_id' => 'program',
            'intent' => '', 'category' => 'procedure', 'service_id' => 'service',
        ]];
        yield 'primary reference wins' => [[
            'code' => ['identifier' => ['value' => 'primary'], 'coding' => [['code' => 'alias']]],
            'program' => ['identifier' => ['value' => 'primary-program'], 'id' => 'alias-program'],
        ], [
            'request_number' => null, 'quantity' => 1, 'program_id' => 'primary-program',
            'intent' => 'order', 'category' => null, 'service_id' => 'primary',
        ]];
    }

    #[DataProvider('responses')]
    public function test_use_import_has_its_own_defaults_without_changing_get_sync(array $input, array $expected): void
    {
        $mapper = app(ObjectMapperInterface::class);
        $this->assertSame($expected, $mapper->map(new ServiceRequestUse($input), ServiceRequestModelData::class)->toUseRecord());
        $this->assertSame([], $mapper->map((object) [], ServiceRequestModelData::class)->toSyncPatch());
    }
}
