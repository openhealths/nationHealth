<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Classes\eHealth\Api\Patient\Condition as ConditionApi;
use App\Classes\eHealth\EHealthResponse;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class EncounterCancellationPaginationTest extends TestCase
{
    private const string PATIENT = 'e4b04d5a-4dfe-442a-969b-96049e325521';
    private const string ENCOUNTER = 'b71c62ab-9936-4c96-9178-9fdbebf1e459';

    public function test_every_page_is_read_before_the_signed_package_is_rebuilt(): void
    {
        $first = $this->record('b71c62ab-9936-4c96-9178-9fdbebf1e460');
        $second = $this->record('b71c62ab-9936-4c96-9178-9fdbebf1e461');
        $api = $this->mock(ConditionApi::class);
        foreach ([1 => $first, 2 => $second] as $page => $record) {
            $api->shouldReceive('getBySearchParams')->once()->with(self::PATIENT, [
                'encounter_id' => self::ENCOUNTER, 'page' => $page, 'page_size' => 300,
            ])->andReturn($this->response([$record], $this->paging($page, 2, 2)));
        }
        $this->assertSame([$first, $second], $this->read());
    }

    #[DataProvider('invalidPages')]
    public function test_unreliable_pages_cannot_be_used_for_cancellation(array $items, array $paging): void
    {
        $this->mock(ConditionApi::class)->shouldReceive('getBySearchParams')->once()
            ->andReturn($this->response($items, $paging));
        $this->expectException(RuntimeException::class);
        $this->read();
    }

    public static function invalidPages(): array
    {
        $record = ['uuid' => 'b71c62ab-9936-4c96-9178-9fdbebf1e460', 'context' => ['identifier' => ['value' => self::ENCOUNTER]]];

        return [
            'missing last record' => [[$record], ['page_number' => 1, 'page_size' => 300, 'total_pages' => 1, 'total_entries' => 2]],
            'empty intermediate page' => [[], ['page_number' => 1, 'page_size' => 300, 'total_pages' => 2, 'total_entries' => 1]],
            'foreign encounter' => [[['uuid' => $record['uuid'], 'context' => ['identifier' => ['value' => self::PATIENT]]]], ['page_number' => 1, 'page_size' => 300, 'total_pages' => 1, 'total_entries' => 1]],
        ];
    }

    public function test_missing_paging_is_rejected_even_for_an_empty_collection(): void
    {
        $this->mock(ConditionApi::class)->shouldReceive('getBySearchParams')->once()->andReturn($this->response([], []));
        $this->expectException(ValidationException::class);
        $this->read();
    }

    public function test_a_repeated_record_cannot_hide_an_unread_second_record(): void
    {
        $record = $this->record('b71c62ab-9936-4c96-9178-9fdbebf1e460');
        $api = $this->mock(ConditionApi::class);
        $api->shouldReceive('getBySearchParams')->once()->ordered()->andReturn($this->response([$record], $this->paging(1, 2, 2)));
        $api->shouldReceive('getBySearchParams')->once()->ordered()->andReturn($this->response([$record], $this->paging(2, 2, 2)));
        $this->expectException(RuntimeException::class);
        $this->read();
    }

    public function test_changed_collection_totals_stop_the_cancellation(): void
    {
        $api = $this->mock(ConditionApi::class);
        $api->shouldReceive('getBySearchParams')->once()->ordered()->andReturn($this->response([$this->record('b71c62ab-9936-4c96-9178-9fdbebf1e460')], $this->paging(1, 2, 2)));
        $api->shouldReceive('getBySearchParams')->once()->ordered()->andReturn($this->response([$this->record('b71c62ab-9936-4c96-9178-9fdbebf1e461')], $this->paging(2, 3, 2)));
        $this->expectException(RuntimeException::class);
        $this->read();
    }

    public function test_a_validation_failure_on_a_later_page_is_never_silently_skipped(): void
    {
        $api = $this->mock(ConditionApi::class);
        $api->shouldReceive('getBySearchParams')->once()->ordered()->andReturn($this->response([$this->record('b71c62ab-9936-4c96-9178-9fdbebf1e460')], $this->paging(1, 2, 2)));
        $invalid = Mockery::mock(EHealthResponse::class);
        $invalid->shouldNotReceive('getData');
        $invalid->shouldReceive('validate')->once()->andThrow(ValidationException::withMessages(['uuid' => 'Malformed remote resource']));
        $api->shouldReceive('getBySearchParams')->once()->ordered()->andReturn($invalid);
        $this->expectException(ValidationException::class);
        $this->read();
    }

    private function read(): array
    {
        return new ReflectionMethod(Repository::encounter(), 'cancellationRecords')->invoke(
            Repository::encounter(),
            'condition',
            'encounter_id',
            self::PATIENT,
            self::ENCOUNTER
        );
    }

    private function response(array $items, array $paging): EHealthResponse
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldNotReceive('getData');
        $response->shouldReceive('validate')->once()->andReturn($items);
        $response->shouldReceive('getPaging')->once()->andReturn($paging);

        return $response;
    }

    private function record(string $uuid): array
    {
        return ['uuid' => $uuid, 'context' => ['identifier' => ['value' => self::ENCOUNTER]]];
    }

    private function paging(int $page, int $total, int $pages): array
    {
        return ['page_number' => $page, 'page_size' => 300, 'total_pages' => $pages, 'total_entries' => $total];
    }
}
