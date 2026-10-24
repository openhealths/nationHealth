<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Models\Person\Person;
use App\Repositories\MedicalEvents\CompositionRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompositionFhirFidelityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_complete_details_preserve_false_codes_events_and_fractional_utc(): void
    {
        $person = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'MALE']);
        $concept = ['text' => 'Full concept', 'coding' => [
            ['system' => 'one', 'code' => 'a', 'display' => 'First', 'version' => '1', 'userSelected' => false],
            ['system' => 'two', 'code' => 'b', 'display' => 'Second'],
        ]];
        $details = [
            'identifier' => ['value' => (string) Str::uuid()], 'status' => 'PRELIMINARY',
            'date' => '2026-10-24T00:41:12.123456+03:00',
            'type' => $concept, 'category' => $concept,
            'subject' => ['value' => $person->uuid, 'type' => $concept],
            'extension' => [['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => false]],
            'event' => [['code' => $concept, 'period' => ['start' => '2026-10-24T00:41:12.123456+03:00', 'end' => '2026-10-24T01:41:12.123456+03:00']],
                ['code' => $concept]],
        ];
        $repository = app(CompositionRepository::class);
        $composition = $repository->store($details, $person);
        $result = $composition->toDetail();
        $this->assertSame('2026-10-23T21:41:12.123456Z', $result['date']);
        $this->assertSame($details['extension'], $result['extension']);
        $this->assertEquals($concept, $result['type']);
        $this->assertEquals($concept, $result['category']);
        $this->assertEquals($details['subject'], $result['subject']);
        $this->assertEquals($concept, $result['event'][0]['code']);
        $this->assertCount(2, $result['event']);
        $this->assertSame('2026-10-23T21:41:12.123456Z', $result['event'][0]['period']['start']);
        $updated = $repository->store(['identifier' => $details['identifier'], 'status' => 'FINAL'], $person)->toDetail();
        $this->assertSame($result['event'], $updated['event']);
        $this->assertSame($result['extension'], $updated['extension']);
        $concept['coding'] = [$concept['coding'][1]];
        $details['subject']['type'] = $concept;
        $details['extension'] = [];
        $details['event'] = [];
        $updated = $repository->store($details, $person)->toDetail();
        $this->assertEquals($concept, $updated['subject']['type']);
        $this->assertSame([], $updated['extension']);
        $this->assertSame([], $updated['event']);
        $this->assertSame('2026-10-23T21:41:12.123456Z', $updated['date']);
    }
}
