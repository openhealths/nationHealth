<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :title="__('referrals.referral') . ' - ' . $patientFullName"
    :hideNavigation="true"
    :breadcrumbs="[
        ['label' => __('general.home') , 'url' => route('dashboard', [legalEntity()])],
        ['label' => __('patients.patients') , 'url' => route('persons.index', [legalEntity()])],
        ['label' => $patientFullName , 'url' => $personId ? route('persons.summary', [legalEntity(), $personId]) : '#'],
        ['label' => __('referrals.new_referral')]
    ]"
>
    <x-slot name="headerActions"></x-slot>

    <div class="shift-content mt-6 pl-4" x-data="{ showServiceSearchDrawer: @entangle('showServiceSearchDrawer'), showErrorModal: false }">
        <div class="w-full max-w-screen-xl">
            <form>
                <fieldset class="fieldset">
                    <legend class="legend">{{ __('forms.main_information') }}</legend>

                    <div class="form-row-2 mt-2">
                        <div class="form-group group">
                            <select id="service_id" class="input-select peer" wire:model="form.service_id" required>
                                <option value="">{{ __('referrals.select_service') }}</option>
                                @if(!empty($form['service_id']))
                                    <option value="{{ $form['service_id'] }}">{{ $form['service_name'] ?? $form['service_id'] }}</option>
                                @endif
                            </select>
                            <label for="service_id" class="label">{{ __('referrals.service') }}</label>
                        </div>

                        <div class="flex items-center mb-3">
                            <button type="button" @click="showServiceSearchDrawer = true" class="flex items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">
                                @icon('book-open', 'w-4 h-4')
                                {{ __('referrals.select_from_dictionary') }}
                            </button>
                        </div>
                    </div>

                    <div class="form-row-2 mt-6">
                        <div class="flex items-end gap-4">
                            <div class="form-group group w-1/2">
                                <input type="number" id="quantity" class="input peer w-full" wire:model="form.quantity" required />
                                <label for="quantity" class="label">{{ __('referrals.quantity') }}</label>
                            </div>
                            <div class="form-group group w-1/2">
                                <select id="quantity_unit" class="input-select peer w-full" wire:model="form.quantity_unit">
                                    <option value="шт">{{ __('referrals.pcs') }}</option>
                                </select>
                            </div>
                        </div>
                        <div></div>
                    </div>

                    <div class="form-row-2 mt-6">
                        <div class="form-group group">
                            <select id="category" class="input-select peer" wire:model="form.category" required>
                                <option value="consultation">{{ __('referrals.consultation') }}</option>
                            </select>
                            <label for="category" class="label">{{ __('referrals.category') }}</label>
                        </div>

                        <div class="form-group group">
                            <select id="priority" class="input-select peer" wire:model="form.priority" required>
                                <option value="routine">{{ __('referrals.priority') }}</option>
                                <option value="urgent">{{ __('referrals.urgent') }}</option>
                            </select>
                            <label for="priority" class="label">{{ __('referrals.priority') }}</label>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="fieldset mt-8">
                    <legend class="legend">{{ __('referrals.additional_info') }}</legend>

                    <div class="form-row-2 mt-2">
                        <div class="form-group group">
                            <select id="date_type" class="input-select peer" wire:model="form.date_type">
                                <option value="period">{{ __('referrals.period') }}</option>
                            </select>
                            <label for="date_type" class="label">{{ __('referrals.date_or_period_label') }}</label>
                        </div>

                        <div class="form-group group">
                            <div class="relative w-full">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    @icon('calendar', 'w-4 h-4 text-gray-400 dark:text-gray-500')
                                </div>
                                <input type="text" id="date_range" class="input peer w-full pl-10" placeholder="02.02.2026 - 10.02.2026" />
                            </div>
                        </div>
                    </div>

                    <div x-data="{ open: false, showProgramInfo: false, selectedProgram: @entangle('form.program_id') }" :class="{ 'relative z-50': open }">
                        <div class="form-row-2 mt-6">
                            <div class="form-group group relative" :class="{ 'z-50': open }">
                                <input type="text" id="program_id" class="input-select peer w-full cursor-pointer" readonly
                                       placeholder=" "
                                       :value="selectedProgram === 'pmg' ? '{{ __('referrals.pmg') }}' : ''"
                                       @click="open = !open" />
                                <label for="program_id" class="label">{{ __('referrals.program') }}</label>

                                <div class="absolute top-1/2 -translate-y-1/2 right-2 flex items-center pointer-events-none">
                                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </div>

                                <div x-show="open" @click.away="open = false" x-cloak style="display: none;" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg dark:bg-gray-700 dark:border-gray-600">
                                    <ul class="py-1">
                                        <li class="flex items-center px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600">
                                            <button type="button" @click.stop="showProgramInfo = true; open = false" class="mr-3 text-gray-400 hover:text-blue-500 transition-colors">
                                                @icon('question-mark-circle', 'w-5 h-5')
                                            </button>
                                            <span class="cursor-pointer flex-1 text-gray-900 dark:text-gray-200" @click="selectedProgram = 'pmg'; open = false">
                                                {{ __('referrals.pmg') }}
                                            </span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <div></div>
                        </div>

                        <fieldset x-show="showProgramInfo" x-cloak style="display: none;" class="fieldset mt-6 w-3/4 !p-4">
                            <legend class="legend !text-sm !font-normal">{{ __('referrals.pmg') }}</legend>
                            <p class="text-base text-gray-800 dark:text-gray-200">{{ __('referrals.mandatory_care_plan') }}</p>
                        </fieldset>
                    </div>

                    <div class="form-row-2 mt-8">
                        <div class="form-group group">
                            <input type="text" id="doctor_name" class="input peer w-full" readonly wire:model="form.doctor_name" />
                            <label for="doctor_name" class="label">{{ __('referrals.doctor') }}</label>
                        </div>

                        <div class="form-group group">
                            <input type="text" id="provider_name" class="input peer w-full" readonly wire:model="form.provider_name" />
                            <label for="provider_name" class="label">{{ __('referrals.medical_service_provider') }}</label>
                        </div>
                    </div>

                    <div class="mt-8">
                        <label for="note_doctor" class="mb-2 block text-sm font-medium text-gray-900 dark:text-white">
                            {{ __('referrals.note_doctor') }}
                        </label>
                        <textarea
                            id="note_doctor"
                            rows="4"
                            class="textarea w-full dark:border-gray-600 dark:bg-gray-700/50 dark:text-white"
                            placeholder="{{ __('referrals.placeholder_notes') }}"
                            wire:model="form.note_doctor"
                        ></textarea>
                    </div>

                    <div class="mt-6">
                        <label for="note_patient" class="mb-2 block text-sm font-medium text-gray-900 dark:text-white">
                            {{ __('referrals.note_patient') }}
                        </label>
                        <textarea
                            id="note_patient"
                            rows="4"
                            class="textarea w-full dark:border-gray-600 dark:bg-gray-700/50 dark:text-white"
                            placeholder="{{ __('referrals.placeholder_notes') }}"
                            wire:model="form.note_patient"
                        ></textarea>
                    </div>
                </fieldset>

                <fieldset class="fieldset mt-8" x-data="{
                    showReferencesDrawer: false,
                    selectedReferences: @entangle('form.supportingInfo'),

                    init() {
                        this.selectedReferences = (this.selectedReferences ?? []).filter(reference => reference?.uuid && reference?.type);
                    },
                    openReferencesDrawer() {
                        this.showReferencesDrawer = true;
                    },
                    isReferenceAdded(recordId) {
                        return this.selectedReferences.some((ref) => ref.uuid === recordId);
                    },
                    addReference(record) {
                        const typeLabels = {
                            condition: '{{ __('conditions.condition_or_diagnosis') }}',
                            observation: '{{ __('observations.medical_label') }}',
                            diagnostic_report: '{{ __('diagnostic-reports.label') }}',
                        };

                        this.selectedReferences.push({
                            uuid: record.id,
                            type: record.type,
                            display: record.title || record.name || '',
                            displayType: typeLabels[record.type] || record.type,
                            date: record.date || ''
                        });
                        this.showReferencesDrawer = false;
                    },
                    removeReference(index) {
                        this.selectedReferences.splice(index, 1);
                    },
                    cancelSelection() {
                        this.showReferencesDrawer = false;
                    }
                }"
                @encounter-supporting-info-selected.window="addReference($event.detail.record)">
                    <legend class="legend">{{ __('referrals.base_records') }}</legend>

                    <template x-if="selectedReferences.length > 0">
                        <div class="mb-4 mt-2 overflow-x-auto">
                            <table class="table-input w-full">
                                <thead class="thead-input">
                                    <tr>
                                        <th scope="col" class="td-input w-32">{{ __('forms.date')  }}</th>
                                        <th scope="col" class="td-input">{{ __('forms.name')  }}</th>
                                        <th scope="col" class="td-input w-24 text-right">{{ __('forms.action')  }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(ref, index) in selectedReferences" :key="index">
                                        <tr class="group">
                                            <td class="td-input" x-text="ref.date"></td>
                                            <td class="td-input">
                                                <span x-text="ref.displayType"></span>
                                                <span x-text="ref.display"></span>
                                            </td>
                                            <td class="td-input text-right">
                                                <button
                                                    type="button"
                                                    @click="removeReference(index)"
                                                    class="text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-500 transition-colors"
                                                >
                                                    @icon('delete', 'w-5 h-5')
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>

                    <button type="button" @click="openReferencesDrawer()" class="mt-2 flex items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">
                        + {{ __('referrals.add_conditions') }}
                    </button>

                    @include('livewire.encounter.parts.references-drawer', ['patientUuid' => $person->uuid ?? $person->id ?? ''])
                </fieldset>

                <fieldset class="fieldset mt-8" x-data="{
                    showAttentionReferencesDrawer: false,
                    showEpisodeReferencesDrawer: false,
                    selectedAttentionReferences: @entangle('form.reasonReference'),

                    init() {
                        this.selectedAttentionReferences = (this.selectedAttentionReferences ?? []).filter(reference => reference?.uuid && reference?.type);
                    },
                    openAttentionReferencesDrawer() {
                        this.showAttentionReferencesDrawer = true;
                    },
                    openEpisodeReferencesDrawer() {
                        this.showEpisodeReferencesDrawer = true;
                    },
                    isAttentionReferenceAdded(recordId) {
                        return this.selectedAttentionReferences.some((ref) => ref.uuid === recordId);
                    },
                    addAttentionReference(record) {
                        const typeLabels = {
                            condition: '{{ __('conditions.condition_or_diagnosis') }}',
                            observation: '{{ __('observations.medical_label') }}',
                            diagnostic_report: '{{ __('diagnostic-reports.label') }}',
                            episode: '{{ __('episodes.label')  }}',
                            episodes: '{{ __('episodes.label_plural') }}'
                        };

                        this.selectedAttentionReferences.push({
                            uuid: record.id,
                            type: record.type || 'episode',
                            display: record.title || record.name || '',
                            displayType: typeLabels[record.type] || record.type || '{{ __('episodes.label')  }}',
                            date: record.date || ''
                        });
                        this.showAttentionReferencesDrawer = false;
                        this.showEpisodeReferencesDrawer = false;
                    },
                    removeAttentionReference(index) {
                        this.selectedAttentionReferences.splice(index, 1);
                    },
                    cancelAttentionSelection() {
                        this.showAttentionReferencesDrawer = false;
                    },
                    cancelEpisodeSelection() {
                        this.showEpisodeReferencesDrawer = false;
                    }
                }"
                @encounter-attention-info-selected.window="addAttentionReference($event.detail.record)"
                @encounter-episode-info-selected.window="addAttentionReference($event.detail.record)">
                    <legend class="legend">{{ __("referrals.attention_records") }}</legend>

                    <template x-if="selectedAttentionReferences.length > 0">
                        <div class="mb-4 mt-2 overflow-x-auto">
                            <table class="table-input w-full">
                                <thead class="thead-input">
                                    <tr>
                                        <th scope="col" class="td-input w-32">{{ __('forms.date') }}</th>
                                        <th scope="col" class="td-input">{{ __('forms.name') }}</th>
                                        <th scope="col" class="td-input w-24 text-right">{{ __('forms.action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(ref, index) in selectedAttentionReferences" :key="index">
                                        <tr class="group">
                                            <td class="td-input" x-text="ref.date"></td>
                                            <td class="td-input">
                                                <span x-text="ref.displayType"></span>
                                                <span x-text="ref.display"></span>
                                            </td>
                                            <td class="td-input text-right">
                                                <button
                                                    type="button"
                                                    @click="removeAttentionReference(index)"
                                                    class="text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-500 transition-colors"
                                                >
                                                    @icon('delete', 'w-5 h-5')
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>

                    <div class="mt-2 flex flex-col items-start gap-4">
                        <button type="button" @click="openEpisodeReferencesDrawer()" class="flex items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">
                            + {{ __("referrals.add_episodes") }}
                        </button>
                        <button type="button" @click="openAttentionReferencesDrawer()" class="flex items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">
                            + {{ __("referrals.add_attention_records") }}
                        </button>
                    </div>

                    @include('livewire.encounter.parts.references-drawer', [
                        'patientUuid' => $person->uuid ?? $person->id ?? '',
                        'selectionEvent' => 'encounter-attention-info-selected',
                        'showProperty' => 'showAttentionReferencesDrawer',
                        'cancelMethod' => 'cancelAttentionSelection',
                        'isAddedMethod' => 'isAttentionReferenceAdded',
                        'componentKey' => 'encounter-attention-info-search'
                    ])

                    @include('livewire.encounter.parts.references-drawer', [
                        'patientUuid' => $person->uuid ?? $person->id ?? '',
                        'selectionEvent' => 'encounter-episode-info-selected',
                        'showProperty' => 'showEpisodeReferencesDrawer',
                        'cancelMethod' => 'cancelEpisodeSelection',
                        'isAddedMethod' => 'isAttentionReferenceAdded',
                        'componentKey' => 'encounter-episode-info-search',
                        'recordTypes' => ['episodes'],
                        'title' => __('episodes.search') 
                    ])
                </fieldset>

                <div class="mb-10 mt-8 flex items-center gap-4">
                    <button type="button" @click="showErrorModal = true" class="button-primary-outline-red px-6 py-2.5">
                        {{ __('referrals.actions.delete_request')  }}
                    </button>
                    <button type="button" class="button-primary-outline px-6 py-2.5 flex items-center gap-2">
                        @icon('archive', 'w-5 h-5')
                        <span>{{ __('referrals.actions.save')  }}</span>
                    </button>
                    <button type="button" wire:click="save" class="button-primary px-8 py-2.5">
                        {{ __('referrals.actions.create_en')  }}
                    </button>
                </div>
            </form>
        </div>

        @include('livewire.care-plan.parts.modals.service-search-drawer')

                <div x-show="showErrorModal" style="display: none" @keydown.escape.prevent.stop="showErrorModal = false" role="dialog" aria-modal="true" class="modal">
                        <div x-show="showErrorModal" x-transition.opacity class="fixed inset-0 bg-black/25"></div>

                        <div x-show="showErrorModal" x-transition @click="showErrorModal = false" class="relative flex min-h-screen items-center justify-center p-4">
                <div @click.stop x-trap.noscroll.inert="showErrorModal" class="modal-content h-fit w-full max-w-4xl rounded-2xl shadow-lg bg-white">

                                        <h3 class="modal-header border-b-0 pb-0 pt-8 px-10 text-xl font-bold">{{ __('forms.error') }}</h3>

                                        <div class="px-10 pb-10 pt-6">
                        <div class="rounded-lg bg-red-50 p-4 mb-8 flex items-start gap-3">
                            <div class="mt-0.5">
                                @icon('info-circle', 'w-5 h-5 text-red-500')
                            </div>
                            <h3 class="text-sm font-medium text-red-600">
                                {{ __('referrals.assistant_error') }}
                            </h3>
                        </div>

                                                <div class="flex items-center">
                            <button type="button" @click="showErrorModal = false" class="button-primary px-6 py-2.5">
                                {{ __('referrals.return_to_en') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.patient>
