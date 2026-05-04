@php
    use App\Models\LegalEntity;

    $readonly = $action === 'show';

    // Determine an appropriate HTTP-method
    $httpMethod = match ($action) {
        'show'   => 'GET',
        'update'   => 'PATCH',
        'store' => 'POST',
        default  => 'GET'
    };
@endphp

<div>
    <livewire:components.x-message :key="now()->timestamp"/>

    <div
        x-data="{
            divisionId: 0,
            textConfirmation: '',
            actionType: '',
            actionTitle: '',
            actionButtonText: ''
        }"
    >
        <x-header-navigation x-data="{ showFilter: false }" class=''>
            <x-slot name='title'>
                @yield('title')
            </x-slot>

            <x-slot name="description">
                @yield('description')
            </x-slot>
        </x-header-navigation>

        <div class="form shift-content">
            <section class="section-form">
                <div
                    class="form-row"
                    x-data="{
                        isDisabled: @json($readonly),
                        init() {
                            Livewire.hook('commit', ({ succeed }) => {
                                succeed(() => {
                                    this.$nextTick(() => {
                                        const firstErrorMessage = document.querySelector('.input-error')
                                        if (firstErrorMessage !== null) {
                                            firstErrorMessage.scrollIntoView({ block: 'center', inline: 'center' });
                                        }
                                    })
                                })
                            })
                        }
                    }"
                >
                    <form wire:submit.prevent="{{ $action }}">

                        @if (!in_array(strtoupper($httpMethod), ['GET', 'POST']))
                            @method($httpMethod)
                        @endif

                        <fieldset class="fieldset">
                            <!-- Personal Data Fieldset -->
                            <legend class="legend">
                                <h2>{{__('forms.main_information')}}</h2>
                            </legend>

                            <div class="form">

                                <div class="form-row-3">
                                    <!-- Division Name -->
                                    <div class="form-group">
                                        <input required
                                            id="name_division"
                                            type="text"
                                            placeholder=" "
                                            class="peer input @error('divisionForm.division.name') input-error border-red-500 @enderror"
                                            name="name_division"
                                            wire:model.defer='divisionForm.division.name'
                                            x-bind:disabled="isDisabled"
                                        />

                                        <label
                                            for="name_division"
                                            class="label"
                                        >
                                            {{ __('forms.full_name_division') }}
                                        </label>

                                        @error('divisionForm.division.name')
                                        <p class="text-error">{{$message}}</p>
                                        @enderror
                                    </div>

                                    <!-- Division Email -->
                                    <div class="form-group">
                                        <input required
                                            id="email"
                                            type="text"
                                            name="email"
                                            placeholder=" "
                                            class="peer input @error('divisionForm.division.email') input-error border-red-500 @enderror"
                                            wire:model.defer='divisionForm.division.email'
                                            x-bind:disabled="isDisabled"
                                        />

                                        <label
                                            for="email"
                                            class="label"
                                        >
                                            {{ __('forms.email') }}
                                        </label>

                                        @error('divisionForm.division.email')
                                        <p class="text-error">{{$message}}</p>
                                        @enderror
                                    </div>
                                </div>


                                <!-- Division Type & External ID -->
                                <div class="form-row-3">
                                    <div class="form-group">
                                        <select id="type"
                                                class='peer input @error("divisionForm.division.type") select-error border-red-500 @enderror'
                                                wire:model.defer='divisionForm.division.type'
                                                x-bind:disabled="{{ ($action === 'update' && $status !== 'DRAFT') || $action === 'show' ? 'true' : 'false' }}"
                                        >
                                            <option value="_placeholder_" selected hidden>-- {{ __('forms.type') }}--
                                            </option>

                                            @foreach ($dictionaries['DIVISION_TYPE'] as $k => $type)
                                                <option value="{{ $k }}">{{ $type }}</option>
                                            @endforeach
                                        </select>

                                        <label for="type" class="label">
                                            {{ __('forms.type') }} *
                                        </label>

                                        @error('divisionForm.division.type')
                                        <p class="text-error">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="form-group">
                                        <input type="text"
                                            placeholder=" "
                                            id="external_id"
                                            class="peer input @error('divisionForm.division.externalId') input-error border-red-500 @enderror"
                                            name="external_id"
                                            wire:model.defer='divisionForm.division.externalId'
                                            x-bind:disabled="{{ ($action === 'update' && $status !== 'DRAFT') || $action === 'show'? 'true' : 'false' }}"
                                        />

                                        <label
                                            for="external_id"
                                            class="label"
                                        >
                                            {{ __('forms.external_id') }}
                                        </label>

                                        @error('divisionForm.division.externalId')
                                        <p class="text-error">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>

                                <!-- PHONES -->
                                <div class="space-y-2"
                                    x-data="{ phones: $wire.entangle('divisionForm.division.phones') }"
                                    x-init="if (!Array.isArray(phones) || phones.length === 0) { phones = [{ type: '', number: '' }] }"
                                    x-id="['phone']"
                                >
                                    <template x-for="(phone, index) in phones" :key="index">
                                        <div x-data="{errors: [] }"
                                            x-init="errors = @js($errors->getMessages())"
                                            class="grid grid-cols-1 md:grid-cols-3 gap-6 items-center"
                                        >
                                            {{-- Phone Type Select --}}
                                            <div class="form-group">
                                                <select required
                                                        x-model="phones[index].type"
                                                        class="input-select"
                                                        :class="{ 'input-error': errors[`divisionForm.division.phones.${index}.type`] }"
                                                        :disabled="isDisabled"
                                                        :id="$id('phone', '_type_' + index)"
                                                >
                                                    <option value="_placeholder_" selected hidden>
                                                        -- {{ __('forms.type_mobile') }} --
                                                    </option>
                                                    <template x-for="(phoneType, key) in $wire.dictionaries.PHONE_TYPE"
                                                            :key="key"
                                                    >
                                                        <option x-text="phoneType"
                                                                :value="key"
                                                                :disabled="phones.some((p) => p.type === key)"
                                                                :selected="phone.type === key"
                                                        ></option>
                                                    </template>
                                                </select>

                                                <template x-if="errors[`divisionForm.division.phones.${index}.type`]">
                                                    <p class="text-error"
                                                    x-text="errors[`divisionForm.division.phones.${index}.type`]"
                                                    ></p>
                                                </template>

                                                <label :for="$id('phone', '_type_' + index)" class="label">
                                                    {{ __('forms.phone_type') }}
                                                </label>
                                            </div>

                                            {{-- Phone Number Input --}}
                                            <div class="form-group">
                                                <div class="phone-wrapper relative">
                                                    <input required
                                                        type="tel"
                                                        placeholder=" "
                                                        class="peer input pl-10 with-leading-icon text-gray-500 "
                                                        x-model="phones[index].number"
                                                        x-mask="+380999999999"
                                                        :id="$id('phone', '_number' + index)"
                                                        :class="{ 'input-error border-red-500': errors[`divisionForm.division.phones.${index}.number`] }"
                                                        :disabled="isDisabled"
                                                    />

                                                    <label :for="$id('phone', '_number' + index)" class="wrapped-label">
                                                        {{ __('forms.phone') }}
                                                    </label>
                                                </div>

                                                <template x-if="errors[`divisionForm.division.phones.${index}.number`]">
                                                    <p class="text-error"
                                                    x-text="errors[`divisionForm.division.phones.${index}.number`]"></p>
                                                </template>
                                            </div>

                                            <!-- Action Phone Buttons -->
                                            <div x-cloak
                                                x-show="!isDisabled"
                                                class="flex items-center space-x-4 justify-start"
                                            >
                                                <!-- Add phone -->
                                                <template x-if="phones.length > 1">
                                                    <button type="button" @click.prevent="phones.splice(index, 1)"
                                                            class="item-remove text-red-600 hover:text-red-800 justify-self-start">
                                                        <span>{{__('forms.remove_phone')}}</span>
                                                    </button>
                                                </template>

                                                <!-- Remove Phone -->
                                                <template x-if="index === phones.length - 1 && phones.length < 2">
                                                    <button type="button"
                                                            @click.prevent="phones.push({ type: '', number: '' })"
                                                            class="item-add">
                                                        <span>{{__('forms.add_phone')}}</span>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <!-- LOCATION -->

                                <div class="form-row-3">
                                    <div class="form-group">
                                        <input type="number"
                                            step="0.01"
                                            id="longitude"
                                            placeholder=" "
                                            x-ref="longitude"
                                            class="peer input"
                                            x-bind:disabled="isDisabled"
                                            name="longitude"
                                            wire:model='divisionForm.division.location.longitude'
                                            x-effect="$refs.longitude.value == 0 ? $refs.longitude.value = null : $refs.longitude.value"
                                        />
                                        <label
                                            for="longitude"
                                            class="label"
                                        >
                                            {{ __('forms.longitude') }}
                                        </label>

                                        @error('divisionForm.division.location.longitude')
                                        <p class="text-error">{{$message}}</p>
                                        @enderror
                                    </div>

                                    <div class="form-group">
                                        <input id="latitude"
                                            type="number"
                                            step="0.01"
                                            name="latitude"
                                            placeholder=" "
                                            x-ref="latitude"
                                            class="peer input"
                                            x-bind:disabled="isDisabled"
                                            wire:model='divisionForm.division.location.latitude'
                                            x-effect="$refs.latitude.value == 0 ? $refs.latitude.value = null : $refs.latitude.value"
                                        />

                                        <label
                                            for="latitude"
                                            class="label"
                                        >
                                            {{ __('forms.latitude') }}
                                        </label>

                                        @error('divisionForm.division.location.latitude')
                                        <p class="text-error">{{$message}}</p>
                                        @enderror
                                    </div>
                                </div>

                                <!-- UUID -->
                                @if ($action === 'show' && $uuid)
                                    <div class="form-row-3">
                                        <div class="form-group">
                                            <input type="text"
                                                id="uuid"
                                                placeholder=" "
                                                class="peer input"
                                                x-bind:disabled="true"
                                                name="uuid"
                                                value="{{ $uuid }}"
                                            />
                                            <label
                                                for="longitude"
                                                class="label"
                                            >
                                                {{ __('UUID') }}
                                            </label>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </fieldset>

                        {{-- ADDRESS --}}
                        <fieldset class="fieldset">

                            <legend class="legend">
                                <h2>{{ __('forms.address') }}</h2>
                            </legend>

                            <div class="form" x-data="{ showReceptionAddress: $wire.entangle('divisionForm.showReceptionAddress') }">
                                <x-forms.addresses-search
                                    :address="$address"
                                    :districts="$districts"
                                    :settlements="$settlements"
                                    :streets="$streets"
                                    :readonly="$readonly"
                                    class="mt-8 form-row-3"
                                />

                                @if(legalEntity()->type->name === LegalEntity::TYPE_OUTPATIENT)
                                    <div class='form-row-3'>
                                        <div class="form-group group">
                                            <input
                                                type="checkbox"
                                                id="showReception"
                                                class="default-checkbox text-blue-500 focus:ring-blue-300"
                                                x-model="showReceptionAddress"
                                                :checked="showReceptionAddress"
                                                :disabled="isDisabled"
                                            >

                                            <label for="showReception" class="ms-2 text-sm font-medium text-gray-500 dark:text-gray-300">{{ __('divisions.receptionShow') }}</label>
                                        </div>
                                    </div>

                                    <div x-show="showReceptionAddress" x-cloak>
                                        <x-forms.addresses-reception
                                            :address="$receptionAddress"
                                            :districts="$receptionDistricts"
                                            :settlements="$receptionSettlements"
                                            :streets="$receptionStreets"
                                            :readonly="$readonly"
                                            class="mt-8 form-row-3"
                                        />
                                    </div>

                                    <div class="form-group checkbox-group"
                                        x-data="{ isMountainGroup: @js($this->divisionForm->division['mountainGroup'] ?? false) }"
                                    >
                                        <input id="mountain_group"
                                            type="checkbox"
                                            :checked="isMountainGroup"
                                            class="w-5 h-5 text-blue-600 bg-gray-100 border-gray-300 rounded-sm focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600"
                                            disabled
                                        />

                                        <label for="mountain_group"
                                            class="checkbox-label text-gray-500 dark:text-gray-300 ms-2"
                                        >
                                            {{__('forms.mountainous_status')}}
                                        </label>
                                    </div>
                                @endif
                            </div>
                        </fieldset>

                        {{-- WORKING HOURS --}}
                        <fieldset class="fieldset"
                                x-data="{
                                    working: false,
                                    workingHours: $wire.entangle('divisionForm.division.workingHours'),
                                    isStoreMode: {{ $action === 'store' ? 'true' : 'false' }},
                                    init() {
                                        this.working = Object.values(this.workingHours).some(day => 
                                            day.some(shift => (shift[0] && shift[0] !== '' && shift[0] !== '00:00') || (shift[1] && shift[1] !== '' && shift[1] !== '00:00'))
                                        );
                                    }
                                }"
                        >
                            <legend class="legend">
                                <h2>{{ __('forms.work_schedule') }}</h2>
                            </legend>

                            <div class="form">
                                <div class="form-group mb-4">
                                    <button @click.prevent="working = !working"
                                            x-text="working ? '{{ __('forms.remove_work_schedule') }}' : '{{ __('forms.work_schedule') }}'"
                                            class="item-add"
                                    >
                                        {{ __('add_work_schedule') }}
                                    </button>
                                </div>

                                @if($action === 'store')
                                    <div x-cloak
                                        x-show="working"
                                        class="p-4 rounded-lg bg-blue-100 flex items-start mb-4"
                                    >
                                        <svg class="w-6 h-6 text-blue-500 mr-3 mt-1" aria-hidden="true"
                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"
                                            viewBox="0 0 24 24">
                                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M12 13V8m0 8h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                        </svg>
                                        <div>
                                            <p class="font-bold text-blue-800">{{ __('forms.important') }}</p>
                                            <p class="text-sm text-blue-600">{{ __("forms.schedule_note") }}</p>
                                        </div>
                                    </div>
                                @endif

                                @if ($weekdays)
                                    <div x-cloak
                                        x-show="working"
                                        class="grid md:grid-cols-2 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700"
                                    >
                                        @foreach ($weekdays as $key => $day)
                                            <div
                                                class="p-6 min-h-[220px] {{ $loop->iteration % 2 == 0 ? '' : 'border-r border-gray-200 dark:border-gray-700' }} {{ $loop->last ? '' : 'border-b border-gray-200 dark:border-gray-700' }} ">
                                                <div
                                                    :key="'{{ $key }}'"
                                                    x-data="{
                                                    shift: workingHours['{{ $key }}'].length > 1,
                                                    show_work: (workingHours['{{ $key }}'][0][0] !== '' && workingHours['{{ $key }}'][0][0] !== null && workingHours['{{ $key }}'][0][0] !== '00:00') ||
                                                    (workingHours['{{ $key }}'][0][1] !== '' && workingHours['{{ $key }}'][0][1] !== null && workingHours['{{ $key }}'][0][1] !== '00:00') ||
                                                    '{{ $action }}' === 'store',
                                                    switchWorking(day) {
                                                    this.show_work = !this.show_work;
                                                    this.workingHours[day] = [['00:00', '00:00']];
                                                    if (! this.show_work) {
                                                    this.shift = false;
                                                    }
                                                    },
                                                    addAvailableShift(day) {
                                                    if (this.workingHours[day].length < 4) {
                                                    this.workingHours[day].push(['00:00', '00:00']);
                                                    }
                                                    },
                                                    deleteShift(day, index) {
                                                    if (this.workingHours[day].length > 1) {
                                                    this.workingHours[day].splice(index, 1);
                                                    }
                                                    },
                                                    switchShift(day) {
                                                    if (! this.shift) {
                                                    return;
                                                    }
                                                    shiftCount = this.workingHours[day].length;
                                                    if (shiftCount > 1) {
                                                    this.workingHours[day].splice(1);
                                                    }
                                                    },
                                                    errors: []
                                                    }"
                                                    x-init="errors =@js($errors->getMessages())"
                                                >
                                                    <div class="mb-4">
                                                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $day }}</h3>
                                                    </div>
                                                    <div class="flex items-center gap-8 mb-4">
                                                        <label class="inline-flex items-center cursor-pointer">
                                                            <input type="checkbox" class="sr-only peer"
                                                                x-model="show_work"
                                                                x-on:click="switchWorking('{{ $key }}')"
                                                                x-bind:disabled="isDisabled"
                                                            >
                                                            <div
                                                                class="relative w-11 h-6 bg-gray-200 rounded-full peer peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:bg-gray-700 dark:peer-focus:ring-blue-800 after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:w-5 after:h-5 after:transition-all peer-checked:bg-blue-600 dark:peer-checked:bg-blue-600 peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full"></div>
                                                            <span
                                                                class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300"
                                                                x-text="show_work ? '{{ __('forms.works') }}' : '{{ __('forms.does_not_work') }}'"
                                                            ></span>
                                                        </label>
                                                        <label class="inline-flex items-center cursor-pointer"
                                                            x-bind:class="!show_work && 'opacity-40 pointer-events-none'">
                                                            <input type="checkbox"
                                                                x-model="shift"
                                                                :checked="shift"
                                                                @click="switchShift('{{ $key }}')"
                                                                x-bind:disabled="isDisabled"
                                                                id="shift_switcher"
                                                                class="w-5 h-5 text-blue-600 bg-gray-100 border-gray-300 rounded-sm focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600"
                                                            />
                                                            <span
                                                                class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">{{ __('forms.by_shift') }}</span>
                                                        </label>
                                                    </div>
                                                    <template x-if="show_work">
                                                        <div class="mt-3 space-y-4">
                                                            <template
                                                                x-if="isStoreMode || workingHours['{{ $key }}'].length">
                                                                <template
                                                                    x-for="(shiftHours, shiftIndex) in workingHours['{{ $key }}']"
                                                                    :key="shiftIndex">
                                                                    {{-- Don't remove this template!! It needs for properly deleted last shift --}}
                                                                    <template x-if="workingHours['{{ $key }}'][shiftIndex]">
                                                                        <div class="space-y-4">
                                                                            <template x-if="shift">
                                                                                <div
                                                                                    class="flex items-center gap-2 text-xs font-medium text-gray-700 dark:text-gray-300">
                                                                                    <span
                                                                                        class="w-2 h-2 rounded-full bg-blue-600"></span>
                                                                                    <span
                                                                                        x-text="(shiftIndex + 1) + ' {{ __('forms.shift') }}'"></span>
                                                                                </div>
                                                                            </template>
                                                                            <div class="flex items-end gap-4">
                                                                                <div class="form-group w-full">
                                                                                    <label
                                                                                        :for="'opened_by-' + '{{ $key }}' + '-' + shiftIndex"
                                                                                        class="label !text-xs !text-gray-500 dark:!text-gray-400"
                                                                                    >
                                                                                        <span
                                                                                            x-text="shift ? '{{ __('forms.start') }}' : '{{ __('forms.opened_by') }}'"></span>
                                                                                    </label>
                                                                                    <div class="relative w-full">
                                                                                        <div
                                                                                            class="absolute inset-y-0 start-0 flex items-center pointer-events-none">
                                                                                            <svg
                                                                                                class="w-5 h-5 text-gray-500 dark:text-gray-400"
                                                                                                aria-hidden="true"
                                                                                                xmlns="http://www.w3.org/2000/svg"
                                                                                                width="24" height="24"
                                                                                                fill="none"
                                                                                                viewBox="0 0 24 24">
                                                                                                <path stroke="currentColor"
                                                                                                    stroke-linecap="round"
                                                                                                    stroke-linejoin="round"
                                                                                                    stroke-width="2"
                                                                                                    d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                                                                            </svg>
                                                                                        </div>
                                                                                        <input
                                                                                            type="text"
                                                                                            :id="'opened_by-' + '{{ $key }}' + '-' + shiftIndex"
                                                                                            class="input timepicker-uk text-gray-900 dark:text-white border-t-0 border-r-0 border-l-0 border-b border-gray-300 focus:ring-0 px-0 ps-8"
                                                                                            placeholder="--:--"
                                                                                            x-model.lazy="workingHours['{{ $key }}'][shiftIndex][0]"
                                                                                            x-bind:disabled="isDisabled"
                                                                                        />
                                                                                    </div>
                                                                                    <template
                                                                                        x-if="errors[`divisionForm.division.working_hours.{{ $key }}.type.${shiftIndex}.0`]">
                                                                                        <p class="text-error"
                                                                                        x-text="errors[`divisionForm.division.working_hours.{{ $key }}.type.${shiftIndex}.0`]"></p>
                                                                                    </template>
                                                                                </div>
                                                                                <div class="form-group w-full">
                                                                                    <label
                                                                                        :for="'closed_by-' + '{{ $key }}' + '-' + shiftIndex"
                                                                                        class="label !text-xs !text-gray-500 dark:!text-gray-400"
                                                                                    >
                                                                                        <span
                                                                                            x-text="shift ? '{{ __('forms.end') }}' : '{{ __('forms.closed_by') }}'"></span>
                                                                                    </label>
                                                                                    <div class="relative w-full">
                                                                                        <div
                                                                                            class="absolute inset-y-0 start-0 flex items-center pointer-events-none">
                                                                                            <svg
                                                                                                class="w-5 h-5 text-gray-500 dark:text-gray-400"
                                                                                                aria-hidden="true"
                                                                                                xmlns="http://www.w3.org/2000/svg"
                                                                                                width="24" height="24"
                                                                                                fill="none"
                                                                                                viewBox="0 0 24 24">
                                                                                                <path stroke="currentColor"
                                                                                                    stroke-linecap="round"
                                                                                                    stroke-linejoin="round"
                                                                                                    stroke-width="2"
                                                                                                    d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                                                                            </svg>
                                                                                        </div>
                                                                                        <input
                                                                                            type="text"
                                                                                            :id="'closed_by-' + '{{ $key }}' + '-' + shiftIndex"
                                                                                            class="input timepicker-uk text-gray-900 dark:text-white border-t-0 border-r-0 border-l-0 border-b border-gray-300 focus:ring-0 px-0 ps-8"
                                                                                            placeholder="--:--"
                                                                                            x-model.lazy="workingHours['{{ $key }}'][shiftIndex][1]"
                                                                                            x-bind:disabled="isDisabled"
                                                                                        />
                                                                                    </div>
                                                                                    <template
                                                                                        x-if="errors[`divisionForm.division.working_hours.{{ $key }}.type.${shiftIndex}.1`]">
                                                                                        <p class="text-error"
                                                                                        x-text="errors[`divisionForm.division.working_hours.{{ $key }}.type.${shiftIndex}.1`]"></p>
                                                                                    </template>
                                                                                </div>
                                                                                <button
                                                                                    type="button"
                                                                                    x-show="shift && shiftIndex && !isDisabled"
                                                                                    @click="deleteShift('{{ $key }}', shiftIndex)"
                                                                                    class="h-10 text-red-600 dark:text-red-500 hover:text-red-700 cursor-pointer"
                                                                                    x-bind:disabled="isDisabled"
                                                                                >
                                                                                    <svg class="w-5 h-5" aria-hidden="true"
                                                                                        xmlns="http://www.w3.org/2000/svg"
                                                                                        width="24" height="24" fill="none"
                                                                                        viewBox="0 0 24 24">
                                                                                        <path stroke="currentColor"
                                                                                            stroke-linecap="round"
                                                                                            stroke-linejoin="round"
                                                                                            stroke-width="2"
                                                                                            d="M5 7h14m-9 3v8m4-8v8M10 3h4a1 1 0 0 1 1 1v3H9V4a1 1 0 0 1 1-1ZM6 7h12v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V7Z"/>
                                                                                    </svg>
                                                                                </button>
                                                                            </div>
                                                                        </div>
                                                                    </template>
                                                                </template>
                                                            </template>
                                                            <template x-if="workingHours['{{ $key }}'].length < 4">
                                                                <button
                                                                    x-show="shift && !isDisabled"
                                                                    class='item-add text-sm'
                                                                    @click.prevent="addAvailableShift('{{ $key }}')"
                                                                    x-bind:disabled="isDisabled"
                                                                >
                                                                    {{ __('forms.add_shift') }}
                                                                </button>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </fieldset>

                        <div class="flex gap-2 items-center additional-actions">
                            <a role="button"
                            class="button-minor cursor-pointer inline-flex items-center leading-none"
                            href="javascript:history.back()">
                                {{ __('forms.cancel') }}
                            </a>

                            @yield('additional-buttons')
                        </div>
                    </form>
                </div>

                <x-forms.loading />
            </section>

            @include('livewire.division.modal.confirmation-modal')

        </div>
    </div>
</div>
