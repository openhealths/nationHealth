@use('App\Livewire\CarePlan\CarePlanActivityCreate')

<section class="section-form">
    <x-header-navigation x-data="{ showFilter: false }" class="breadcrumb-form">
        <x-slot name="title">
            {{ __('care-plan.new_activity') }}
        </x-slot>
    </x-header-navigation>

    <div x-data="{ showSignatureModal: $wire.entangle('showSignatureModal').live }" class="form shift-content" wire:key="{{ time() }}">
        <div class="row align-items-center mb-4">
            <h2 class="title">{{ __('care-plan.activity_details') }}</h2>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <x-forms.select 
                    id="activity_kind" 
                    name="form.kind" 
                    label="{{ __('care-plan.kind') }}" 
                    wire:model="form.kind">
                    <option value="medication_request">{{ __('care-plan.medication_request') }}</option>
                    <option value="service_request">{{ __('care-plan.service_request') }}</option>
                    <option value="device_request">{{ __('care-plan.device_request') }}</option>
                </x-forms.select>
            </div>
            
            <div class="col-md-6 mb-3">
                <x-forms.input 
                    id="period_start" 
                    name="form.scheduled_period_start" 
                    label="{{ __('forms.start_date') }}" 
                    wire:model="form.scheduled_period_start" 
                    isDatePicker="true" 
                    placeholder="ДД.ММ.РРРР"/>
            </div>

            <div class="col-md-6 mb-3">
                <x-forms.input 
                    id="period_end" 
                    name="form.scheduled_period_end" 
                    label="{{ __('forms.end_date') }}" 
                    wire:model="form.scheduled_period_end" 
                    isDatePicker="true" 
                    placeholder="ДД.ММ.РРРР"/>
            </div>

            <div class="col-md-6 mb-3">
                <x-forms.input 
                    id="quantity" 
                    name="form.quantity" 
                    label="{{ __('care-plan.quantity') }}" 
                    wire:model="form.quantity" />
            </div>

            <div class="col-12 mb-3">
                <x-forms.textarea 
                    id="description" 
                    name="form.description" 
                    label="{{ __('forms.description') }}" 
                    wire:model="form.description" />
            </div>
        </div>

        <div class="mt-6 flex flex-row items-center gap-4 pt-6">
            <div class="flex items-center space-x-3">
                <button type="button"
                        class="button-primary-outline flex items-center gap-2 px-4 py-2"
                        wire:click="save"
                >
                    @icon('archive', 'w-4 h-4')
                    {{ __('forms.save') }}
                </button>

                <button type="button" @click="showSignatureModal = true" class="button-primary">
                    {{ __('forms.sign_with_KEP') }}
                </button>
            </div>
        </div>

        @include('components.signature-modal', ['method' => 'sign'])
    </div>

    <x-messages/>
    <x-forms.loading/>
</section>
