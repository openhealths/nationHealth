@php
    use App\Models\LegalEntity;
    use App\Enums\JobStatus;

    $le = $this->legalEntity;
    $isEdit = $isDetails = true;

    $parentLegalEntity = legalEntity()->parentLegalEntity()->first();

    $leStatusMap = [
        'ACTIVE' => [__('forms.status.active'), 'status-alert-green'],
        'SUSPENDED' => [__('forms.status.suspended'), 'status-alert-red'],
        'CLOSED' => [__('forms.status.inactive'), 'status-alert-red'],
        'REORGANIZED' => [__('forms.status.reorganized'), 'status-alert-red'],
    ];

    $edrStatus = (string)$le->edr['state'];
    $edrStatusStyleMap = [
        '-1' => 'status-alert-red',
        '1' =>  'status-alert-green',
        '2' => 'status-alert-yellow',
        '3' => 'status-alert-red',
        '4' => 'status-alert-red',
        '5' => 'status-alert-red',
        '6' => 'status-alert-red',
    ];
@endphp

<div x-data="{ isDisabled: true, isEdit: @json($isEdit), activeStep: 0}">

    <livewire:components.x-message :key="now()->timestamp"/>

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
            <legend class="legend">{{ __('forms.verification_NSZU') }}</legend>
            <div class="flow-root mt-4">
                <div class="max-w-screen-xl">
                    <table class="table-input w-full table-fixed min-w-[600px] text-sm">
                        <thead class="thead-input">
                        <tr>
                            <th scope="col" class="px-3 py-3 th-input w-[15%]">{{__('forms.status.label')}}</th>
                            <th scope="col" class="px-3 py-3 th-input w-[35%]">{{__('forms.reviewed_NHS')}}</th>
                            <th scope="col" class="px-3 py-3 th-input w-[50%]">{{__('forms.comment_NSZU')}}</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <td class="td-input break-words whitespace-nowrap align-top">
                                @if($le->nhs_verified)
                                    <span class="badge-green">{{__('forms.status.active')}}</span>
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

        <fieldset class="p-4 sm:p-8 sm:pb-10 mb-16 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-[1280px]">
            <legend class="legend">{{ __('forms.status_in_the_system') }}</legend>
            <div class="{{ $leStatusMap[$le->status][1] ?? 'status-alert-red' }} status-alert-full mb-6">
                    <span class="flex-shrink-0">
                        @icon('check-circle', 'w-5 h-5 text-green-700 mr-3')
                    </span>
                    <span class="ms-1">{{ $leStatusMap[$le->status][0] ?? __('forms.status.unknown') }}</span>
                </div>
        </fieldset>

        {{-- E D R --}}
        <fieldset class="p-4 sm:p-8 sm:pb-10 mb-16 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-[1280px]">
            <legend class="legend">{{ __('forms.state_of_the_NMP') }}</legend>

            <div class="{{ $edrStatusStyleMap[$edrStatus] ?? 'status-alert-red' }} status-alert-full mb-6">
                <span class="flex-shrink-0">
                    @icon('check-circle', 'w-5 h-5 text-green-700 mr-3')
                </span>
                <span class="ms-1">{{ $edrStatuses[$edrStatus] ?? __('forms.status.unknown') }}</span>
            </div>

            <div class="flex flex-col lg:flex-row lg:gap-x-8">
                <div class="flex-grow lg:max-w-[60%] lg:min-w-0">
                    {{-- NAME --}}
                    <div class="form-group">
                        <input
                            id="edrName"
                            type="text"
                            placeholder=" "
                            name="nameLegalEntity"
                            class="peer input"
                            value="{{ __($le->edr['name'] ?? '') }}"
                            x-bind:disabled="isDisabled"
                        />

                        <label
                            for="edrName"
                            class="label"
                        >
                            {{ __('forms.full_name_division') }}
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
                            value="{{ __($le->edr['public_name'] ?? '') }}"
                            x-bind:disabled="isDisabled"
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
                            value="{{ __($le->edr['short_name'] ?? '') }}"
                            x-bind:disabled="isDisabled"
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
                            value="{{ __($edrLegalForms[$le->edr['legal_form']] ?? '') }}"
                            x-bind:disabled="isDisabled"
                        />

                        <label
                            for="legalForm"
                            class="label"
                        >
                            {{ __('forms.organizational_legal_form') }}
                        </label>
                    </div>

                    {{-- ADDRESS REGISTRATION NMP --}}
                    <div class="form-group">
                        <input
                            id="addressRegistrationNMP"
                            type="text"
                            placeholder=" "
                            class="peer input"
                            name="addressRegistrationNMP"
                            value="{{ __($le->edr['registration_address']['address'] ?? '') }}"
                            x-bind:disabled="isDisabled"
                        />

                        <label
                            for="addressRegistrationNMP"
                            class="label"
                        >
                            {{ __('forms.address_registration_NMP') }}
                        </label>
                    </div>
                </div>

                <div class="lg:mt-0 lg:min-w-[280px] lg:flex-shrink-0 space-y-4">
                <p class="text-base font-semibold text-gray-900 dark:text-gray-200 mb-4">{{ __('Список КВЕДів:') }}</p>

                    <div class="text-sm text-gray-900 dark:text-gray-200 space-y-4">
                        <div>
                            <p class="mb-2 font-semibold text-gray-600 dark:text-gray-400">{{ __('Основний КВЕД:') }}</p>
                            <p class="ms-2">{{ __($mainKVED['code'] . ' ' . $mainKVED['name'] ?? '') }}</p>
                        </div>
                        <div>
                            <p class="mb-2 font-semibold text-gray-600 dark:text-gray-400">{{ __('Додаткові КВЕДи:') }}</p>

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
                <div class="status-alert-cyan status-alert-full mb-6">
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
