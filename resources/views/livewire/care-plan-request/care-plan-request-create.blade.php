<x-layouts.patient
    :showLegacyMessages="false"
    :personId="$personId"
    :uuid="$uuid"
    :patientFullName="$patientFullName"
    :hideNavigation="true"
    :breadcrumbs="[
        ['label' => __('general.home'), 'url' => route('dashboard', [legalEntity()])],
        ['label' => $patientFullName]
    ]"
>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <x-slot name="headerActions"></x-slot>

    <div class="shift-content mt-6 pl-4">
        <div class="w-full max-w-screen-xl">
            @if ($savedRequestId !== null)
                <div class="record-inner-card space-y-4 p-4">
                    <p role="status">
                        {{ __('care-plan.draft_created_awaiting_signature', ['id' => $savedRequestId]) }}
                    </p>

                    <div class="flex flex-wrap gap-4">
                        <a
                            href="{{ route('persons.encounters.package.show', [legalEntity(), $personId, $encounterId]) }}"
                            class="button-primary-outline"
                        >
                            {{ __('forms.back') }}
                        </a>
                        <button
                            type="button"
                            wire:click="prepareForSigning"
                            wire:loading.attr="disabled"
                            wire:target="prepareForSigning"
                            class="button-primary"
                        >
                            {{ __('forms.sign') }}
                        </button>
                    </div>
                </div>
            @else
                <form wire:submit="save" method="post">
                    @csrf
                    @include('livewire.care-plan.parts.doctors')
                    @include('livewire.care-plan.parts.patient_data', ['allowsPatientChange' => false])
                    @include('livewire.care-plan.parts.care_plan_data')
                    @include('livewire.care-plan.parts.condition_diagnosis')

                    <fieldset class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800">
                        <legend class="legend">{{ __('care-plan.supporting_information') }}</legend>

                        <div class="mt-4 space-y-10">
                            @foreach ([
                                'episodes' => [array_column($availableEpisodes, null, 'uuid'), 'name_episode', 'add_episode'],
                                'medicalRecords' => [$medicalRecords, 'medical_record', 'add_medical_record'],
                            ] as $field => [$records, $label, $addLabel])
                                <div
                                    class="space-y-4"
                                    x-data="{
                                        selected: $wire.entangle(@js('form.' . $field)),
                                        records: @js((object) $records),
                                        choice: '',
                                        choosing: false
                                    }"
                                >
                                    <template x-if="selected.length">
                                        <div class="index-table-wrapper overflow-x-auto">
                                            <table class="index-table">
                                                <thead class="index-table-thead">
                                                    <tr>
                                                        @if ($field === 'episodes')
                                                            <th scope="col" class="index-table-th w-32">{{ __('care-plan.date') }}</th>
                                                        @endif
                                                        <th scope="col" class="index-table-th">{{ __('care-plan.' . $label) }}</th>
                                                        <th scope="col" class="index-table-th w-24 text-right">{{ __('forms.action') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="key in selected" :key="key">
                                                        <tr class="index-table-tr">
                                                            @if ($field === 'episodes')
                                                                <td class="index-table-td" x-text="records[key]?.date"></td>
                                                            @endif
                                                            <td class="index-table-td-primary" x-text="records[key]?.name"></td>
                                                            <td class="index-table-td text-right">
                                                                <button
                                                                    type="button"
                                                                    @click="selected = selected.filter((entry) => entry !== key)"
                                                                    class="svg-hover-action"
                                                                    aria-label="{{ __('forms.delete') }}"
                                                                >
                                                                    @icon('delete', 'w-5 h-5 text-red-600')
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </template>

                                    <button
                                        type="button"
                                        class="item-add ml-1 flex items-center"
                                        x-ref="add"
                                        :aria-expanded="choosing"
                                        aria-controls="care-plan-request-choice-{{ $field }}"
                                        @click="choosing = !choosing; if (choosing) $nextTick(() => $refs.choice.focus())"
                                    >
                                        {{ __('care-plan.' . $addLabel) }}
                                    </button>

                                    <div id="care-plan-request-choice-{{ $field }}" x-show="choosing" x-cloak>
                                        <label for="care-plan-request-{{ $field }}" class="label-modal">
                                            {{ __('care-plan.' . $label) }}
                                        </label>
                                        <div class="flex flex-wrap items-center gap-4">
                                            <select
                                                id="care-plan-request-{{ $field }}"
                                                x-ref="choice"
                                                x-model="choice"
                                                class="input-select"
                                            >
                                                <option value="">{{ __('forms.select') }}</option>
                                                @foreach ($records as $key => $record)
                                                    <option value="{{ $key }}" :disabled="selected.includes($el.value)">
                                                        {{ $record['name'] }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <button
                                                type="button"
                                                class="button-minor"
                                                :disabled="!choice || selected.includes(choice)"
                                                @click="selected = [...selected, choice]; choice = ''; choosing = false; $nextTick(() => $refs.add.focus())"
                                            >
                                                {{ __('forms.add') }}
                                            </button>
                                        </div>
                                    </div>
                                    @error('form.' . $field)
                                        <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                    </fieldset>

                    @include('livewire.care-plan.parts.additional_info', ['context' => 'create'])

                    @if ($errors->any())
                        <p
                            role="alert"
                            class="text-error mt-6"
                            tabindex="-1"
                            x-data
                            x-init="$nextTick(() => $el.focus())"
                        >
                            {{ $errors->first() }}
                        </p>
                    @endif

                    <div class="mt-8 flex flex-wrap items-center gap-4 pt-4">
                        <a
                            href="{{ route('persons.encounters.package.show', [legalEntity(), $personId, $encounterId]) }}"
                            class="button-primary-outline-red px-6 py-2.5"
                        >
                            {{ __('forms.cancel') }}
                        </a>

                        <button
                            type="submit"
                            class="button-primary-outline flex items-center gap-2 px-6 py-2.5"
                            wire:loading.attr="disabled"
                            wire:target="save, prepareForSigning"
                        >
                            @icon('archive', 'w-4 h-4')
                            <span>{{ __('forms.save') }}</span>
                        </button>

                        <button
                            type="button"
                            wire:click="prepareForSigning"
                            wire:loading.attr="disabled"
                            wire:target="save, prepareForSigning"
                            class="button-primary px-8 py-2.5"
                        >
                            {{ __('care-plan.create_care_plan') }}
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-layouts.patient>
