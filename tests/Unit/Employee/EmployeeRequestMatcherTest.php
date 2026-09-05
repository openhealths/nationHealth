<?php

declare(strict_types=1);

namespace Tests\Unit\Employee;

use App\Enums\Employee\RequestStatus;
use App\Models\Employee\EmployeeRequest;
use App\Services\Employee\EmployeeRequestMatcher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeRequestMatcherTest extends TestCase
{
    #[Test]
    public function is_remote_still_pending_covers_new_and_legacy_signed(): void
    {
        $this->assertTrue(EmployeeRequestMatcher::isRemoteStillPending('NEW'));
        $this->assertTrue(EmployeeRequestMatcher::isRemoteStillPending('SIGNED'));
        $this->assertTrue(EmployeeRequestMatcher::isRemoteStillPending(RequestStatus::SIGNED));
        $this->assertFalse(EmployeeRequestMatcher::isRemoteStillPending('APPROVED'));
        $this->assertFalse(EmployeeRequestMatcher::isRemoteStillPending('REJECTED'));
        $this->assertFalse(EmployeeRequestMatcher::isRemoteStillPending(null));
    }

    #[Test]
    public function dates_match_same_day_across_formats(): void
    {
        $this->assertTrue(EmployeeRequestMatcher::datesMatchSameDay('2024-03-15', '15.03.2024'));
        $this->assertTrue(EmployeeRequestMatcher::datesMatchSameDay('2024-03-15T10:00:00Z', '2024-03-15'));
        $this->assertFalse(EmployeeRequestMatcher::datesMatchSameDay('2024-03-15', '2024-03-16'));
        $this->assertFalse(EmployeeRequestMatcher::datesMatchSameDay(null, '2024-03-15'));
        $this->assertFalse(EmployeeRequestMatcher::datesMatchSameDay('2024-03-15', ''));
    }

    #[Test]
    public function pick_from_approved_list_requires_position_type_and_date(): void
    {
        $matcher = new EmployeeRequestMatcher();
        $request = new EmployeeRequest([
            'position' => 'P1',
            'employee_type' => 'DOCTOR',
            'start_date' => '2024-01-10',
        ]);

        $match = $matcher->pickFromApprovedList($request, [
            [
                'uuid' => 'a-1',
                'position' => 'P1',
                'employee_type' => 'DOCTOR',
                'start_date' => '2024-01-11',
            ],
            [
                'uuid' => 'a-2',
                'position' => 'P1',
                'employee_type' => 'DOCTOR',
                'start_date' => '2024-01-10',
            ],
        ]);

        $this->assertSame('a-2', $match['uuid'] ?? null);
    }

    #[Test]
    public function pick_from_approved_list_fuzzy_when_only_one_candidate(): void
    {
        $matcher = new EmployeeRequestMatcher();
        $request = new EmployeeRequest([
            'position' => 'OTHER',
            'employee_type' => 'DOCTOR',
            'start_date' => '2024-01-10',
        ]);

        $match = $matcher->pickFromApprovedList($request, [
            [
                'uuid' => 'only-one',
                'position' => 'P1',
                'employee_type' => 'NURSE',
                'start_date' => '2020-01-01',
            ],
        ]);

        $this->assertSame('only-one', $match['uuid'] ?? null);
    }

    #[Test]
    public function pick_from_approved_list_returns_null_when_ambiguous_without_match(): void
    {
        $matcher = new EmployeeRequestMatcher();
        $request = new EmployeeRequest([
            'position' => 'P1',
            'employee_type' => 'DOCTOR',
            'start_date' => '2024-01-10',
        ]);

        $match = $matcher->pickFromApprovedList($request, [
            [
                'uuid' => 'a-1',
                'position' => 'X',
                'employee_type' => 'DOCTOR',
                'start_date' => '2024-01-10',
            ],
            [
                'uuid' => 'a-2',
                'position' => 'Y',
                'employee_type' => 'DOCTOR',
                'start_date' => '2024-01-10',
            ],
        ]);

        $this->assertNull($match);
    }
}
