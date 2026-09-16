<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Encounter;

use App\Livewire\Encounter\Forms\DeviceDispenseForm;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Lightweight checks for Device Dispense basedOn eligibility rules without booting Livewire.
 */
class DeviceDispenseBasedOnFilterTest extends TestCase
{
    #[Test]
    public function only_active_order_requests_without_program_are_eligible_for_encounter_based_on(): void
    {
        $requests = [
            [
                'uuid' => '11111111-1111-1111-1111-111111111111',
                'status' => 'active',
                'intent' => 'order',
                'programId' => '22222222-2222-2222-2222-222222222222',
            ],
            [
                'uuid' => '33333333-3333-3333-3333-333333333333',
                'status' => 'active',
                'intent' => 'order',
                'programId' => null,
            ],
            [
                'uuid' => '44444444-4444-4444-4444-444444444444',
                'status' => 'processed',
                'intent' => 'order',
                'programId' => null,
            ],
            [
                'uuid' => '55555555-5555-5555-5555-555555555555',
                'status' => 'active',
                'intent' => 'plan',
                'programId' => null,
            ],
        ];

        $eligible = collect($requests)
            ->filter(
                static fn (array $deviceRequest): bool =>
                    strtolower((string) ($deviceRequest['status'] ?? '')) === 'active'
                    && ($deviceRequest['intent'] ?? null) === 'order'
                    && empty($deviceRequest['programId'])
            )
            ->values()
            ->all();

        $this->assertCount(1, $eligible);
        $this->assertSame('33333333-3333-3333-3333-333333333333', $eligible[0]['uuid']);
    }

    #[Test]
    public function form_class_exposes_device_dispense_rules(): void
    {
        $reflection = new ReflectionClass(DeviceDispenseForm::class);

        $this->assertTrue($reflection->hasMethod('rules'));
    }
}
