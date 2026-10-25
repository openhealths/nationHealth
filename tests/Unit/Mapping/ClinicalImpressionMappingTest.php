<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\ClinicalImpression\Ehealth;
use App\Dto\ClinicalImpression\Form;
use App\Dto\FormCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class ClinicalImpressionMappingTest extends TestCase
{
    #[DataProvider('contracts')]
    public function test_independent_old_payload_and_hydration_contracts_without_io(array $input, array $expected): void
    {
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $mapper = app(ObjectMapperInterface::class);
        $payload = $mapper->map(new FormCollection($input['outbound']), new Ehealth('impression', 'encounter', 'employee'))->toArray();
        $this->assertSame($expected['signedJson'], json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $details = ['previous' => ['ehealthInsertedAt' => 'date', 'codeCode' => 'previous'], 'problem' => ['codeCode' => 'D02', 'codeSystem' => 'ICPC2'], 'finding' => ['ehealthInsertedAt' => 'date', 'codeCode' => '111', 'codeSystem' => 'LOINC'], 'support' => ['codeCode' => '301']];
        $this->assertSame($expected['form'], $mapper->map(new Collection($input['inbound']), new Form($details))->toArray());
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($directory.'/clinical-impression-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/clinical-impression-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }

    public function test_date_time_excludes_period_and_hydrates_the_form_fields(): void
    {
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
        $mapper = app(ObjectMapperInterface::class);
        $payload = $mapper->map(new FormCollection([
            'codeCode' => 'consultation', 'effectiveType' => 'date_time',
            'effectiveDate' => '05.10.2026', 'effectiveTime' => '10:00',
            'effectivePeriodStartDate' => '01.10.2026', 'effectivePeriodStartTime' => '09:00',
        ]), new Ehealth('impression', 'encounter', 'employee'))->toArray();

        $this->assertSame('2026-10-05T07:00:00Z', $payload['effective_date_time']);
        $this->assertArrayNotHasKey('effective_period', $payload);
        $form = $mapper->map(new Collection([
            'effectiveDateTime' => '2026-10-05T07:00:00Z',
            'effectiveDate' => '05.10.2026', 'effectiveTime' => '10:00',
        ]), Form::class)->toArray();
        $this->assertSame('date_time', $form['effectiveType']);
        $this->assertSame('05.10.2026', $form['effectiveDate']);
        $this->assertSame('10:00', $form['effectiveTime']);
    }

    public function test_open_period_omits_the_end_instead_of_synthesizing_a_timestamp(): void
    {
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
        $payload = app(ObjectMapperInterface::class)->map(new FormCollection([
            'codeCode' => 'consultation', 'effectiveType' => 'period',
            'effectivePeriodStartDate' => '05.10.2026', 'effectivePeriodStartTime' => '10:00',
            'effectivePeriodEndDate' => '', 'effectivePeriodEndTime' => '',
        ]), new Ehealth('impression', 'encounter', 'employee'))->toArray();

        $this->assertSame(['start' => '2026-10-05T07:00:00Z'], $payload['effective_period']);
        $this->assertArrayNotHasKey('effective_date_time', $payload);
    }
}
