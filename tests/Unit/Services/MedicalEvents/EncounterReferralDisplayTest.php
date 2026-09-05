<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Services\MedicalEvents\EncounterReferralDisplay;
use Tests\TestCase;

class EncounterReferralDisplayTest extends TestCase
{
    public function test_prefers_paper_requisition(): void
    {
        $label = EncounterReferralDisplay::label([
            'paperReferral' => ['requisition' => 'PAPER-1'],
            'incomingReferral' => ['identifier' => ['value' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']],
        ]);

        $this->assertSame('PAPER-1', $label);
    }

    public function test_uses_electronic_display_value_over_uuid(): void
    {
        $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $label = EncounterReferralDisplay::label([
            'incomingReferral' => [
                'identifier' => ['value' => $uuid],
                'displayValue' => '0000-AAAA-BBBB-CCCC',
            ],
        ]);

        $this->assertSame('0000-AAAA-BBBB-CCCC', $label);
    }

    public function test_falls_back_to_local_request_number_for_electronic_uuid(): void
    {
        $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $label = EncounterReferralDisplay::label(
            ['incomingReferral' => ['identifier' => ['value' => $uuid]]],
            [$uuid => '0000-70K0-6MTX-K8M8']
        );

        $this->assertSame('0000-70K0-6MTX-K8M8', $label);
    }

    public function test_dash_when_no_referral(): void
    {
        $this->assertSame('-', EncounterReferralDisplay::label([]));
    }
}
