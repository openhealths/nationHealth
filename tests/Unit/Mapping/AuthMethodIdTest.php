<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Mapping\Transforms\AuthMethodId;
use Tests\TestCase;

class AuthMethodIdTest extends TestCase
{
    public function test_extracts_id_from_pipe_encoded_form_value(): void
    {
        $this->assertSame(
            'auth-1',
            AuthMethodId::extract('auth-1|OTP|+380501112233')
        );
    }

    public function test_maps_care_plan_style_auth_method_object(): void
    {
        $this->assertSame(
            'auth-2',
            AuthMethodId::extract(['auth_method_id' => 'auth-2'])
        );
    }

    public function test_returns_null_for_empty_values(): void
    {
        $this->assertNull(AuthMethodId::extract(null));
        $this->assertNull(AuthMethodId::extract(''));
        $this->assertNull(AuthMethodId::extract([]));
    }

    public function test_form_value_falls_back_to_uuid_when_raw_is_missing(): void
    {
        $this->assertSame(
            'auth-3',
            $this->formValue(['uuid' => 'auth-3', 'type' => '', 'label' => 'OTP'])
        );
        $this->assertSame(
            'auth-4|OTP|+380501112233',
            $this->formValue(['raw' => 'auth-4|OTP|+380501112233'])
        );
        $this->assertSame('', $this->formValue([]));
    }
    private function formValue(array $method): string
    {
        $component = new \App\Livewire\Encounter\EncounterEdit();

        return (new \ReflectionMethod($component, 'authenticationMethodFormValue'))->invoke($component, $method);
    }
}
