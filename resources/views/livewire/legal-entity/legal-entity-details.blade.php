@php
    use App\Models\LegalEntity;
    use App\Enums\JobStatus;

    $le = $this->legalEntity;
    $isEdit = $isDetails = true;

    $parentLegalEntity = legalEntity()->parentLegalEntity()->first();
@endphp

<div x-data="{ isDisabled: true, isEdit: @json($isEdit), activeStep: 0}">
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />

    <x-header-navigation class="items-start" x-data="{ showFilter: false }">

        <x-slot name="title">
            {{ data_get($le->edr, 'name') ?? data_get($le->edr, 'public_name') ?? __('Unnamed legal entity') }}
        </x-slot>

        @if(auth()->getDefaultDriver() === 'ehealth')
            @can('sync', [LegalEntity::class, $le])
                <div class="flex flex-wrap items-end justify-between gap-4 max-w-6xl">
                    <button
                        wire:click="{{ !$this->isSync ? 'sync' : '' }}"
                        class="{{ $this->isSync ? 'button-sync-disabled' : 'button-sync' }} flex items-center gap-2 whitespace-nowrap"
                        {{ $this->isSync ? 'disabled' : '' }}
                    >
                        @icon('refresh', 'w-4 h-4')
                        <span>{{ ($syncStatus === JobStatus::PAUSED->value || $syncStatus === JobStatus::FAILED->value) ? __('forms.sync_retry') : __('forms.synchronise_with_eHealth') }}</span>
                    </button>
                </div>
            @endcan
        @endif
    </x-header-navigation>

    <div class="shift-content pl-3.5">
        <fieldset class="p-4 sm:p-8 sm:pb-10 mb-16 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-[1280px]">
            <legend class="legend">{{ __('forms.status_in_the_system') }}</legend>
            <div class="{{ $this->statusStyle }} status-alert-full mb-6">
                    <span class="flex-shrink-0">
                        @icon('check-circle', 'w-5 h-5 text-green-700 mr-3')
                    </span>
                        <span class="ms-1">{{ $this->statusLabel }}</span>
                </div>
        </fieldset>

        <fieldset class="p-4 sm:p-8 sm:pb-10 mb-16 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-[1280px]">
            <legend class="legend">{{ __('forms.verification_NSZU') }}</legend>
            <div class="flow-root mt-4">
                <div class="max-w-screen-xl">
                    <table class="table-input w-full table-fixed min-w-[600px] text-sm">
                        <thead class="thead-input">
                        <tr>
                            <th scope="col" class="px-3 py-3 th-input w-[25%]">{{__('forms.verified_NHS')}}</th>
                            <th scope="col" class="px-3 py-3 th-input w-[25%]">{{__('forms.reviewed_NHS')}}</th>
                            <th scope="col" class="px-3 py-3 th-input w-[50%]">{{__('forms.comment_NSZU')}}</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <td class="td-input break-words whitespace-nowrap align-top">
                                @if($le->nhs_verified)
                                    <span class="badge-green">{{__('forms.verified_NHS')}}</span>
                                @else
                                    <span class="badge-red">{{__('forms.not_verified')}}</span>
                                @endif
                            </td>
                            <td class="td-input break-words whitespace-nowrap align-top">
                                @if($le->nhs_reviewed)
                                    <span class="badge-green">{{__('forms.yes')}}</span>
                                @else
                                    <span class="badge-red">{{__('forms.no')}}</span>
                                @endif
                            </td>
                            <td>
                                {{ $le->nhs_comment }}
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </fieldset>

        {{-- E D R --}}
        <fieldset class="p-4 sm:p-8 sm:pb-10 mb-16 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-[1280px]">
            <legend class="legend">{{ __('forms.state_of_the_NMP') }}</legend>

            <span class="text-xs text-gray-500 dark:text-gray-400 mb-2 block">
                {{ __('forms.edr.edrStatus') }}
            </span>
            <div id="edrStatus" class="{{ $this->edrStatusStyle }} status-alert-full mb-6">

                <span class="flex-shrink-0">
                    @icon('check-circle', 'w-5 h-5 text-green-700 mr-3')
                </span>
                <span class="ms-1">{{ $this->edrStatusLabel }}</span>
            </div>

            <div class="flex flex-col lg:flex-row lg:gap-x-8">
                <div class="flex-grow lg:max-w-[60%] lg:min-w-0">
                    {{-- EDR EDRPOU --}}
                    <div class="form-group">
                        <input
                            id="edrEdrpou"
                            type="text"
                            placeholder=" "
                            name="edrEdrpou"
                            class="peer input"
                            value="{{ __($le->edr['edrpou'] ?? '-') }}"
                            readonly
                        />

                        <label
                            for="edrEdrpou"
                            class="label"
                        >
                            {{ __('forms.edr.edrpou') }}
                        </label>
                    </div>

                    {{-- NAME --}}
                    <div class="form-group">
                        <input
                            id="edrName"
                            type="text"
                            placeholder=" "
                            name="nameLegalEntity"
                            class="peer input"
                            value="{{ __($le->edr['name'] ?? '-') }}"
                            reaodonly
                        />

                        <label
                            for="edrName"
                            class="label"
                        >
                            {{ __('forms.full_name_division') }}
                        </label>
                    </div>

                    {{-- EDR UUID --}}
                    <div class="form-group">
                        <input
                            id="edrUuid"
                            type="text"
                            placeholder=" "
                            name="edrUuid"
                            class="peer input"
                            value="{{ __($le->edr['uuid'] ?? '-') }}"
                            readonly
                        />

                        <label
                            for="edrUuid"
                            class="label"
                        >
                            {{ __('forms.edr.uuid') }}
                        </label>
                    </div>

                    {{-- PUBLIC NAME --}}
                    <div class="form-group">
                        <input
                            id="publicName"
                            type="text"
                            placeholder=" "
                            class="peer input"
                            name="publicName"
                            value="{{ __($le->edr['public_name'] ?? '-') }}"
                            readonly
                        />

                        <label
                            for="publicName"
                            class="label"
                        >
                            {{ __('forms.public_name') }}
                        </label>
                    </div>

                    {{-- SHORT NAME --}}
                    <div class="form-group">
                        <input
                            id="shortName"
                            type="text"
                            placeholder=" "
                            class="peer input"
                            name="shortName"
                            value="{{ __($le->edr['short_name'] ?? '-') }}"
                            readonly
                        />

                        <label
                            for="shortName"
                            class="label"
                        >
                            {{ __('forms.abbreviated_name') }}
                        </label>
                    </div>

                    {{-- ORGANIZATIONAL LEGAL FORM --}}
                    <div class="form-group">
                        <input
                            id="legalForm"
                            type="text"
                            placeholder=" "
                            class="peer input"
                            name="legalForm"
                            value="{{ __($edrLegalForms[$le->edr['legal_form']] ?? '-') }}"
                            readonly
                        />

                        <label
                            for="legalForm"
                            class="label"
                        >
                            {{ __('forms.organizational_legal_form') }}
                        </label>
                    </div>

                    {{-- ADDRESS REGISTRATION NMP --}}
                    <h3 class="mt-2 mb-4">{{ __('forms.address_registration_NMP') }}</h3>

                    {{-- COUNTRY --}}
                    <div class="form-group group">
                        <input
                            value="{{ __($le->edr['registration_address']['country'] ?? '-') }}"
                            type="text"
                            placeholder=" "
                            id="addressCountry"
                            class="input peer"
                            readonly
                        />

                        <label for="addressCountry" class="label z-10">
                            {{ __('forms.country') }}
                        </label>
                    </div>

                    {{-- ZIP --}}
                    <div class="form-group group">
                        <input
                            value="{{ __($le->edr['registration_address']['zip'] ?? '-') }}"
                            type="text"
                            x-mask="99999"
                            placeholder=" "
                            id="addressZip"
                            class="input peer"
                            readonly
                        />

                        <label for="addressZip" class="label z-10">
                            {{ __('forms.zip_code') }}
                        </label>
                    </div>

                    {{-- FULL ADDRESS --}}
                    <div class="form-group">
                        <input
                            id="addressRegistrationNMP"
                            type="text"
                            placeholder=" "
                            class="peer input"
                            name="addressRegistrationNMP"
                            value="{{ __($le->edr['registration_address']['address'] ?? '-') }}"
                            readonly
                        />

                        <label
                            for="addressRegistrationNMP"
                            class="label"
                        >
                            {{ __('forms.address') }}
                        </label>
                    </div>

                    <div
                        x-data="{ open: false }"
                        class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm"
                    >
                        <h2>
                            <button
                                type="button"
                                class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                                @click="open = !open"
                                :aria-expanded="open"
                            >
                                    <span class="text-base font-semibold text-gray-900 dark:text-white">
                                        {{ __('Детальні дані про адресу реєстрації') }}
                                    </span>
                                @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                            </button>
                        </h2>

                        <div
                            x-cloak
                            x-show="open"
                            wire:ignore.self
                        >
                            <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-6">
                                <div class="form-row-2">
                                    <div class="form-group group">
                                        <input
                                            type="text"
                                            class="input peer"
                                            placeholder=" "
                                            readonly
                                            value="{{ __(Arr::get($le->edr, 'registration_address.parts.atu', '-') ?? '-') }}"
                                        />

                                        <label class="label">{{ __('forms.atu') }}</label>
                                    </div>
                                    <div class="form-group group">
                                        <input
                                            type="text"
                                            class="input peer"
                                            placeholder=" "
                                            readonly
                                            value="{{ __(Arr::get($le->edr, 'registration_address.parts.atu_code', '-') ?? '-') }}"
                                        />
                                        <label class="label">{{ __('forms.atu_code') }}</label>
                                    </div>
                                </div>

                                {{-- Building MAIN --}}
                                <h6 class="-mt-6 mb-4 text-sm text-blue-700">{{ __('forms.building_main') }}</h6>

                                <div class="form-row-3">
                                    <div class="form-group group">
                                        <input
                                            type="text"
                                            id='edrAddressStreet'
                                            class="input peer"
                                            placeholder=" "
                                            readonly
                                            value="{{ __(Arr::get($le->edr, 'registration_address.parts.street', '-') ?? '-') }}"
                                        />
                                        <label for="edrAddressStreet" class="label">{{ __('forms.street') }}</label>
                                    </div>

                                    <div class="form-group group">
                                        <input
                                            type="text"
                                            id='edrAddressHouseType'
                                            class="input peer"
                                            placeholder=" "
                                            readonly
                                            value="{{ __(Arr::get($le->edr, 'registration_address.parts.house_type', '-') ?? '-') }}"
                                        />
                                        <label for="edrAddressHouseType" class="label">{{ __('forms.type') }}</label>
                                    </div>

                                    <div class="form-group group">
                                        <input
                                            type="text"
                                            class="input peer"
                                            id='edrAddressHouse'
                                            placeholder=" "
                                            readonly
                                            value="{{ __(Arr::get($le->edr, 'registration_address.parts.house', '-') ?? '-') }}"
                                        />
                                        <label for="edrAddressHouse" class="label">{{ __('forms.number') }}</label>
                                    </div>
                                </div>

                                {{-- Building parts --}}
                                <h6 class="-mt-6 mb-4 text-sm text-blue-700">{{ __('forms.building_parts') }}</h6>

                                <div class="form-row-2">
                                    <div class="form-group group">
                                        <input
                                            type="text"
                                            id='edrAddressBuildingType'
                                            class="input peer"
                                            placeholder=" "
                                            readonly
                                            value="{{ __(Arr::get($le->edr, 'registration_address.parts.building_type', '-') ?? '-') }}"
                                        />
                                        <label for="edrAddressBuildingType" class="label">{{ __('forms.subtype') }}</label>
                                    </div>

                                    <div class="form-group group">
                                        <input
                                            type="text"
                                            id='edrAddressBuilding'
                                            class="input peer"
                                            placeholder=" "
                                            readonly
                                            value="{{ __(Arr::get($le->edr, 'registration_address.parts.building', '-') ?? '-') }}"
                                        />
                                        <label for="edrAddressBuilding" class="label">{{ __('forms.number') }}</label>
                                    </div>
                                </div>

                                {{-- Building apartment --}}
                                <h6 class="-mt-6 mb-4 text-sm text-blue-700">{{ __('forms.lodgement') }}</h6>

                                <div class="form-row-2">
                                    <div class="form-group group">
                                        <input
                                            type="text"
                                            id='edrAddressLodgementType'
                                            class="input peer"
                                            placeholder=" "
                                            readonly
                                            value="{{ __(Arr::get($le->edr, 'registration_address.parts.num_type', '-') ?? '-') }}"
                                        />
                                        <label for="edrAddressLodgementType" class="label">{{ __('forms.type') }}</label>
                                    </div>

                                    <div class="form-group group">
                                        <input
                                            type="text"
                                            id='edrAddressLodgementNumber'
                                            class="input peer"
                                            placeholder=" "
                                            readonly
                                            value="{{ __(Arr::get($le->edr, 'registration_address.parts.num', '-') ?? '-') }}"
                                        />
                                        <label for="edrAddressLodgementNumber" class="label">{{ __('forms.number') }}</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lg:mt-0 lg:min-w-[280px] lg:flex-shrink-0 space-y-4">
                <p class="text-base font-semibold text-gray-900 dark:text-gray-200 mb-4">{{ __('forms.edr.kved') . ':' }}</p>

                    <div class="text-sm text-gray-900 dark:text-gray-200 space-y-4">
                        <div>
                            <p class="mb-2 font-semibold text-gray-600 dark:text-gray-400">{{ __('forms.edr.main_kved') }}</p>
                            <p class="ms-2">{{ __($mainKVED['code'] . ' ' . $mainKVED['name'] ?? '') }}</p>
                        </div>
                        <div>
                            <p class="mb-2 font-semibold text-gray-600 dark:text-gray-400">{{ __('forms.edr.additional_kveds') }}</p>

                            @foreach ($additionalKVEDs as $kved)
                                <p class="ms-2">{{ __($kved['code'] . ' ' . $kved['name'] ?? '') }}</p>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </fieldset>

        {{-- REORGANIZATION --}}
        <fieldset class="p-4 sm:p-8 sm:pb-10 mb-16 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-[1280px]">
            <legend class="legend">{{ __('forms.participation_reorganization') }}</legend>

            @if ($le->status !== 'REORGANIZED')
                @if ($this->relatedLegalEntities->isEmpty())
                <div class="status-alert-green status-alert-full mb-6">
                    <span class="flex-shrink-0">
                        @icon('check-circle', 'w-5 h-5 text-green-700 mr-3')
                    </span>

                    <span class="ms-1">{{__('forms.not_process_of_reorganization')}}</span>
                </div>
                @else
                <div class="status-info-cyan status-alert-full mb-6">
                    <span class="flex-shrink-0">
                        @icon('check-circle', 'w-5 h-5 text-green-700 mr-3')
                    </span>

                    <span class="ms-1">{{__('forms.le_accessor')}}</span>
                </div>
                @endif
            @else
                <div class="status-alert-red status-alert-full mb-6">
                    <span class="flex-shrink-0">
                        @icon('alert-circle', 'w-5 h-5 text-red-500 mr-3')
                    </span>

                    <span class="ms-1">{{__('forms.process_of_reorganization')}}</span>
                </div>
            @endif

            @if($this->relatedLegalEntities->isNotEmpty() || $parentLegalEntity)
            <div class=" lg:mt-0 lg:min-w-[280px] lg:-ml-1 space-y-4">
                <p class="text-base font-semibold text-gray-900 dark:text-gray-200 mb-4">{{__('forms.reorg_related_legal_entities')}}:</p>

                <ul class="list-disc list-inside">
                    @if($parentLegalEntity)
                        <li class="ms-4">{{ $parentLegalEntity->edr['name'] ?? __('forms.name_not_defined') }}</li>
                    @endif

                    @foreach ($this->relatedLegalEntities as $relatedLegalEntity)
                        <li class="ms-4">{{ $relatedLegalEntity->name }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <!-- TODO: restore this functionality later -->
            @if(false)
            <div class="flex items-center gap-4 mt-6">
                <a href=" "
                class="cursor-pointer text-blue-600 hover:text-blue-800 flex items-center gap-1">
                    @icon('download', 'w-4 h-4 text-blue-600 hover:text-blue-800')
                    <span class="text-sm">{{ __('forms.download_list_employees') }}</span>
                </a>

                <a href=" "
                class="cursor-pointer text-blue-600 hover:text-blue-800 flex items-center gap-1">
                    @icon('upload', 'w-4 h-4 text-blue-600 hover:text-blue-800')
                    <span class="text-sm">{{ __('forms.upload_employee_list') }}</span>
                </a>
            </div>
            @endif
        </fieldset>

        {{-- STEPS --}}
        <fieldset x-bind:disabled="isDisabled">
                @include('livewire.legal-entity.step._step_edrpou')
                @include('livewire.legal-entity.step._step_owner')
                @include('livewire.legal-entity.step._step_contact')
                @include('livewire.legal-entity.step._step_residence_address')
                @include('livewire.legal-entity.step._step_accreditation')
                @include('livewire.legal-entity.step._step_license')
                @include('livewire.legal-entity.step._step_additional_information')
        </fieldset>

        <x-forms.loading/>

        {{-- BUTTONS --}}
        <div class="flex gap-2 items-center additional-actions">
            <a role="button"
                class="alternative-button cursor-pointer !mb-0 inline-flex items-center leading-none"
                href="javascript:history.back()">
                {{ __('forms.back') }}
            </a>

            @if(auth()->getDefaultDriver() === 'ehealth')
                @can('edit', [LegalEntity::class, $le])
                    <a role="button" class="default-button cursor-pointer inline-flex items-center leading-none !mb-0" href="{{ route('legal-entity.edit', [legalEntity()]) }}">
                        {{ __('forms.edit') }}
                    </a>
                @endcan
            @endif
        </div>
    </div>
</div>
