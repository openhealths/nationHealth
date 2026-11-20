@use('App\Enums\CarePlanStatus')
@use('Illuminate\Support\Facades\Lang')

@php
    $displayedActivities = $carePlan->activities ?? collect();
    $recordsById = collect($availableConditions ?? [])->concat($availableReports ?? [])->concat($availableObservations ?? [])->keyBy('uuid');
@endphp
<div x-show="activeTab === 'activities'" x-cloak x-data="{ query: '', appliedQuery: '', statusFilter: '', appliedStatus: '', moreFilters: false }">
    @if ($displayedActivities->isNotEmpty())
    <div class="care-plan-activity-filters">
        <div class="form-group group max-w-sm">
            <span class="svg-input">@icon('search', 'w-4 h-4')</span>
            <input id="activity-search" type="search" class="input peer" placeholder=" " x-model="query" @keydown.enter.prevent="appliedQuery = query; appliedStatus = statusFilter" />
            <label for="activity-search" class="label">{{ __('care-plan.activity_search_id') }}</label>
        </div>
        <div class="mt-5 flex flex-wrap gap-3">
            <button type="button" class="button-primary flex items-center gap-2" @click="appliedQuery = query; appliedStatus = statusFilter">@icon('search', 'w-4 h-4') {{ __('forms.search') }}</button>
            <button type="button" class="button-primary-outline-red" @click="query = ''; appliedQuery = ''; statusFilter = ''; appliedStatus = ''">{{ __('forms.reset_all_filters') }}</button>
            <button type="button" class="button-minor flex items-center gap-2" @click="moreFilters = !moreFilters" :aria-expanded="moreFilters">@icon('adjustments', 'w-4 h-4') {{ __('care-plan.additional_search_parameters') }}</button>
        </div>
        <div x-show="moreFilters" x-cloak class="form-group group mt-6 max-w-sm">
            <select id="activity-status-filter" class="input-select peer" x-model="statusFilter">
                <option value="">{{ __('care-plan.all_statuses') }}</option>
                @foreach ($displayedActivities->map(fn ($item) => CarePlanStatus::normalize($item->status))->filter()->unique()->sort() as $filterStatus)
                    <option value="{{ $filterStatus }}">{{ Lang::has('care-plan.activity_card_status.'.$filterStatus) ? __('care-plan.activity_card_status.'.$filterStatus) : (Lang::has('care-plan.status.'.str_replace('-', '_', $filterStatus)) ? __('care-plan.status.'.str_replace('-', '_', $filterStatus)) : $filterStatus) }}</option>
                @endforeach
            </select>
            <label for="activity-status-filter" class="label">{{ __('care-plan.status_label') }}</label>
        </div>
    </div>
    @endif

    @foreach (['service_request' => 'services', 'medication_request' => 'medication_products', 'device_request' => 'medical_devices'] as $sectionKind => $sectionLabel)
        @php
            $sectionActivities = $displayedActivities->filter(fn ($item) => $item->resolvedKind() === $sectionKind);
        @endphp
        <fieldset class="care-plan-activity-section" wire:key="activity-section-{{ $sectionKind }}">
            <legend>{{ __('care-plan.'.($sectionKind === 'medication_request' && $sectionActivities->isEmpty() ? 'medications' : $sectionLabel)) }}</legend>
            @if ($sectionActivities->isNotEmpty())
                <div class="care-plan-activity-cards">
                    @foreach ($sectionActivities as $activity)
                        @php
                            $product = $activity->relationLoaded('productReferenceIdentifier') ? $activity->productReferenceIdentifier?->displayValue : null;
                            $product ??= $activity->relationLoaded('productConcept') ? $activity->productConcept?->text : null;
                            $product ??= $activity->productCodeableConcept ?: $activity->productReference;
                            $grounds = collect($activity->reasonReference ?? [])
                                ->map(fn ($ref) => is_string($ref) ? $ref : ($ref['reference'] ?? $ref['identifier']['value'] ?? null))
                                ->filter()->map(fn ($ref) => $recordsById[basename($ref)]['name'] ?? $ref)->values()->all();
                        @endphp
                        <div wire:key="activity-card-{{ $activity->id }}"
                            x-show="(!appliedQuery || @js(($activity->uuid ?? '').' '.($product ?? '').' '.$activity->id).toLowerCase().includes(appliedQuery.toLowerCase())) && (!appliedStatus || appliedStatus === @js(CarePlanStatus::normalize($activity->status)))">
                            @include('livewire.care-plan.parts.activity.detail-card', [
                                'compact' => true,
                                'activityProductLabel' => $product,
                                'activityReasonReferenceLabels' => $grounds,
                            ])
                        </div>
                    @endforeach
                    <p x-show="appliedQuery || appliedStatus" class="text-sm text-gray-500">{{ __('care-plan.activity_filters_applied') }}</p>
                </div>
            @endif
            @if ($sectionActivities->isEmpty())
            <div>
                <div class="care-plan-activity-empty" role="status">@icon('info-circle', 'w-4 h-4') {{ __('care-plan.no_prescriptions_yet') }}</div>
            </div>
            @endif
            @include('livewire.care-plan.parts.activity.create-button', [
                'menuItem' => false, 'kind' => $sectionKind,
                'label' => match ($sectionKind) { 'service_request' => 'add_services', 'medication_request' => 'add_medications', 'device_request' => 'add_medical_devices' },
            ])
        </fieldset>
    @endforeach

    <div class="mt-10 flex flex-wrap gap-4 pb-12">
        <a href="{{ route('persons.care-plans', [legalEntity(), $carePlan->person_id]) }}" class="button-minor" wire:navigate>{{ __('forms.cancel') }}</a>
        <button type="button" class="button-primary-outline-red disabled:opacity-50 disabled:cursor-not-allowed" wire:click="openSignatureModal('cancel')" @disabled(!$this->canCancelPlan) title="{{ !$this->canCancelPlan ? ($activityCreationBlockReason ?? __('care-plan.lifecycle_action_unavailable')) : '' }}">{{ __('care-plan.cancel_care_plan') }}</button>
        <button type="button" class="button-primary disabled:opacity-50 disabled:cursor-not-allowed" wire:click="openSignatureModal('complete')" @disabled(!$this->canCompletePlan) title="{{ !$this->canCompletePlan ? ($activityCreationBlockReason ?? __('care-plan.lifecycle_action_unavailable')) : '' }}">{{ __('care-plan.complete_care_plan') }}</button>
    </div>
</div>
