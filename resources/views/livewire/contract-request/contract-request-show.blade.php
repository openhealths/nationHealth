<section class="section-form w-full max-w-7xl">
    <livewire:components.x-message :key="time()" />

    <div class="flex items-center justify-between gap-4 flex-wrap w-full">
        <x-header-navigation class="breadcrumb-form flex-1 min-w-0">
            <x-slot name="title">
                {{ __('contracts.contract_requests') }}
                @if($contractRequest->contract_number)
                    № {{ $contractRequest->contract_number }}
                @endif
            </x-slot>

            @if(is_object($contractRequest->status) && method_exists($contractRequest->status, 'label'))
                <span class="{{ $contractRequest->status->color() }} px-3 py-1 rounded-full text-xs font-bold uppercase">
                    {{ $contractRequest->status->label() }}
                </span>
            @else
                <span class="bg-gray-100 text-gray-800 px-3 py-1 rounded-full text-xs font-bold uppercase">
                    {{ (string) $contractRequest->status }}
                </span>
            @endif
        </x-header-navigation>
    </div>

    <fieldset disabled class="form shift-content space-y-8 mt-6">
        @include('livewire.contract.parts.basic-data', ['contract' => $contractRequest, 'data' => $contractData, 'idFormName' => $idFormName])
        @include('livewire.contract.parts.contractor', ['data' => $contractData])
        @include('livewire.contract.parts.nhs-customer', ['contract' => $contractRequest, 'data' => $contractData])
        @include('livewire.contract.parts.payment-details', ['contract' => $contractRequest, 'data' => $contractData])
        @include('livewire.contract.parts.divisions', ['contract' => $contractRequest, 'data' => $contractData])
        @include('livewire.contract.parts.medical-programs', [
            'contract' => $contractRequest,
            'data' => $contractData,
            'medicalProgramsList' => [],
            'medicalProgramNames' => $medicalProgramNames,
        ])
        @include('livewire.contract.parts.external-contractors-readonly', [
            'externalContractors' => data_get($contractData, 'external_contractors', $contractRequest->external_contractors ?? []),
        ])
        @include('livewire.contract.parts.documents', ['contract' => $contractRequest, 'data' => $contractData])

        @if(!empty($contractRequest->printout_content))
            <fieldset class="fieldset">
                <legend class="legend">{{ __('contracts.printout_content') }}</legend>
                <div class="show-alert-info overflow-auto max-h-72 text-xs font-mono whitespace-pre-wrap break-all">
                    {{ $contractRequest->printout_content }}
                </div>
            </fieldset>
        @endif
    </fieldset>

    <div class="shift-content mt-8 flex flex-wrap items-center justify-between gap-4">
        <a href="{{ route('contract-request.index', legalEntity()) }}" class="button-minor" wire:navigate>
            {{ __('forms.back_to_list') }}
        </a>

        <div class="flex flex-wrap items-center gap-3">
            @can('approve', $contractRequest)
                @if($this->canApproveContractRequest())
                    <button type="button"
                            wire:click="openApproveModal"
                            class="button-primary-outline">
                        {{ __('contracts.approve_contract_request') }}
                    </button>
                @endif
            @endcan

            @can('sign', $contractRequest)
                @if($this->canSignContractRequest())
                    <button type="button"
                            wire:click="openSignModal"
                            class="button-primary">
                        {{ __('contracts.sign_contract_request') }}
                    </button>
                @endif
            @endcan
        </div>
    </div>

    <x-signature-modal method="submitSignedAction" agreementText="Засвідчуючи даний договір кваліфікованим електронним підписом я розумію, про настання певних прав та обов’язків, зрозумів текст договору.">
        <x-slot:customFields>
            <p class="default-p">
                @if($pendingAction === 'approve')
                    {{ __('contracts.approve_signature_hint') }}
                @elseif($pendingAction === 'sign')
                    {{ __('contracts.sign_signature_hint') }}
                @endif
            </p>
        </x-slot:customFields>
    </x-signature-modal>
</section>
