<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dictionary;

use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BasicDictionaryCollectionTest extends TestCase
{
    #[Test]
    public function by_name_finds_unprefixed_medication_request_reject_reason(): void
    {
        $basics = new BasicDictionaryCollection([
            [
                'name' => 'MEDICATION_REQUEST_REJECT_REASON',
                'values' => [
                    ['code' => 'OTHER', 'description' => 'Інша причина'],
                ],
            ],
        ]);

        $this->assertSame(
            ['OTHER' => 'Інша причина'],
            $basics->byName('MEDICATION_REQUEST_REJECT_REASON')->asCodeDescription()->toArray()
        );
    }

    #[Test]
    public function by_name_throws_for_incorrect_ehealth_prefixed_reject_reason(): void
    {
        $basics = new BasicDictionaryCollection([
            [
                'name' => 'MEDICATION_REQUEST_REJECT_REASON',
                'values' => [
                    ['code' => 'OTHER', 'description' => 'Інша причина'],
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Dictionary 'eHealth/MEDICATION_REQUEST_REJECT_REASON' not found");

        $basics->byName('eHealth/MEDICATION_REQUEST_REJECT_REASON');
    }
}
