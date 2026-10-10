<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Classes\Cipher\Api\CipherApi;
use App\Core\Arr;
use App\Dto\CarePlan\Ehealth;
use App\Livewire\CarePlan\CarePlanCreate;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Services\SignatureService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class CarePlanEhealthMappingTest extends TestCase
{
    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $inputs = require $directory.'/care-plan-create-inputs.php';
        $baseline = json_decode(file_get_contents($directory.'/care-plan-create-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($inputs as $name => $input) {
            yield $name => [$input, $baseline['cases'][$name]];
        }
    }

    #[DataProvider('contracts')]
    public function test_direct_form_mapping_preserves_original_payload_and_signing_bytes_without_io(array $input, array $expected): void
    {
        config(['app.timezone' => 'Europe/Kyiv']);
        $form = $this->form($input);
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $target = new Ehealth($input['id'], $input['employeeUuid'], $input['encounterData'], 'Europe/Kyiv');
        $mapper = app(ObjectMapperInterface::class);
        $this->assertSame($target, $mapper->map($form, $target));
        $payload = $target->toArray();
        $this->assertSame($expected['payload'], $payload);
        $this->assertSame($expected['signingJson'], json_encode(Arr::toSnakeCase($payload), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_signature_service_receives_original_care_plan_bytes(): void
    {
        [$input, $expected] = iterator_to_array(self::contracts())['full_sparse_lists'];
        $cipher = Mockery::mock(CipherApi::class);
        $cipher->shouldReceive('sendSession')->once()->with(
            $expected['signingJson'],
            'test-password',
            base64_encode('synthetic-key'),
            'test-knedp',
            '0000000000'
        )->andReturn('synthetic-signed-content');
        $key = UploadedFile::fake()->createWithContent('test.dat', 'synthetic-key');
        $upload = Mockery::mock(UploadedFile::class);
        $upload->shouldReceive('exists')->once()->andReturnTrue();
        $upload->shouldReceive('getClientOriginalExtension')->once()->andReturn('dat');
        $upload->shouldReceive('getRealPath')->once()->andReturn($key->getRealPath());

        $payload = app(ObjectMapperInterface::class)->map(
            $this->form($input),
            new Ehealth($input['id'], $input['employeeUuid'], $input['encounterData'], 'Europe/Kyiv')
        )->toArray();
        $this->assertSame('synthetic-signed-content', (new SignatureService($cipher))->signData(
            Arr::toSnakeCase($payload),
            'test-password',
            'test-knedp',
            $upload,
            '0000000000'
        ));
    }

    private function form(array $input): CarePlanForm
    {
        $form = new CarePlanForm(new CarePlanCreate(), 'form');
        $form->fill($input['form']);
        $form->password = 'synthetic-secret';
        $form->knedp = 'synthetic-provider';
        $form->keyContainerFileName = 'private-key.dat';
        $form->medical_number = 'local-number';
        $form->patient = 'local-display';
        $form->intent = 'client-intent';

        return $form;
    }
}
