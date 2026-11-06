<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Livewire\Referral\ReferralIndex;

class ReferralIndexFixture extends ReferralIndex
{
    public function seedResults(array $rows): void
    {
        $this->searchResults = $rows;
        $this->hasSearched = true;
    }
}
