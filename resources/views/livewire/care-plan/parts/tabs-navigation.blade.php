<div class="care-plan-tabs">
    <nav class="flex" aria-label="{{ __('care-plan.care_plan') }}">
        <button type="button" @click="activeTab = 'info'" class="care-plan-tab" :class="activeTab === 'info' && 'is-active'" :aria-selected="activeTab === 'info'" role="tab">
            {{ __('care-plan.plan_info') }}
        </button>
        <button type="button" @click="activeTab = 'activities'" class="care-plan-tab" :class="activeTab === 'activities' && 'is-active'" :aria-selected="activeTab === 'activities'" role="tab">
            {{ __('care-plan.activities') }}
        </button>
    </nav>
    <div class="relative" @keydown.escape.stop="openDropdown = false" @click.outside="openDropdown = false">
        <button type="button" @click="openDropdown = !openDropdown" class="care-plan-add" :aria-expanded="openDropdown" aria-haspopup="menu">
            + {{ __('care-plan.new_prescription') }}
        </button>
        <div x-show="openDropdown" x-cloak x-transition class="care-plan-new-menu" role="menu">
            @foreach (['service_request' => 'services', 'medication_request' => 'medications', 'device_request' => 'medical_devices'] as $kind => $label)
                @include('livewire.care-plan.parts.activity.create-button', ['menuItem' => true])
            @endforeach
        </div>
    </div>
</div>
