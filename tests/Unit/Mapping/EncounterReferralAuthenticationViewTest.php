<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use Illuminate\Support\ViewErrorBag;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class EncounterReferralAuthenticationViewTest extends TestCase
{
    public function test_authentication_options_render_prepared_values_without_a_service(): void
    {
        // The service catalog is independent of authentication option rendering.
        $catalog = new class extends Component
        {
            public function render(): string
            {
                return '<div></div>';
            }
        };
        Livewire::component('dictionary.service-catalog', $catalog::class);

        $html = view('livewire.encounter.parts.encounter-referral-drawer', [
            'errors' => new ViewErrorBag(),
            'showEncounterReferralDrawer' => true,
            'encounterReferralHasSearched' => false,
            'encounterReferralPrograms' => [],
            'encounterReferralSelectedService' => null,
            'encounterReferralServiceResults' => [],
            'encounterReferralWarningMessage' => '',
            'encounterReferralAuthMethods' => [
                ['uuid' => 'auth-1', 'raw' => 'auth-1|OTP|+380501112233', 'label' => 'OTP · +380501112233'],
                ['uuid' => 'auth-legacy', 'label' => 'Legacy'],
            ],
        ])->render();

        $this->assertStringContainsString('value="auth-1|OTP|+380501112233"', $html);
        $this->assertStringContainsString('value="auth-legacy"', $html);
    }
}
