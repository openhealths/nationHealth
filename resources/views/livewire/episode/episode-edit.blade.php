<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :hideNavigation="true"
    :title="__('episodes.edit_title', ['name' => $form->name])"
>
    <x-slot name="headerActions"></x-slot>

    <div class="breadcrumb-form p-4 sm:p-8 shift-content max-w-4xl">
        <form
            class="space-y-6"
            @if($isDraft)
                x-data="{
                    careManagerId: $wire.form.careManagerId,
                    employeeEpisodeTypes: {{ json_encode($employeeEpisodeTypes) }},
                    isTypeAllowed(code) {
                        if (this.careManagerId === '') {
                            return true;
                        }

                        return (this.employeeEpisodeTypes[this.careManagerId] ?? []).includes(code);
                    },
                    onCareManagerChange(uuid) {
                        this.careManagerId = uuid;

                        if (!this.isTypeAllowed($wire.form.typeCode)) {
                            this.$refs.typeCode.value = '';
                            $wire.set('form.typeCode', '', false);
                        }
                    }
                }"
            @endif
        >
            <div class="form-row-2">
                <div class="form-group group">
                    <input
                        wire:model="form.name"
                        type="text"
                        name="name"
                        id="name"
                        class="input peer @error('form.name') input-error @enderror"
                        placeholder=" "
                        required
                        autocomplete="off"
                    />
                    <label for="name" class="label">{{ __('episodes.name') }}</label>

                    @error('form.name')
                    <p class="text-error mt-1 text-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            @if($isDraft)
                <div class="form-row-2">
                    <div class="form-group group">
                        <select
                            class="input-select peer @error('form.typeCode') input-error @enderror"
                            wire:model="form.typeCode"
                            x-ref="typeCode"
                            name="typeCode"
                            id="typeCode"
                            required
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach($episodeTypes as $code => $display)
                                <option
                                    value="{{ $code }}"
                                    class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white"
                                    x-show="isTypeAllowed('{{ $code }}')"
                                    x-bind:disabled="!isTypeAllowed('{{ $code }}')"
                                >
                                    {{ $display }}
                                </option>
                            @endforeach
                        </select>
                        <label for="typeCode" class="label">{{ __('episodes.type') }}</label>

                        @error('form.typeCode')
                        <p class="text-error mt-1 text-xs">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="form-group group">
                            <div class="relative">
                                <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                                    @icon('calendar-week', 'w-5 h-5 text-gray-400')
                                </div>
                                <input
                                    wire:model="form.startDate"
                                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                                    type="text"
                                    name="startDate"
                                    id="startDate"
                                    class="datepicker-input with-leading-icon input peer @error('form.startDate') input-error @enderror"
                                    placeholder=" "
                                    required
                                    autocomplete="off"
                                />
                                <label for="startDate" class="wrapped-label">{{ __('forms.start_date') }}</label>
                            </div>

                            @error('form.startDate')
                            <p class="text-error mt-1 text-xs">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="form-group group">
                            <div class="relative">
                                <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                                    @icon('mingcute-time-fill', 'w-5 h-5 text-gray-400')
                                </div>
                                <input
                                    wire:model="form.startTime"
                                    type="text"
                                    name="startTime"
                                    id="startTime"
                                    class="timepicker-uk with-leading-icon input peer @error('form.startTime') input-error @enderror"
                                    placeholder=" "
                                    required
                                    autocomplete="off"
                                />
                                <label for="startTime" class="wrapped-label">{{ __('forms.start_time') }}</label>
                            </div>

                            @error('form.startTime')
                            <p class="text-error mt-1 text-xs">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            @endif

            <div class="form-row-2">
                <div class="form-group group">
                    <select
                        class="input-select peer @error('form.careManagerId') input-error @enderror"
                        wire:model="form.careManagerId"
                        @if($isDraft) x-on:change="onCareManagerChange($event.target.value)" @endif
                        name="careManagerId"
                        id="careManagerId"
                        required
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach($employees as $employee)
                            @continue(!$isDraft && !in_array($form->typeCode, $employeeEpisodeTypes[$employee['uuid']] ?? [], true))
                            <option value="{{ $employee['uuid'] }}" class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white">
                                {{ $employee['name'] }} ({{ $this->dictionaries['POSITION'][$employee['position']] }})
                            </option>
                        @endforeach
                    </select>
                    <label for="careManagerId" class="label">
                        {{ __('episodes.attending_doctor') }}
                    </label>

                    @error('form.careManagerId')
                    <p class="text-error mt-1 text-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="form-row-3 pt-8">
                <div class="flex gap-3 col-span-1">
                    @if($isDraft)
                        <button
                            type="button"
                            x-on:click="$dispatch('open-draft-deletion')"
                            class="button-primary-outline-red flex-1 text-center py-2.5 text-sm rounded-lg"
                        >
                            {{ __('episodes.delete_draft') }}
                        </button>

                        <button
                            type="button"
                            wire:click="save"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50 cursor-not-allowed"
                            class="button-primary-outline flex-1 flex items-center justify-center gap-1.5 py-2.5 text-sm rounded-lg"
                        >
                            @icon('file-text', 'w-4 h-4')
                            {{ __('forms.save') }}
                        </button>

                        <button
                            type="button"
                            wire:click="sendToEHealth"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50 cursor-not-allowed"
                            class="button-primary flex-1 text-center py-2.5 text-sm rounded-lg"
                        >
                            {{ __('episodes.send_to_ehealth') }}
                        </button>
                    @else
                        <button
                            type="button"
                            wire:click="cancel"
                            class="button-primary-outline-red flex-1 text-center py-2.5 text-sm rounded-lg"
                        >
                            {{ __('episodes.cancel_changes') }}
                        </button>

                        <button
                            type="button"
                            wire:click="save"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50 cursor-not-allowed"
                            class="button-primary flex-1 text-center py-2.5 text-sm rounded-lg"
                        >
                            {{ __('episodes.save_changes') }}
                        </button>
                    @endif
                </div>
            </div>
        </form>
    </div>

    @if($isDraft)
        <div x-data="{ open: false }"
             x-on:open-draft-deletion.window="open = true"
        >
            <template x-teleport="body">
                <div x-show="open"
                     x-cloak
                     role="dialog"
                     aria-modal="true"
                     class="modal"
                     x-on:keydown.escape.prevent.stop="open = false"
                >
                    <div x-transition.opacity
                         class="fixed inset-0 bg-black/30"
                         x-on:click="open = false"
                    ></div>

                    <div class="modal-wrapper">
                        <div class="modal-content w-full max-w-xl mx-auto bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
                             x-on:click.stop
                             x-transition
                             x-trap.noscroll.inert="open"
                        >
                            <h3 class="mb-4 text-xl font-bold text-gray-900 dark:text-white">
                                {{ __('episodes.delete_draft_title') }}
                            </h3>

                            <p class="mb-6 text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                                {{ __('episodes.delete_draft_description') }}
                            </p>

                            <div class="flex gap-4 justify-start items-center pt-4 border-t border-gray-200 dark:border-gray-700">
                                <button type="button"
                                        x-on:click="open = false"
                                        class="button-minor"
                                >
                                    {{ __('forms.cancel') }}
                                </button>

                                <button type="button"
                                        wire:click="deleteDraft"
                                        wire:loading.attr="disabled"
                                        wire:loading.class="opacity-50 cursor-not-allowed"
                                        wire:target="deleteDraft"
                                        class="button-danger"
                                >
                                    {{ __('episodes.delete_draft') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif

    <x-forms.loading />
</x-layouts.patient>
