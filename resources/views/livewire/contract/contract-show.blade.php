<section class="section-form">
    <x-header-navigation class="breadcrumb-form flex-1 min-w-0">
        <x-slot name="title">
            {{ __('contracts.label') }} {{ $contract->contract_number ?? ($data['contract_number'] ?? '---') }}
        </x-slot>

        {{-- Status (check against object/enum or string) --}}
        @if(is_object($contract->status) && method_exists($contract->status, 'label'))
            <span class="{{ $contract->status->color() }} px-3 py-1 rounded-full text-xs font-bold uppercase">
                {{ $contract->status->label() }}
            </span>
        @else
            <span class="bg-gray-100 text-gray-800 px-3 py-1 rounded-full text-xs font-bold uppercase">
                {{ $contract->status }}
            </span>
        @endif
    </x-header-navigation>

    {{-- fieldset disabled робить всі інпути всередині неактивними (тільки для читання) --}}
    <fieldset disabled class="form shift-content space-y-8">
        {{-- We pass $contract and $data to each partial --}}

        @include('livewire.contract.parts.basic-data', ['contract' => $contract, 'data' => $data])

        @include('livewire.contract.parts.contractor', ['data' => $data])

        @include('livewire.contract.parts.nhs-customer', ['data' => $data])

        @include('livewire.contract.parts.payment-details', ['contract' => $contract, 'data' => $data])

        @include('livewire.contract.parts.divisions', ['contract' => $contract, 'data' => $data])

        @include('livewire.contract.parts.medical-programs', [
            'contract' => $contract,
            'data' => $data,
            'medicalProgramsList' => [],
            'medicalProgramNames' => $medicalProgramNames ?? [],
        ])

        @include('livewire.contract.parts.external-contractors-readonly', [
            'externalContractors' => data_get($data, 'external_contractors', $contract->external_contractors ?? []),
        ])

        @include('livewire.contract.parts.documents', ['contract' => $contract, 'data' => $data])
    </fieldset>

    {{-- Footer with a back button --}}
    @include('livewire.contract.parts.actions', ['showFooter' => true])
</section>
