<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Classes\eHealth\Api\Dictionary;
use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

trait RefreshCompositionDatabase
{
    use RefreshDatabase;
    use UsesCompositionDictionaries;

    protected function migrateDatabases(): void
    {
        $dictionary = Mockery::mock(Dictionary::class);
        $dictionary->shouldReceive('getMany')->andReturn(new EHealthResponse(new Response(200, [], json_encode([
            'data' => [['name' => 'eHealth/ICD10_AM/condition_codes', 'values' => []]],
        ]))));
        $this->app->instance(Dictionary::class, $dictionary);

        try {
            $this->artisan('migrate:fresh', [
                '--path' => [database_path('migrations/install'), database_path('migrations')],
                '--realpath' => true,
            ])->assertSuccessful();
        } finally {
            $this->app->forgetInstance(Dictionary::class);
        }
    }
}
