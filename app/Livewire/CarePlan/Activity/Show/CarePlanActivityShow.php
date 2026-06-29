<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Activity\Show;

use App\Classes\eHealth\EHealth;
use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanEPrescription;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanLifecycle;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanReferrals;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use Illuminate\Support\Facades\Log;

class CarePlanActivityShow extends CarePlanComponent
{
    use ManagesCarePlanEPrescription;
    use ManagesCarePlanLifecycle;
    use ManagesCarePlanReferrals;

    public CarePlanActivity $activity;

    public string $activityProductLabel = '';

    public function mount(CarePlan $carePlan, CarePlanActivity $activity): void
    {
        if ($activity->care_plan_id !== $carePlan->id) {
            abort(404);
        }

        $this->bootCarePlan($carePlan);

        $this->activity = $activity->load(['kindConcept.coding', 'reasonReferences']);
        $this->activityProductLabel = $this->resolveActivityProductLabel($activity);
        $this->scopeDocumentsToActivity($activity->id);
    }

    protected function renderCarePlan()
    {
        $this->carePlan->load(['person', 'author.party', 'categoryConcept']);

        return view('livewire.care-plan.activity.show.care-plan-activity-show');
    }

    protected function resolveActivityProductLabel(CarePlanActivity $activity): string
    {
        $kindLower = strtolower($activity->resolvedKind());

        if (str_contains($kindLower, 'device') && !empty($activity->product_reference)) {
            try {
                $filters = ['page_size' => 50];
                if (!empty($activity->program)) {
                    $filters['medical_program_id'] = $activity->program;
                }

                $response = EHealth::deviceDefinition()->getMany($filters);
                $reference = (string) $activity->product_reference;
                $device = collect($response->getData())->first(
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

            return (string) $activity->product_reference;
        }

        if (str_contains($kindLower, 'medication') && !empty($activity->product_reference)) {
            return (string) $activity->product_reference;
        }

        if (str_contains($kindLower, 'service') && !empty($activity->product_reference)) {
            return (string) $activity->product_reference;
        }

        if (!empty($activity->product_codeable_concept)) {
            return $this->dictionaries['device_definition_classification_type'][$activity->product_codeable_concept]
                ?? (string) $activity->product_codeable_concept;
        }

        return '';
    }
}
