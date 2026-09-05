<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Listeners\eHealth\EmployeeCreate;
use App\Models\Employee\EmployeeRequest;
use App\Models\Revision;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeCreateTaxIdCollectionTest extends TestCase
{
    #[Test]
    public function collect_tax_ids_includes_every_pending_request_not_only_the_first(): void
    {
        $listener = new EmployeeCreate();
        $method = new ReflectionMethod(EmployeeCreate::class, 'collectTaxIds');

        $first = new EmployeeRequest();
        $first->setRelation('revision', new Revision([
            'data' => ['party' => ['tax_id' => '3978213781']],
        ]));

        $second = new EmployeeRequest();
        $second->setRelation('revision', new Revision([
            'data' => ['party' => ['tax_id' => '3461807396']],
        ]));

        /** @var Collection<int, string> $taxIds */
        $taxIds = $method->invoke($listener, collect([$first, $second]));

        $this->assertSame(['3978213781', '3461807396'], $taxIds->all());
    }

    #[Test]
    public function collect_tax_ids_skips_empty_and_duplicates(): void
    {
        $listener = new EmployeeCreate();
        $method = new ReflectionMethod(EmployeeCreate::class, 'collectTaxIds');

        $withTax = new EmployeeRequest();
        $withTax->setRelation('revision', new Revision([
            'data' => ['party' => ['tax_id' => '1111111111']],
        ]));

        $duplicate = new EmployeeRequest();
        $duplicate->setRelation('revision', new Revision([
            'data' => ['party' => ['tax_id' => '1111111111']],
        ]));

        $empty = new EmployeeRequest();
        $empty->setRelation('revision', new Revision([
            'data' => ['party' => ['tax_id' => '']],
        ]));

        /** @var Collection<int, string> $taxIds */
        $taxIds = $method->invoke($listener, collect([$withTax, $duplicate, $empty]));

        $this->assertSame(['1111111111'], $taxIds->all());
    }
}
