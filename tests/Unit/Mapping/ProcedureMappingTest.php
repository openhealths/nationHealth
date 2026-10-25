<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\FormCollection;
use App\Dto\Procedure\Ehealth;
use App\Dto\Procedure\EhealthCancellation;
use App\Dto\Procedure\Form;
use App\Livewire\Procedure\Forms\ProcedureForm;
use App\Livewire\Procedure\ProcedureCreate;
use App\Livewire\Procedure\ProcedureIndex;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Classes\eHealth\Api\Patient\Procedure as ProcedureApi;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Support\Facades\Auth;
use ReflectionMethod;
use Mockery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class ProcedureMappingTest extends TestCase
{
    #[DataProvider('contracts')]
    public function test_old_create_form_and_cancellation_contracts_with_both_sources_without_io(array $input, array $expected): void
    {
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
        app()->forgetInstance('legalEntity');
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $mapper = app(ObjectMapperInterface::class);
        $payload = $mapper->map(new FormCollection($input['outbound']), new Ehealth('procedure', 'legal-entity', 'employee', $input['encounter']))->toArray();
        $this->assertSame($expected['signedJson'], $this->documentJson($payload));
        $form = new ProcedureForm(new ProcedureCreate(), 'form');
        $form->procedure = $input['outbound'];
        $this->assertSame($expected['signedJson'], $this->documentJson($mapper->map($form, new Ehealth('procedure', 'legal-entity', 'employee', $input['encounter']))->toArray()));
        $details = ['condition' => ['ehealthInsertedAt' => 'date', 'codeCode' => 'D02', 'codeSystem' => 'ICPC2'], 'complication' => ['codeCode' => 'D03']];
        $this->assertSame($expected['form'], $mapper->map(new Collection($input['inbound']), new Form($details))->toArray());
        $raw = ['id' => 'procedure', 'status' => 'completed', 'inserted_at' => 'removed', 'updatedBy' => 'removed', 'unknown_field' => ['literal_key' => 0, 'null' => null, 'false' => false, 'list' => [42 => 'retained']]];
        $cancel = $mapper->map(new Collection(['statusReason' => 'incorrect_data', 'explanatoryLetter' => null, 'statusReasonText' => 'Опис']), new EhealthCancellation($raw))->toArray();
        $this->assertSame($expected['cancelJson'], $this->documentJson($cancel));
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }
    private function documentJson(array $document): string
    {
        return json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
    public function test_standalone_caller_maps_validated_clone_and_preserves_explicit_uuid(): void
    {
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
        app()->instance('legalEntity', new LegalEntity()->forceFill(['uuid' => 'legal-entity']));
        $user = Mockery::mock(User::class);
        $user->shouldReceive('getProcedureWriterEmployee')->once()->andReturn(new Employee()->forceFill(['uuid' => 'employee']));
        Auth::shouldReceive('user')->andReturn($user);
        $component = new ProcedureCreate();
        $component->form = new ProcedureForm($component, 'form');
        $component->form->procedure = ['unknown' => 'raw form'];
        $input = (require dirname(__DIR__, 2).'/Fixtures/Mapping/procedure-inputs.php')['minimal']['outbound'];
        $payload = new ReflectionMethod(ProcedureCreate::class, 'prepareFormattedData')->invoke($component, ['procedure' => $input], 'explicit-uuid');
        $expected = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/Mapping/procedure-baseline.json'), true)['minimal']['json'];
        $this->assertSame(str_replace('"id":"procedure"', '"id":"explicit-uuid"', $expected), $this->documentJson($payload));
        $this->assertSame(['unknown' => 'raw form'], $component->form->procedure);
    }
    public function test_cancellation_caller_maps_raw_api_document_and_dictionary_text(): void
    {
        $component = new ProcedureIndex();
        $component->uuid = 'patient';
        $component->dictionaries = ['eHealth/procedure_status_reasons' => ['incorrect_data' => 'Опис']];
        $raw = ['id' => 'procedure', 'status' => 'completed', 'inserted_at' => 'removed', 'updatedBy' => 'removed', 'unknown_field' => ['literal_key' => 0, 'null' => null, 'false' => false, 'list' => [42 => 'retained']]];
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->once()->andReturn($raw);
        $this->mock(ProcedureApi::class)->shouldReceive('getById')->once()->with('patient', 'procedure')->andReturn($response);
        $payload = new ReflectionMethod(ProcedureIndex::class, 'buildCancellationPackage')->invoke($component, new Procedure()->forceFill(['uuid' => 'procedure']), 'incorrect_data', null);
        $expected = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/Mapping/procedure-baseline.json'), true)['minimal']['cancelJson'];
        $this->assertSame($expected, $this->documentJson($payload));
    }
    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($directory.'/procedure-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/procedure-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }
}
