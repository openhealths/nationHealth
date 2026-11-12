<?php

declare(strict_types=1);

use App\Models\CarePlan;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\Person\Person;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Approval::whereApprovableType(CarePlan::class)->update(['status' => null]);
        Approval::whereApprovableType(Person::class)->whereNotNull('status')
            ->with(['grantedResourceIdentifiers.type.coding', 'grantedResourceTypes.codeableConcept.coding', 'ehealthLinks.job'])
            ->chunkById(100, static function (Collection $approvals): void {
                foreach ($approvals as $approval) {
                    if ($approval->isCarePlanConsent()) {
                        $approval->update(['status' => null]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Legacy labels cannot be reconstructed from verified and expired consent.
    }
};
