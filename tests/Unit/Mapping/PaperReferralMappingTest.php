<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Core\Arr;
use App\Dto\DiagnosticReport\Ehealth as DiagnosticEhealth;
use App\Dto\DiagnosticReport\Form as DiagnosticForm;
use App\Dto\FormCollection;
use App\Dto\PaperReferral\Ehealth;
use App\Dto\PaperReferral\Form;
use App\Dto\Procedure\Ehealth as ProcedureEhealth;
use App\Dto\Procedure\Form as ProcedureForm;
use App\Enums\Person\DiagnosticReportStatus;
use App\Models\LegalEntity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class PaperReferralMappingTest extends TestCase
{
    #[DataProvider('contracts')]
    public function test_old_wire_and_hydration_contracts_survive_in_both_clinical_parents(array $input, array $expected): void
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
        $mapper = app(ObjectMapperInterface::class);
        $outbound = empty($input['outbound']['paperReferralRequesterLegalEntityEdrpou']) ? null
            : $mapper->map(new FormCollection($input['outbound']), Ehealth::class)->toArray();
        $this->assertSame($expected['outbound'] === null ? null : Arr::toSnakeCase($expected['outbound']), $outbound);
        $this->assertSame($expected['json'], json_encode($outbound, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame($expected['form'], $mapper->map(new Collection($input['inbound']), Form::class)->toArray());

        $fields = $input['outbound'] + [
            'status' => 'completed', 'codeValue' => 'service', 'categoryCode' => 'procedure',
            'primarySource' => true, 'performerEmployeeId' => 'employee',
            'issuedDate' => '05.10.2026', 'issuedTime' => '10:00',
        ];
        $uuids = ['procedure' => 'procedure', 'diagnosticReport' => 'diagnostic', 'employee' => 'employee'];
        $parents = [
            Arr::toCamelCase($mapper->map(new FormCollection($fields), new ProcedureEhealth('procedure', 'legal-entity', 'employee'))->toArray()),
            Arr::toCamelCase($mapper->map(new FormCollection($fields), new DiagnosticEhealth('diagnostic', DiagnosticReportStatus::FINAL, 'legal-entity', 'employee'))->toArray()),
        ];
        foreach ($parents as $parent) {
            $this->assertSame($expected['outbound'], $parent['paperReferral'] ?? null);
        }
        foreach ([$mapper->map(new Collection($input['inbound']), ProcedureForm::class)->toArray(), $mapper->map(new Collection($input['inbound']), DiagnosticForm::class)->toArray()] as $form) {
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
