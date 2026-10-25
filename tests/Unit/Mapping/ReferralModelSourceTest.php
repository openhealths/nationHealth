<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\DeviceRequest\Model as DeviceRequestModelData;
use App\Dto\ServiceRequest\Model as ServiceRequestModelData;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class ReferralModelSourceTest extends TestCase
{
    public static function models(): iterable
    {
        yield 'service' => [ServiceRequestRequest::class, ServiceRequestModelData::class, 'service_id'];
        yield 'device' => [DeviceRequestRequest::class, DeviceRequestModelData::class, 'device_id'];
    }

    #[DataProvider('models')]
    public function test_preloaded_models_map_directly_without_queries_even_when_nullable_fields_are_absent(string $modelClass, string $targetClass, string $product): void
    {
        $model = new $modelClass(['uuid' => 'request-id', $product => 'product-id', 'quantity' => 0]);
        foreach (['intent', 'category', 'priority'] as $relation) {
            $model->setRelation($relation, null);
        }
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $data = app(ObjectMapperInterface::class)->map($model, $targetClass)->toArray();

        $this->assertSame('request-id', $data['uuid']);
        $this->assertSame('product-id', $data[$product]);
        $this->assertSame(0.0, (float) $data['quantity']);
        $this->assertNull($data['note']);
        $this->assertNull($data['category']);
        $this->assertSame([], $queries);
    }
}
