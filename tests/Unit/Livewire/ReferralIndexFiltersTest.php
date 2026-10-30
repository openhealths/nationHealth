<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire;

use App\Livewire\Referral\ReferralIndex;
use PHPUnit\Framework\TestCase;

class ReferralIndexFiltersTest extends TestCase
{
    public function test_patient_filter_matches_ukrainian_names_and_subject_display(): void
    {
        $component = new ReferralIndex();
        $component->searchResults = [
            ['id' => 'first', 'identity' => ['last_name' => 'Шевченко', 'first_name' => 'Тарас']],
            ['id' => 'second', 'subject' => ['display' => 'Шевченко Т. Г.']],
            ['id' => 'other', 'identity' => ['last_name' => 'Коваль']],
        ];
        $component->patient = '  ШЕВЧЕНКО  ';

        $this->assertSame(['first', 'second'], array_column($component->filteredReferrals(), 'id'));
        $this->assertCount(3, $component->searchResults);
    }

    public function test_status_filter_includes_legacy_aliases_and_can_be_cleared(): void
    {
        $component = new ReferralIndex();
        $component->searchResults = [
            ['id' => 'error', 'status' => 'entered-in-error'],
            ['id' => 'recalled', 'status' => 'recalled'],
            ['id' => 'active', 'status' => 'active'],
        ];
        $component->status = ['entered_in_error', 'revoked'];

        $this->assertSame(['error', 'recalled'], array_column($component->filteredReferrals(), 'id'));

        $component->status = [];
        $this->assertCount(3, $component->filteredReferrals());
    }

    public function test_work_filter_matches_program_state_and_combines_with_patient(): void
    {
        $component = new ReferralIndex();
        $component->searchResults = [
            ['id' => 'program', 'status' => 'active', 'program_processing_status' => 'in_progress', 'subject' => ['display' => 'Шевченко']],
            ['id' => 'work', 'status' => 'in_progress', 'subject' => ['display' => 'Коваль']],
            ['id' => 'new', 'status' => 'active', 'program_processing_status' => 'new', 'subject' => ['display' => 'Шевченко']],
            ['id' => 'completed', 'status' => 'completed', 'program_processing_status' => 'in_progress', 'subject' => ['display' => 'Шевченко']],
        ];
        $component->status = ['in_progress'];
        $component->patient = 'Шевченко';

        $this->assertSame(['program'], array_column($component->filteredReferrals(), 'id'));
    }
}
