<?php

declare(strict_types=1);

namespace Tests\Feature\Declaration;

use App\Models\DeclarationRequest;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\PostgresConnection;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DeclarationEditBindingTest extends TestCase
{
    private ConnectionResolverInterface $originalResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalResolver = Model::getConnectionResolver();
        // Exercise real Eloquent queries without connecting to a database.
        $connection = new class (
            fn () => throw new RuntimeException('Binding fixtures must not connect to a database.'),
            'testing'
        ) extends PostgresConnection
        {
            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
            {
                preg_match('/from "([a-z_]+)"/', $query, $table);
                $rows = match ($table[1] ?? '') {
                    'persons' => [['id' => 11], ['id' => 12]],
                    'declaration_requests' => [
                        ['id' => 101, 'person_id' => 11],
                        ['id' => 102, 'person_id' => 12],
                    ],
                    default => throw new RuntimeException('Unexpected fixture query: ' . $query),
                };
                preg_match_all('/(?:"[a-z_]+"\.)?"([a-z_]+)" = \?/', $query, $conditions);
                if (count($bindings) !== count($conditions[1])) {
                    throw new RuntimeException('Every query binding must be checked.');
                }

                return array_values(array_map(static fn (array $row): object => (object) $row, array_filter(
                    $rows,
                    static function (array $row) use ($conditions, $bindings): bool {
                        foreach ($conditions[1] as $index => $column) {
                            if ((string) ($row[$column] ?? '') !== (string) $bindings[$index]) {
                                return false;
                            }
                        }

                        return true;
                    }
                )));
            }
        };
        $resolver = new ConnectionResolver(['pgsql' => $connection]);
        $resolver->setDefaultConnection('pgsql');
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        Model::setConnectionResolver($this->originalResolver);

        parent::tearDown();
    }

    public static function declarationRequests(): array
    {
        return [
            'own request' => [11, 101, true],
            'another patient request' => [11, 102, false],
            'missing request' => [11, 999, false],
            'missing patient' => [999, 101, false],
        ];
    }

    #[DataProvider('declarationRequests')]
    public function test_edit_route_only_binds_the_patients_request(int $personId, int $requestId, bool $exists): void
    {
        $route = clone app('router')->getRoutes()->getByName('declaration.edit');
        $route->bind(Request::create(route('declaration.edit', [
            'legalEntity' => 1,
            'person' => $personId,
            'declarationRequest' => $requestId,
        ])));
        // RouteServiceProvider explicitly binds the legal entity before implicit bindings run.
        $route->setParameter('legalEntity', new LegalEntity(['id' => 1]));

        if (!$exists) {
            $this->expectException(ModelNotFoundException::class);
        }

        app('router')->substituteImplicitBindings($route);

        if (!$exists) {
            return;
        }

        $this->assertInstanceOf(Person::class, $route->parameter('person'));
        $this->assertInstanceOf(DeclarationRequest::class, $route->parameter('declarationRequest'));
        $this->assertSame($personId, $route->parameter('declarationRequest')->personId);
        $this->assertSame($requestId, $route->parameter('declarationRequest')->id);
        $this->assertContains('can:update,declarationRequest', $route->gatherMiddleware());
    }
}
