<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dictionary;

use App\Services\Dictionary\Collections\ServiceCollection;
use PHPUnit\Framework\TestCase;

class ServiceCollectionTest extends TestCase
{
    public function test_flattening_preserves_first_occurrence_order_and_keys(): void
    {
        $catalog = new ServiceCollection([
            $this->service('group', 'Group') + [
                'groups' => [$this->service('nested', 'Nested') + [
                    'services' => [$this->service('duplicate', 'First')],
                ]],
                'services' => [$this->service('duplicate', 'Second'), $this->service('last', 'Last')],
            ],
        ]);

        $result = $catalog->flattened();

        $this->assertInstanceOf(ServiceCollection::class, $result);
        $this->assertSame([0, 1, 2, 4], $result->keys()->all());
        $this->assertSame(['group', 'nested', 'duplicate', 'last'], $result->pluck('id')->all());
        $this->assertSame('First', $result->firstWhere('id', 'duplicate')['name']);
        $this->assertSame('laboratory_procedure', $result->firstWhere('id', 'last')['category']);
    }

    public function test_flattening_preserves_strict_id_comparison(): void
    {
        $catalog = new ServiceCollection([
            $this->service(1, 'Integer'), $this->service('1', 'String'),
            $this->service(1, 'Duplicate integer'), $this->service('1', 'Duplicate string'),
        ]);

        $this->assertSame([1, '1'], $catalog->flattened()->pluck('id')->all());
        $this->assertSame(['Integer', 'String'], $catalog->flattened()->pluck('name')->all());
    }

    public function test_inactive_nodes_and_their_children_are_excluded(): void
    {
        $catalog = new ServiceCollection([
            $this->service('inactive', 'Inactive') + [
                'is_active' => false,
                'services' => [$this->service('hidden-child', 'Hidden')],
            ],
            $this->service('active', 'Active') + [
                'services' => [$this->service('inactive-child', 'Inactive child') + ['is_active' => false]],
            ],
        ]);

        $this->assertSame(['active'], $catalog->flattened()->pluck('id')->all());
        $this->assertSame([], (new ServiceCollection())->flattened()->all());
    }

    public function test_large_catalog_keeps_all_distinct_services(): void
    {
        $items = [];
        for ($index = 0; $index < 10000; $index++) {
            $items[] = $this->service('service-'.$index, 'Service '.$index);
        }

        $result = (new ServiceCollection([...$items, ...$items]))->flattened();

        $this->assertCount(10000, $result);
        $this->assertSame('service-0', $result->first()['id']);
        $this->assertSame('service-9999', $result->last()['id']);
    }

    private function service(string|int $id, string $name): array
    {
        return ['id' => $id, 'name' => $name, 'code' => (string) $id, 'category' => 'laboratory_procedure'];
    }
}
