<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dictionary;

use App\Services\Dictionary\ServiceSearch;
use Tests\TestCase;

class ServiceSearchTest extends TestCase
{
    public function test_digit_only_query_retries_nk025_suffix_and_keeps_leaf_without_request_allowed(): void
    {
        $calls = [];
        $fetch = static function (array $params) use (&$calls): array {
            $calls[] = $params['code'] ?? null;
            if (($params['code'] ?? null) === '37003-00') {
                return [[
                    'id' => 'svc-1',
                    'code' => '37003-00',
                    'name' => 'Обстеження',
                    'is_active' => true,
                ]];
            }

            return [];
        };

        $results = ServiceSearch::search('37003', $fetch);

        $this->assertSame(['37003', '37003-00'], $calls);
        $this->assertCount(1, $results);
        $this->assertSame('37003-00', $results[0]['code']);
    }

    public function test_flatten_keeps_nested_services_and_skips_inactive_leaves(): void
    {
        $flat = ServiceSearch::flattenRequestable([
            [
                'id' => 'group-1',
                'code' => '37000',
                'name' => 'Група',
                'groups' => [],
                'services' => [
                    [
                        'id' => 'svc-active',
                        'code' => '37003-00',
                        'name' => 'Активна',
                        'is_active' => true,
                    ],
                    [
                        'id' => 'svc-inactive',
                        'code' => '37004-00',
                        'name' => 'Неактивна',
                        'is_active' => false,
                    ],
                ],
            ],
        ]);

        $this->assertSame(['svc-active'], array_column($flat, 'id'));
    }

    public function test_name_query_does_not_send_code(): void
    {
        $paramsSeen = [];
        $fetch = static function (array $params) use (&$paramsSeen): array {
            $paramsSeen = $params;

            return [];
        };

        ServiceSearch::search('обстеж', $fetch);

        $this->assertArrayHasKey('name', $paramsSeen);
        $this->assertArrayNotHasKey('code', $paramsSeen);
        $this->assertSame('обстеж', $paramsSeen['name']);
    }

    public function test_filter_by_query_needle_filters_out_unrelated_tree_nodes(): void
    {
        $mockTree = [
            [
                'id' => 'group-palliative',
                'code' => 'A59001',
                'name' => 'Паліативна допомога',
                'request_allowed' => true,
                'services' => [
                    [
                        'id' => 'svc-insulin',
                        'code' => '96200-06',
                        'name' => 'Підшкірне введення фармакологічного засобу, інсулін',
                        'is_active' => true,
                        'request_allowed' => true,
                    ],
                    [
                        'id' => 'svc-other',
                        'code' => '30224-02',
                        'name' => 'Черезшкірне дренування абсцесу',
                        'is_active' => true,
                        'request_allowed' => true,
                    ],
                ],
            ],
        ];

        $results = ServiceSearch::search('інсул', static fn (array $p): array => $mockTree);

        $this->assertCount(1, $results);
        $this->assertSame('96200-06', $results[0]['code']);
        $this->assertNotContains('A59001', array_column($results, 'code'));
    }

    public function test_search_returns_empty_when_no_items_match_query(): void
    {
        $mockTree = [
            [
                'id' => 'group-palliative',
                'code' => 'A59001',
                'name' => 'Паліативна допомога',
                'request_allowed' => true,
            ],
        ];

        $results = ServiceSearch::search('неіснуючапослуга999', static fn (array $p): array => $mockTree);

        $this->assertSame([], $results);
    }
}
