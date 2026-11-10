<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Classes\eHealth\Api\Division as DivisionApi;
use App\Classes\eHealth\EHealthResponse;
use App\Dto\Division\DivisionGetMany;
use App\Jobs\DivisionSync;
use App\Models\LegalEntity;
use App\Repositories\DivisionRepository;
use Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DivisionSyncJobTest extends TestCase
{
    use DivisionMappingFixtures;

    protected function responsePage(array $rows, int $status = 200): EHealthResponse
    {
        $api = new class extends DivisionApi
        {
            public function validatePage(EHealthResponse $response): array
            {
                return $this->validateMany($response);
            }
        };

        return new EHealthResponse(
            new Response($status, [], json_encode(['data' => $rows], JSON_THROW_ON_ERROR)),
            $api->validatePage(...)
        );
    }

    protected function process(DivisionSync $job, ?EHealthResponse $response): void
    {
        (function (?EHealthResponse $response): void {
            $this->processResponse($response);
        })->call($job, $response);
    }

    public function test_queued_page_requests_the_configured_page_size(): void
    {
        config(['ehealth.api.page_size_max' => 75]);
        $api = $this->mock(DivisionApi::class);
        $api->shouldReceive('withToken')->once()->with('queue-token')->andReturnSelf();
        $api->shouldReceive('getMany')->once()
            ->with(DivisionApi::URL, ['page' => 2, 'page_size' => 75])
            ->andReturn($this->responsePage([]));

        (function (): void {
            $this->sendRequest('queue-token');
        })->call(new DivisionSync(page: 2));
    }

    public function test_restored_first_login_job_maps_the_validated_page_without_a_component(): void
    {
        $entity = new LegalEntity();
        $entity->id = 7;
        $job = unserialize(serialize(new DivisionSync(legalEntity: $entity, isFirstLogin: true)));
        $serialized = serialize($job);
        $first = $this->response([
            'external_id' => '0',
            'location' => ['latitude' => '50.1234567890123456789', 'longitude' => '0'],
        ]);
        $first['addresses'][0]['addressable_id'] = 999;
        $first['phones'][0]['phoneable_id'] = 999;
        $second = $this->response();
        foreach (['external_id', 'location', 'working_hours', 'mountain_group', 'dls_id',
            'dls_verified', 'inserted_at', 'updated_at', 'inserted_by', 'updated_by'] as $field) {
            unset($second[$field]);
        }

        $this->mock(DivisionRepository::class)->shouldReceive('saveDivisionsList')->once()
            ->with(Mockery::on(function (DivisionGetMany $divisions) use ($first, $second): bool {
                $rows = $divisions->toArray();
                $this->assertCount(2, $rows);
                $this->assertSame([0, 1], array_keys($rows));
                $this->assertSame($first['id'], $rows[0]['uuid']);
                $this->assertSame($second['id'], $rows[1]['uuid']);
                $this->assertSame('0', $rows[0]['external_id']);
                $this->assertSame($first['location'], $rows[0]['location']);
                $this->assertEquals($first['working_hours'], $rows[0]['working_hours']);
                $this->assertFalse($rows[0]['dls_verified']);
                $this->assertSame($first['inserted_at'], $rows[0]['ehealth_inserted_at']);
                $this->assertSame($first['updated_by'], $rows[0]['updated_by']);
                $this->assertSame('0', $rows[0]['addresses'][0]['building']);
                $this->assertArrayNotHasKey('addressable_id', $rows[0]['addresses'][0]);
                $this->assertArrayNotHasKey('phoneable_id', $rows[0]['phones'][0]);
                $this->assertArrayNotHasKey('note', $rows[0]['phones'][0]);
                $this->assertArrayNotHasKey('id', $rows[0]);
                $this->assertArrayNotHasKey('isForm', $rows[0]);
                $this->assertSame(array_keys($rows[0]), array_keys($rows[1]));
                $this->assertFalse($rows[1]['mountain_group']);
                foreach (['external_id', 'location', 'working_hours', 'dls_verified',
                    'ehealth_inserted_at', 'updated_by'] as $field) {
                    $this->assertNull($rows[1][$field], $field);
                }

                return true;
            }), Mockery::on(fn (LegalEntity $actual): bool => $actual->id === $entity->id));

        $this->process($job, $this->responsePage([5 => $first, 9 => $second]));

        $this->assertSame($serialized, serialize($job));
    }

    public static function emptyResponses(): array
    {
        return ['no response' => [false], 'empty validated page' => [true]];
    }

    #[DataProvider('emptyResponses')]
    public function test_empty_response_does_not_save(bool $hasResponse): void
    {
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveDivisionsList');

        $this->process(new DivisionSync(), $hasResponse ? $this->responsePage([]) : null);
    }

    public function test_invalid_page_is_rejected_before_saving(): void
    {
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveDivisionsList');
        $this->expectException(ValidationException::class);

        $this->process(new DivisionSync(), $this->responsePage([
            $this->response(['phones' => [['type' => 'MOBILE']]]),
        ]));
    }

    public function test_failed_http_response_does_not_save(): void
    {
        $this->mock(DivisionRepository::class)->shouldNotReceive('saveDivisionsList');
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('validateMany:');

        $this->process(new DivisionSync(), $this->responsePage([], 500));
    }

    public function test_persistence_failure_propagates_to_queue_failure_handling(): void
    {
        $this->mock(DivisionRepository::class)->shouldReceive('saveDivisionsList')->once()
            ->andThrow(new RuntimeException('Persistence failed.'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Persistence failed.');

        $this->process(
            new DivisionSync(legalEntity: new LegalEntity()),
            $this->responsePage([$this->response()])
        );
    }
}
