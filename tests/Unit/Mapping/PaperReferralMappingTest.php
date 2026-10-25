<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Core\Arr;
use App\Dto\FormCollection;
use App\Dto\PaperReferral\Ehealth;
use App\Dto\PaperReferral\Form;
use App\Enums\Person\DiagnosticReportStatus;
use App\Models\LegalEntity;
use App\Dto\DiagnosticReport\Ehealth as DiagnosticReportData;
use App\Dto\DiagnosticReport\Form as DiagnosticReportForm;
use App\Dto\Procedure\Ehealth as ProcedureData;
use App\Dto\Procedure\Form as ProcedureForm;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaperReferralMappingTest extends TestCase
{
    #[DataProvider('contracts')]
    public function testMainContractsAndBothParentCallersWithoutIo(array $input, array $expected): void
    {
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
        $this->instance('legalEntity', new LegalEntity(['uuid' => 'legal-entity']));
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $outbound = Ehealth::fromSource(new FormCollection($input['outbound']))?->toArray();
        $this->assertSame($expected['outbound'], $outbound);
        $this->assertSame($expected['json'], json_encode($outbound, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame($expected['form'], Form::fromSource(new FormCollection($input['inbound']))->toArray());

        $fields = $input['outbound'] + [
            'status' => 'completed', 'codeValue' => 'service', 'categoryCode' => 'procedure',
            'primarySource' => true, 'performerEmployeeId' => 'employee',
            'issuedDate' => '05.10.2026', 'issuedTime' => '10:00',
        ];
        $mapper = app(ObjectMapperInterface::class);
        $parents = [
            $mapper->map(new FormCollection($fields), new ProcedureData('procedure', 'legal-entity', 'employee'))->toArray(),
            $mapper->map(new FormCollection($fields), new DiagnosticReportData('diagnostic', DiagnosticReportStatus::FINAL, 'legal-entity', 'employee'))->toArray(),
        ];
        foreach ($parents as $parent) {
            $this->assertSame($expected['outbound'] === null ? null : Arr::toSnakeCase($expected['outbound']), $parent['paper_referral'] ?? null);
        }
        foreach ([ProcedureForm::class, DiagnosticReportForm::class] as $target) {
            $form = $mapper->map(new Collection($input['inbound']), $target)->toArray();
            $this->assertSame($expected['form'], Arr::only($form, array_keys($expected['form'])));
        }
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($directory.'/paper-referral-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/paper-referral-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }
}
