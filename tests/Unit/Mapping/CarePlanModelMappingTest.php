<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\CarePlan\Model;
use App\Livewire\CarePlan\CarePlanCreate;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

class CarePlanModelMappingTest extends TestCase
{
    public function test_local_draft_mapping_retains_display_snapshots_and_null_fields_without_ownership_or_key_fields(): void
    {
        $form = new CarePlanForm(new CarePlanCreate(), 'form');
        $form->fill([
            'category' => 'CLASS_23', 'title' => 'Local draft', 'context' => 'context-code',
            'periodStart' => '05.10.2026', 'periodEnd' => '15.10.2026', 'termsOfService' => 'OUTPATIENT',
            'description' => 'Description', 'note' => 'Note', 'informWith' => 'SMS',
            'episodes' => [3 => ['uuid' => 'episode', 'name' => 'Local episode', 'date' => '05.10.2026']],
            'medicalRecords' => [7 => ['uuid' => 'record', 'name' => 'Local record', 'unknownKey' => false]],
            'author' => 'untrusted-display', 'patient' => 'untrusted-display', 'password' => 'synthetic-secret',
            'encounter' => 'unresolved-uuid', 'medical_number' => '999',
        ]);
        $this->assertSame([
            'category' => 'CLASS_23', 'context' => 'context-code', 'title' => 'Local draft',
            'terms_of_service' => 'OUTPATIENT', 'period_start' => '2026-10-05', 'period_end' => '2026-10-15',
            'supporting_info' => [
                'episodes' => [3 => ['uuid' => 'episode', 'name' => 'Local episode', 'date' => '05.10.2026']],
                'medical_records' => [7 => ['uuid' => 'record', 'name' => 'Local record', 'unknownKey' => false]],
            ],
            'description' => 'Description', 'note' => 'Note', 'inform_with' => 'SMS',
        ], $this->mapWithoutIo($form));
    }

    public function test_empty_editable_fields_keep_the_original_draft_clearing_policy(): void
    {
        $form = new CarePlanForm(new CarePlanCreate(), 'form');
        $form->fill([
            'category' => 'CLASS_23', 'title' => 'Draft', 'periodStart' => '05.10.2026',
            'context' => '0', 'description' => '0', 'note' => '0', 'informWith' => '0',
        ]);
        $this->assertSame([
            'category' => 'CLASS_23', 'context' => null, 'title' => 'Draft', 'terms_of_service' => null,
            'period_start' => '2026-10-05', 'period_end' => null,
            'supporting_info' => ['episodes' => [], 'medical_records' => []],
            'description' => null, 'note' => null, 'inform_with' => null,
        ], $this->mapWithoutIo($form));
    }

    private function mapWithoutIo(CarePlanForm $form): array
    {
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $data = app(ObjectMapperInterface::class)->map($form, Model::class)->toArray();
        $this->assertSame([], $queries);
        Http::assertNothingSent();

        return $data;
    }
}
