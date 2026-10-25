<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\MedicationRequest\EhealthDraft;
use App\Dto\MedicationRequest\EhealthDraftPrequalify;
use App\Livewire\MedicationRequest\MedicationRequestForm;
use App\Models\LegalEntity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class StandaloneMedicationRequestMappingTest extends TestCase
{
    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $inputs = require $directory.'/standalone-medication-request-inputs.php';
        $baseline = json_decode(file_get_contents($directory.'/standalone-medication-request-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($inputs as $name => $input) {
            yield $name => [$input, $baseline['cases'][$name]];
        }
    }

    #[DataProvider('contracts')]
    public function test_direct_validated_screen_source_preserves_the_original_payload_and_excludes_ui_and_key_data(array $input, array $expected): void
    {
        $screen = new MedicationRequestForm();
        $screen->legalEntity = (new LegalEntity())->forceFill(['id' => 999]);
        foreach ($input as $field => $value) {
            $screen->$field = $value;
        }
        $screen->form['password'] = 'synthetic-secret';
        $screen->draftContent = ['untrusted' => 'raw document'];
        $screen->draftId = 'local-draft';
        $screen->showSignatureModal = true;
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $mapper = app(ObjectMapperInterface::class);
        $create = $mapper->map($screen, EhealthDraft::class)->toArray();
        $this->assertSame($expected['prequalify'], $mapper->map($screen, EhealthDraftPrequalify::class)->toArray());
        $this->assertSame($expected['create'], $create);
        $this->assertSame($expected['createJson'], json_encode($create, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }
}
