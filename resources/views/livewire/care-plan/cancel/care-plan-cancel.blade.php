<section class="section-form px-4 py-8">
    <x-header-navigation class="breadcrumb-form mb-6">
        <x-slot name="title">Скасування плану лікування</x-slot>
    </x-header-navigation>

    <a href="{{ route('care-plans.show', [legalEntity(), $carePlan->id]) }}" class="button-minor mb-6 inline-flex" wire:navigate>{{ __('forms.back') }}</a>

    @include('livewire.care-plan.parts.modals.cancel-care-plan-modal', ['method' => 'sign'])
    @include('components.signature-modal', ['method' => 'sign'])

    <livewire:components.x-message :key="time()" />
</section>
