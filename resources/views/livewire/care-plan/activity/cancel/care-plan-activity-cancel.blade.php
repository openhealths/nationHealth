<section class="section-form px-4 py-8">
    <x-header-navigation class="breadcrumb-form mb-6">
        <x-slot name="title">Скасування призначення</x-slot>
    </x-header-navigation>

    <a href="{{ route('care-plans.activities.show', [legalEntity(), $carePlan->id, $activity->id]) }}" class="button-minor mb-6 inline-flex" wire:navigate>{{ __('forms.back') }}</a>

    @include('livewire.care-plan.parts.modals.cancel-activity-modal', ['method' => 'sign'])
    @include('components.signature-modal', ['method' => 'sign'])

    <livewire:components.x-message :key="time()" />
</section>
