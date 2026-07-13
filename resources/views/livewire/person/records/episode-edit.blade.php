@php
    $routePrefix = $prepersonId !== null ? 'prepersons' : 'persons';
    $routeParamKey = $prepersonId !== null ? 'preperson' : 'person';
    $recordId = $prepersonId ?? $personId;
@endphp

<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :hideNavigation="true"
    :title="$episodeUuid ? __('patients.messages.episode_edit_title', ['name' => $name]) : __('patients.messages.episode_create_title')"
>
    <x-slot name="headerActions"></x-slot>

    <div class="breadcrumb-form p-4 sm:p-8 shift-content max-w-4xl">
        <form class="space-y-6" wire:submit.prevent="save">
            <div class="form-row-3">
                <div class="form-group group">
                    <input wire:model="name"
                           type="text"
                           name="name"
                           id="name"
                           class="input peer @error('name') input-error @enderror"
                           placeholder=" "
                           required
                           autocomplete="off"
                    />
                    <label for="name" class="label">
                        {{ __('patients.episode_name') }}
                    </label>
                    @error('name')
                        <p class="text-error mt-1 text-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group group">
                    <select
                        class="input-select peer @error('typeCode') input-error @enderror"
                        wire:model="typeCode"
                        name="typeCode"
                        id="typeCode"
                        required
                    >
                        <option value="" disabled selected hidden></option>
                        @foreach($episodeTypes as $code => $display)
                            <option value="{{ $code }}" class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white">
                                {{ $display }}
                            </option>
                        @endforeach
                    </select>
                    <label for="typeCode" class="label">
                        {{ __('patients.episode_type') }}
                    </label>
                    @error('typeCode')
                        <p class="text-error mt-1 text-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group group">
                    <select
                        class="input-select peer @error('statusCode') input-error @enderror"
                        wire:model="statusCode"
                        name="statusCode"
                        id="statusCode"
                        required
                    >
                        <option value="" disabled selected hidden></option>
                        @foreach($episodeStatuses as $code => $display)
                            <option value="{{ $code }}" class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white">
                                {{ $display }}
                            </option>
                        @endforeach
                    </select>
                    <label for="statusCode" class="label">
                        {{ __('patients.episode_status') }}
                    </label>
                    @error('statusCode')
                        <p class="text-error mt-1 text-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="form-row-3">
                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group datepicker-wrapper relative w-full">
                        <input wire:model="startDate"
                               type="text"
                               name="startDate"
                               id="startDate"
                               class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400 @error('startDate') input-error @enderror"
                               placeholder=" "
                               required
                        />
                        <label for="startDate" class="wrapped-label">{{ __('forms.start_date') }}</label>
                        @error('startDate')
                            <p class="text-error mt-1 text-xs">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-group relative w-full">
                        @icon('clock', 'w-5 h-5 text-gray-500 dark:text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none')
                        <input wire:model="startTime"
                               type="text"
                               name="startTime"
                               id="startTime"
                               class="peer input pl-10 appearance-none text-gray-500 dark:text-gray-400 @error('startTime') input-error @enderror"
                               placeholder=" "
                               required
                        />
                        <label for="startTime" class="wrapped-label">{{ __('forms.start_time') }}</label>
                        @error('startTime')
                            <p class="text-error mt-1 text-xs">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group group">
                    <select
                        class="input-select peer @error('careManagerUuid') input-error @enderror"
                        wire:model="careManagerUuid"
                        name="careManagerUuid"
                        id="careManagerUuid"
                        required
                    >
                        <option value="" disabled selected hidden></option>
                        @foreach($employees as $employee)
                            <option value="{{ $employee['uuid'] }}" class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white">
                                {{ $employee['name'] }} ({{ $employee['position'] }})
                            </option>
                        @endforeach
                    </select>
                    <label for="careManagerUuid" class="label">
                        {{ __('patients.messages.attending_doctor') }}
                    </label>
                    @error('careManagerUuid')
                        <p class="text-error mt-1 text-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            @if($episodeUuid)
                <div class="form-row-3 pt-8">
                    <div class="flex gap-4 col-span-1">
                        <button type="button"
                                wire:click="cancel"
                                class="button-primary-outline-red flex-1 text-center py-2.5 text-sm rounded-lg"
                        >
                            {{ __('patients.messages.cancel_changes') }}
                        </button>

                        <button type="submit"
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-50 cursor-not-allowed"
                                class="button-primary flex-1 text-center py-2.5 text-sm rounded-lg"
                        >
                            {{ __('patients.messages.save_changes') }}
                        </button>
                    </div>
                </div>
            @else
                <div class="form-row-3 pt-8">
                    <div class="flex gap-3 col-span-1">
                        <button type="button"
                                wire:click="cancel"
                                class="button-primary-outline-red flex-1 text-center py-2.5 text-sm rounded-lg"
                        >
                            {{ __('forms.delete') }}
                        </button>

                        <button type="submit"
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-50 cursor-not-allowed"
                                class="button-primary-outline flex-1 flex items-center justify-center gap-1.5 py-2.5 text-sm rounded-lg"
                        >
                            @icon('file-text', 'w-4 h-4')
                            {{ __('forms.save') }}
                        </button>

                        <button type="submit"
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-50 cursor-not-allowed"
                                class="button-primary flex-1 text-center py-2.5 text-sm rounded-lg"
                        >
                            {{ __('forms.create') }}
                        </button>
                    </div>
                </div>
            @endif
        </form>
    </div>
</x-layouts.patient>
