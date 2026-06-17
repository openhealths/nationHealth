<div>
    <x-header-navigation class="breadcrumb-form" title="{{ __('contracts.new') }}"></x-header-navigation>

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />

    <div class="form shift-content" wire:key="{{ time() }}">
        @include('livewire.contract.parts.legal-entity-info')
        @include('livewire.contract.parts.basic-data')
        @include('livewire.contract.parts.medical-programs')
        @include('livewire.contract.parts.payment-details')
        @include('livewire.contract.parts.documents')
        @include('livewire.contract.parts.consent-text')

        <div class="mt-6 flex flex-row items-center gap-4 pt-6">
            <div class="flex items-center space-x-3">
                <a href="{{ route('contract.index', legalEntity()) }}" class="button-minor">
                    {{ __('forms.cancel') }}
                </a>

                <button type="submit"
                        class="button-primary-outline flex items-center gap-2 px-4 py-2"
                        wire:click="save"
                >
                    @icon('archive', 'w-4 h-4')
                    {{ __('forms.save') }}
                </button>

                <button type="button" wire:click="openSignatureModal" class="button-primary">
                    {{ __('forms.save_and_send') }}
                </button>
            </div>
        </div>
    </div>

    <x-signature-modal method="create" />
</div>
