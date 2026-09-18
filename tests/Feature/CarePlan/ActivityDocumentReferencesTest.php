<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Models\CarePlanActivity;
use Tests\TestCase;

class ActivityDocumentReferencesTest extends TestCase
{
    public function test_activity_lists_only_documents_with_its_identifier_uuid(): void
    {
        $activity = (new CarePlanActivity())->forceFill(['id' => 42, 'uuid' => 'activity-uuid']);
        $documents = [
            ['uuid' => 'own-document', 'based_on_uuid' => 'activity-uuid', 'based_on_id' => 99, 'request_number' => 'OWN-REF', 'status' => 'completed'],
            ['uuid' => 'other-document', 'based_on_uuid' => 'other-uuid', 'based_on_id' => 42, 'request_number' => 'OTHER-REF', 'status' => 'completed'],
        ];

        foreach (['prescriptions' => 'activePrescriptions', 'referrals' => 'activeReferrals'] as $view => $property) {
            $html = view('livewire.care-plan.parts.activity.'.$view.'-list', [
                'activity' => $activity,
                $property => $documents,
            ])->render();

            $this->assertStringContainsString('OWN-REF', $html);
            $this->assertStringNotContainsString('OTHER-REF', $html);
        }
    }
}
