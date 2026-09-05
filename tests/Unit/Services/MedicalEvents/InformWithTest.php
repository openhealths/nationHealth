<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Services\MedicalEvents\InformWith;
use Tests\TestCase;

class InformWithTest extends TestCase
{
    public function test_extracts_id_from_pipe_encoded_form_value(): void
    {
        $this->assertSame(
            'auth-1',
            InformWith::authMethodId('auth-1|OTP|+380501112233')
        );
    }

    public function test_maps_care_plan_style_auth_method_object(): void
    {
        $this->assertSame(
            'auth-2',
            InformWith::authMethodId(['auth_method_id' => 'auth-2'])
        );
    }

    public function test_returns_null_for_empty_values(): void
    {
        $this->assertNull(InformWith::authMethodId(null));
        $this->assertNull(InformWith::authMethodId(''));
        $this->assertNull(InformWith::authMethodId([]));
    }

    public function test_form_value_falls_back_to_uuid_when_raw_is_missing(): void
    {
        $this->assertSame(
            'auth-3',
            InformWith::formValue(['uuid' => 'auth-3', 'type' => '', 'label' => 'OTP'])
        );
        $this->assertSame(
            'auth-4|OTP|+380501112233',
            InformWith::formValue(['raw' => 'auth-4|OTP|+380501112233'])
        );
        $this->assertSame('', InformWith::formValue([]));
    }
}
