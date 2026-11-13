<section class="section-form w-full max-w-7xl">
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <div class="flex w-full flex-wrap items-center justify-between gap-4">
        <x-header-navigation class="breadcrumb-form min-w-0 flex-1">
            <x-slot name="title">
                {{ __('contracts.label') }}
                @if ($contract->contract_number ?? ($data['contract_number'] ?? null))
                    № {{ $contract->contract_number ?? $data['contract_number'] }}
                @endif
            </x-slot>

            @if (is_object($contract->status) && method_exists($contract->status, 'label'))
                <span class="{{ $contract->status->color() }} px-3 py-1 rounded-full text-xs font-bold uppercase">
                    {{ $contract->status->label() }}
                </span>
            @else
                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-bold text-gray-800 uppercase">
                    {{ $contract->status }}
                </span>
            @endif
            @if ($contract->isSuspended)
                <span class="badge-yellow">{{ __('contracts.suspension_active') }}</span>
            @endif
        </x-header-navigation>
    </div>

    <fieldset disabled class="form shift-content mt-6 space-y-8">
        @include('livewire.contract.parts.basic-data', ['contract' => $contract, 'data' => $data, 'idFormName' => $idFormName])
        @include('livewire.contract.parts.contractor', ['data' => $data])
        @include('livewire.contract.parts.nhs-customer', ['contract' => $contract, 'data' => $data])
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

    @if ($printoutContent !== '')
        <fieldset class="fieldset shift-content mt-6">
            <legend class="legend">{{ __('contracts.printout_content') }}</legend>
            <pre class="text-sm break-words whitespace-pre-wrap dark:text-gray-200">{{ $printoutContent }}</pre>
        </fieldset>
    @endif

    <details class="fieldset shift-content mt-6">
        <summary class="legend cursor-pointer">{{ __('contracts.all_ehealth_details') }}</summary>
        <div class="mt-4">
            @include('livewire.contract-request.partials.ehealth-data-tree', ['data' => $data, 'path' => ''])
        </div>
    </details>

    @include('livewire.contract.parts.actions', ['showFooter' => true])
</section>
