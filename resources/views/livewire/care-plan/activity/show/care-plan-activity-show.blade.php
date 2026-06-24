<section class="section-form">
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">
            Призначення — план №{{ $carePlan->requisition ?? $carePlan->id }}
        </x-slot>
    </x-header-navigation>

    @php
        $kindValue = '';
        if ($activity->kindConcept) {
            $kindValue = $activity->kindConcept->coding->first()?->code ?? $activity->kindConcept->text ?? '';
        } elseif (is_array($activity->kind)) {
            $kindValue = $activity->kind['coding'][0]['code'] ?? $activity->kind['text'] ?? '';
        } else {
            $kindValue = $activity->kind ?? '';
        }

        $activityStatus = is_array($activity->status) ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? '')) : $activity->status;
    @endphp

    <div x-data="{
        showEPrescriptionDrawer: @entangle('showEPrescriptionDrawer'),
        showReferralDrawer: @entangle('showReferralDrawer'),
    }"
    @close-drawers.window="showEPrescriptionDrawer = false; showReferralDrawer = false;"
    class="form shift-content px-4 space-y-6">

        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('care-plans.show', [legalEntity(), $carePlan->id]) }}" class="button-minor" wire:navigate>
                @icon('arrow-left', 'w-4 h-4')
                <span>{{ __('forms.back') }}</span>
            </a>

            @if(in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']))
                <a href="{{ route('care-plans.activities.edit', [legalEntity(), $carePlan->id, $activity->id]) }}" class="button-minor" wire:navigate>Редагувати</a>
                <button type="button" class="button-primary-outline" wire:click="openSignatureModal('sign_activity', {{ $activity->id }})">Підписати призначення</button>
            @elseif(in_array(strtoupper($activityStatus), ['ACTIVE', 'SCHEDULED', 'IN-PROGRESS', 'IN_PROGRESS', 'ON-HOLD', 'PROCESSED']))
                @if(str_contains(strtolower($kindValue), 'medication'))
                    <button type="button" class="button-primary" wire:click="initEPrescriptionForm({{ $activity->id }})">Виписати Е-Рецепт</button>
                @endif
                @if(str_contains(strtolower($kindValue), 'service_request') || str_contains(strtolower($kindValue), 'device_request'))
                    <button type="button" class="button-primary" wire:click="initReferralForm({{ $activity->id }})">Створити направлення</button>
                @endif
                <button type="button" class="button-minor" wire:click="openSignatureModal('complete_activity', {{ $activity->id }})">Завершити</button>
                <button type="button" class="button-minor text-red-500 border-red-200" wire:click="openSignatureModal('cancel_activity', {{ $activity->id }})">Скасувати</button>
            @endif
        </div>

        @include('livewire.care-plan.parts.activity.detail-card')

        @include('livewire.care-plan.parts.activity.prescriptions-list')

        @include('livewire.care-plan.parts.activity.referrals-list')

        @if($actionType === 'cancel_activity')
            @include('livewire.care-plan.parts.modals.cancel-activity-modal', ['method' => 'sign'])
        @elseif($actionType === 'complete_activity')
            @include('livewire.care-plan.parts.modals.complete-activity-modal', ['method' => 'sign'])
        @else
            @include('components.signature-modal', ['method' => 'sign'])
        @endif

        @include('livewire.care-plan.parts.modals.eprescription-form-drawer')
        @include('livewire.care-plan.parts.modals.referral-form-drawer')
    </div>

    <livewire:components.x-message :key="time()" />
</section>
