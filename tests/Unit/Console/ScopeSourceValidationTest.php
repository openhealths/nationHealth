<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\Update;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ScopeSourceValidationTest extends TestCase
{
    public static function invalidSources(): iterable
    {
        $invalidScopes = 'Invalid scopes file :path. ';
        $payloads = [
            'malformed JSON' => ['{"data":', 'Invalid JSON in scopes file :path.'],
            'null document' => ['null', $invalidScopes . 'The data field is required.'],
            'missing data' => ['{}', $invalidScopes . 'The data field is required.'],
            'null data' => ['{"data":null}', $invalidScopes . 'The data field is required.'],
            'empty data' => ['{"data":[]}', $invalidScopes . 'The data field is required.'],
            'scalar data' => ['{"data":"invalid"}', $invalidScopes . 'The data field must be an array.'],
            'object data' => [
                '{"data":{"OWNER":{"name":"OWNER","scope":"legal_entity:read"}}}',
                $invalidScopes . 'The data field must be a list.',
            ],
            'invalid row' => ['{"data":[null]}', $invalidScopes . 'The data.0 field is required.'],
            'missing name' => [
                '{"data":[{"scope":"legal_entity:read"}]}',
                $invalidScopes . 'The data.0.name field is required.',
            ],
            'blank name' => [
                '{"data":[{"name":" ","scope":"legal_entity:read"}]}',
                $invalidScopes . 'The data.0.name field is required.',
            ],
            'missing scope' => [
                '{"data":[{"name":"OWNER"}]}',
                $invalidScopes . 'The data.0.scope field is required.',
            ],
            'blank scope' => [
                '{"data":[{"name":"OWNER","scope":" "}]}',
                $invalidScopes . 'The data.0.scope field is required.',
            ],
            'invalid scope type' => [
                '{"data":[{"name":"OWNER","scope":123}]}',
                $invalidScopes . 'The data.0.scope field must be a string.',
            ],
            'duplicate names' => [
                '{"data":[{"name":"OWNER","scope":"legal_entity:read"},{"name":"OWNER","scope":"employee:read"}]}',
                $invalidScopes . 'The data.0.name field has a duplicate value.',
            ],
            'invalid missed type' => [
                '{"data":[{"name":"OWNER","scope":"legal_entity:read","missed":null}]}',
                $invalidScopes . 'The data.0.missed field must be a string.',
            ],
            'invalid extra type' => [
                '{"data":[{"name":"OWNER","scope":"legal_entity:read","extra":[]}]}',
                $invalidScopes . 'The data.0.extra field must be a string.',
            ],
        ];

        foreach ([
            ['legal_entity_types', 'PRIMARY_CARE'],
            ['legal_entity_types', 'OUTPATIENT'],
            ['roles', 'OWNER'],
            ['roles', 'ADMIN'],
            ['roles', 'DOCTOR'],
            ['roles', 'SPECIALIST'],
        ] as [$source, $name]) {
            foreach ($payloads as $description => [$payload, $expectedMessage]) {
                yield $source . ': ' . $name . ': ' . $description => [
                    $source,
                    str_replace('"OWNER"', '"' . $name . '"', $payload),
                    $expectedMessage,
                ];
            }
        }

        yield 'no eligible roles' => [
            'roles',
            '{"data":[{"name":"UNSUPPORTED_ROLE","scope":"legal_entity:read"}]}',
            'No eligible roles found in scopes file :path.',
        ];
        yield 'unknown legal entity type' => [
            'legal_entity_types',
            '{"data":[{"name":"UNSUPPORTED_TYPE","scope":"legal_entity:read"}]}',
            'Unknown legal entity type UNSUPPORTED_TYPE in scopes file :path.',
        ];
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_source_stops_update_before_database_and_file_changes(
        string $source,
        string $payload,
        string $expectedMessage
    ): void {
        $this->app->setLocale('en');

        $directory = sys_get_temp_dir() . '/mis-scope-source-' . bin2hex(random_bytes(8));
        $files = new Filesystem();
        $files->makeDirectory($directory . '/scopes', recursive: true);
        $files->put($directory . '/scopes/' . $source . '.json', $payload);
        $adapter = new LocalFilesystemAdapter($directory);
        Storage::swap(new FilesystemAdapter(new Flysystem($adapter), $adapter));
        DB::partialMock()->shouldReceive('transaction')->never();
        File::shouldReceive('replace')->never();

        $command = Mockery::mock(Update::class . '[call]');
        $command->setLaravel($this->app);
        $command->shouldNotReceive('call');
        $output = new BufferedOutput();

        try {
            try {
                $command->run(new ArrayInput(['--scopes' => true]), $output);
                $this->fail('Invalid scope sources must stop the update.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    str_replace(':path', 'scopes/' . $source . '.json', $expectedMessage),
                    $exception->getMessage()
                );
            }

            $this->assertStringNotContainsString('Config cache updated', $output->fetch());
        } finally {
            $files->deleteDirectory($directory);
        }
    }
}
