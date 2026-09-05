<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Activity\Show;

use App\Classes\eHealth\EHealth;
use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanEPrescription;
use App\Livewire\CarePlan\Concerns\CarePlanManager;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanReferrals;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;

class CarePlanActivityShow extends CarePlanComponent
{
    use ManagesCarePlanEPrescription;
    use CarePlanManager;
    use ManagesCarePlanReferrals;

    #[Locked]
    public CarePlanActivity $activity;

    public string $activityProductLabel = '';

    public function mount(CarePlan $carePlan, CarePlanActivity $activity): void
    {
        $this->bootCarePlan($carePlan);

        $this->activity = $activity->load(['kindConcept.coding', 'reasonReferences', 'author.party']);
        $this->activityProductLabel = $this->resolveActivityProductLabel($activity);
        $this->scopeDocumentsToActivity($activity->id);
    }

    protected function renderCarePlan()
    {
        $this->carePlan->load(['person', 'author.party', 'categoryConcept']);

        return view('livewire.care-plan.activity.show.care-plan-activity-show');
    }

    protected function getDeviceSignReadinessWarning(CarePlanActivity $activity): ?string
    {
        // Validation delegated to eHealth API during signing to avoid overengineering guard checks.
        return null;
    }

    protected function resolveActivityProductLabel(CarePlanActivity $activity): string
    {
        $kindLower = strtolower($activity->resolvedKind());

        if (str_contains($kindLower, 'device') && !empty($activity->productReference)) {
            try {
                $reference = (string) $activity->productReference;
                $device = dictionary()->deviceDefinitions()->first(
                    fn (array $item): bool => (string) ($item['id'] ?? $item['uuid'] ?? '') === $reference
                );

                if (is_array($device)) {
                    return (string) ($device['device_names'][0]['name']
                        ?? $device['name']
                        ?? $device['model_number']
                        ?? $reference);
                }
            } catch (\Exception $exception) {
                Log::warning('CarePlanActivityShow: failed to resolve device label: ' . $exception->getMessage());
            }

            return (string) $activity->productReference;
        }

        if (str_contains($kindLower, 'medication') && !empty($activity->productReference)) {
            return (string) $activity->productReference;
        }

        if (str_contains($kindLower, 'service') && !empty($activity->productReference)) {
            return (string) $activity->productReference;
        }

        if (!empty($activity->productCodeableConcept)) {
            return $this->dictionaries['device_definition_classification_type'][$activity->productCodeableConcept]
                ?? (string) $activity->productCodeableConcept;
        }

        return '';
    }
}
