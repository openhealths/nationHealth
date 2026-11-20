@php
    $canCreate = $this->canManageActivities
        && in_array(\App\Enums\CarePlanStatus::normalize($carePlan->status), ['active', 'new', 'pending'], true);
    $drawer = match ($kind) {
        'service_request' => 'showServiceDrawer',
        'medication_request' => 'showMedicationDrawer',
        'device_request' => 'showMedicalDeviceDrawer',
    };
@endphp
<button type="button" @if ($menuItem) role="menuitem" @else class="care-plan-add mt-4" @endif
    @if ($canCreate)
        @click="openDropdown = false; activeTab = 'activities'; {{ $drawer }} = true"
        wire:click="initActivityForm('{{ $kind }}')"
    @else
        disabled title="{{ $activityCreationBlockReason ?? __('care-plan.lifecycle_action_unavailable') }}"
    @endif
>
    {{ $menuItem ? '' : '+ ' }}{{ __('care-plan.'.$label) }}
</button>
