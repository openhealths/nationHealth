<fieldset class="fieldset" x-data="{
    localEpisodes: $wire.entangle('form.episodes'),
    localMedicalRecords: $wire.entangle('form.medical_records'),

    openModal: false,
    openMedicalModal: false,
    searchType: 'ehealth',
    modalTarget: '',
    isNew: true,
    itemIndex: 0,
    modalForm: { date: '', name: '' },

    initAdd(target) {
        this.modalTarget = target;
        this.isNew = true;
        this.modalForm = {
            date: new Date().toISOString().split('T')[0],
            name: ''
        };
        this.openModal = true;
    },

    initEdit(target, index) {
        this.modalTarget = target;
        this.isNew = false;
        this.itemIndex = index;
        let source = target === 'episode' ? this.localEpisodes : this.localMedicalRecords;
        this.modalForm = { ...source[index] };
        this.openModal = true;
    },

    save() {
        let list = this.modalTarget === 'episode' ? this.localEpisodes : this.localMedicalRecords;
        if (this.isNew) {
            list.push({...this.modalForm});
        } else {
            list[this.itemIndex] = {...this.modalForm};
        }
        this.openModal = false;
    },

    saveMedical() {
        if (this.searchType === 'current') {
            this.localMedicalRecords.push({
                date: new Date().toLocaleDateString('uk-UA'),
                name: '{{ __('care-plan.current_interaction') }}'
            });
        } else {
            this.localMedicalRecords.push({
                date: new Date().toLocaleDateString('uk-UA'),
                name: '{{ __('care-plan.medical_record_from_ehealth') }}'
            });
        }
        this.openMedicalModal = false;
    },

    removeEntry(type, index) {
        if (type === 'episode') this.localEpisodes.splice(index, 1);
        else this.localMedicalRecords.splice(index, 1);
    }
}">
    <legend class="legend">
        {{ __('care-plan.supporting_information') }}
    </legend>

    <div class="mt-4 space-y-10">
        <div class="overflow-x-auto">
            <template x-if="localEpisodes.length > 0">
                <table class="w-full mb-4 text-left border-collapse">
                    <thead>
                    <tr class="text-xs uppercase text-gray-400 border-b border-gray-100">
                        <th class="py-3 px-2 font-medium w-32">{{ __('care-plan.date') }}</th>
                        <th class="py-3 px-2 font-medium">{{ __('care-plan.name_episode') }}</th>
                        <th class="py-3 px-2 font-medium w-24 text-right">{{ __('forms.action') }}</th>
                    </tr>
                    </thead>
                    <tbody class="text-sm">
                    <template x-for="(item, index) in localEpisodes" :key="'ep-'+index">
                        <tr class="group hover:bg-gray-50 transition-colors cursor-pointer" @click="initEdit('episode', index)">
                            <td class="py-4 px-2 text-gray-600" x-text="item.date"></td>
                            <td class="py-4 px-2 text-gray-800" x-text="item.name"></td>
                            <td class="py-4 px-2 text-right">
                                <button type="button" @click.stop="removeEntry('episode', index)" class="svg-hover-action">
                                    @icon('delete', 'w-5 h-5 text-red-600')
                                </button>
                            </td>
                        </tr>
                    </template>
                    </tbody>
                </table>
            </template>
            <button type="button" @click="initAdd('episode')" class="item-add flex items-center">
                {{ __('care-plan.add_episode') }}
            </button>
        </div>

        <div class="overflow-x-auto">
            <template x-if="localMedicalRecords.length > 0">
                <table class="w-full mb-4 text-left border-collapse">
                    <thead>
                    <tr class="text-xs uppercase text-gray-400 border-b border-gray-100">
                        <th class="py-3 px-2 font-medium w-32">{{ __('care-plan.date') }}</th>
                        <th class="py-3 px-2 font-medium">{{ __('care-plan.medical_record') }}</th>
                        <th class="py-3 px-2 font-medium w-24 text-right">{{ __('forms.action') }}</th>
                    </tr>
                    </thead>
                    <tbody class="text-sm">
                    <template x-for="(item, index) in localMedicalRecords" :key="'mr-'+index">
                        <tr class="group hover:bg-gray-50 transition-colors cursor-pointer" @click="initEdit('medical', index)">
                            <td class="py-4 px-2 text-gray-600" x-text="item.date"></td>
                            <td class="py-4 px-2 text-gray-800" x-text="item.name"></td>
                            <td class="py-4 px-2 text-right">
                                <button type="button" @click.stop="removeEntry('medical', index)" class="svg-hover-action">
                                    @icon('delete', 'w-5 h-5 text-red-600')
                                </button>
                            </td>
                        </tr>
                    </template>
                    </tbody>
                </table>
            </template>
            <button type="button" @click="openMedicalModal = true" class="item-add flex items-center">
                {{ __('care-plan.add_medical_record') }}
            </button>
        </div>
    </div>

    <template x-teleport="body">
        <div x-show="openModal"
             style="display: none"
             class="modal"
             @keydown.escape.prevent.stop="openModal = false">
            <div x-show="openModal" x-transition.opacity class="fixed inset-0 bg-black/25"></div>
            <div x-show="openModal" x-transition @click="openModal = false"
                 class="relative flex min-h-screen items-center justify-center p-4">
                <div @click.stop x-trap.noscroll.inert="openModal"
                     class="modal-content h-fit w-full max-w-2xl rounded-2xl shadow-lg bg-white">
                    <h3 class="modal-header !flex !justify-start gap-2">
                        <span x-text="isNew ? '{{ __('forms.add') }}' : '{{ __('forms.edit') }}'"></span>
                        <span x-text="modalTarget === 'episode' ? '{{ mb_strtolower(__('care-plan.episode')) }}' : '{{ mb_strtolower(__('care-plan.medical_record')) }}'"></span>
                    </h3>
                    <form @submit.prevent="save()">
                        <div class="p-6 space-y-4">
                            <div class="relative">
                                <svg class="svg-input absolute left-1 !top-2/3 transform -translate-y-1/2 pointer-events-none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M6 5V4a1 1 0 1 1 2 0v1h3V4a1 1 0 1 1 2 0v1h3V4a1 1 0 1 1 2 0v1h1a2 2 0 0 1 2 2v2H3V7a2 2 0 0 1 2-2h1ZM3 19v-8h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Zm5-6a1 1 0 1 0 0 2h8a1 1 0 1 0 0-2H8Z" clip-rule="evenodd"/>
                                </svg>
                                <label for="documentIssuedAt" class="label-modal">{{__('forms.date')}}<span class="text-red-600"> *</span></label>
                                <input x-model="modalDocument.issuedAt"
                                       datepicker-format="{{ frontendDateFormat() }}"
                                       type="text" name="documentIssuedAt"
                                       id="documentIssuedAt"
                                       class="input-modal datepicker-input"
                                       autocomplete="off">
                            </div>
                            <div>
                                <label class="label-modal">{{ __('care-plan.name_description') }} <span class="text-red-600">*</span></label>
                                <input type="text" x-model="modalForm.name"
                                       :placeholder="modalTarget === 'episode' ? '{{ __('care-plan.episode_name_placeholder') }}' : '{{ __('care-plan.record_name_placeholder') }}'"
                                       class="input-modal w-full" required>
                            </div>
                        </div>
                        <div class="mt-6 flex flex-row items-center gap-4 border-t border-gray-200 p-6">
                            <button type="button" @click="openModal = false" class="button-minor">
                                {{__('forms.cancel')}}
                            </button>
                            <button type="submit" class="button-primary" :disabled="!modalForm.date || !modalForm.name">
                                {{__('forms.save')}}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    <template x-teleport="body">
        <div x-show="openMedicalModal"
             style="display: none"
             class="modal"
             @keydown.escape.prevent.stop="openMedicalModal = false">
            <div x-show="openMedicalModal" x-transition.opacity class="fixed inset-0 bg-black/25"></div>
            <div x-show="openMedicalModal" x-transition @click="openMedicalModal = false"
                 class="relative flex min-h-screen items-center justify-center p-4">
                <div @click.stop x-trap.noscroll.inert="openMedicalModal"
                     class="modal-content h-fit w-full max-w-2xl rounded-2xl shadow-lg bg-white">

                    <div class="p-6">
                        <form @submit.prevent="saveMedical()">
                        <fieldset class="fieldset">
                            <legend class="legend">
                                {{ __('care-plan.search_medical_records') ?? 'Пошук медичних записів' }}
                            </legend>

                            <div class="flex mt-2">
                                <div class="flex items-center me-6">
                                    <input id="current-interaction" type="radio" value="current" x-model="searchType" name="search-type"
                                           class="w-4 h-4 text-neutral-primary border-default-medium bg-neutral-secondary-medium rounded-full checked:border-brand focus:ring-2 focus:outline-none focus:ring-brand-subtle border border-default appearance-none">
                                    <label for="current-interaction" class="select-none ms-2 text-sm font-medium text-heading whitespace-nowrap">
                                        {{ __('care-plan.current_interaction') }}
                                    </label>
                                </div>

                                <div class="flex items-center">
                                    <input id="search-ehealth" type="radio" value="ehealth" x-model="searchType" name="search-type"
                                           class="w-4 h-4 text-neutral-primary border-default-medium bg-neutral-secondary-medium rounded-full checked:border-brand focus:ring-2 focus:outline-none focus:ring-brand-subtle border border-default appearance-none">
                                    <label for="search-ehealth" class="select-none ms-2 text-sm font-medium text-heading whitespace-nowrap">
                                        {{ __('care-plan.search_in_ehealth') }}
                                    </label>
                                </div>
                            </div>
                        </fieldset>

                        <div x-show="searchType === 'ehealth'">
                            <fieldset class="fieldset">

                                <div class="mb-8 flex items-center gap-1 font-semibold text-gray-900 dark:text-white">
                                    @icon('search-outline', 'w-4.5 h-4.5')
                                    <p>{{ __('care-plan.search') }}</p>
                                </div>

                                <div class="form-row-2" x-data="{
                                   open: false,
                                   selectedType: $wire.entangle('medical_record_type'),
                                   types: {
                                   'CONDITION': 'Стани/діагнози',
                                   'OBSERVATION': 'Спостереження'
                                   }
                                   }">

                                    <div class="relative">
                                        <input type="text"
                                               id="recordTypeFilter"
                                               class="input peer w-full cursor-pointer text-gray-500 dark:text-gray-400"
                                               x-on:click="open = !open"
                                               :value="types[selectedType] || '{{ __('forms.select_type') }}'"
                                               readonly />

                                        <svg class="w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400 pointer-events-none"
                                             fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path d="M19 9l-7 7-7-7"></path>
                                        </svg>

                                        <div x-show="open"
                                             x-on:click.away="open = false"
                                             x-transition:enter="transition ease-out duration-100"
                                             x-transition:enter-start="transform opacity-0 scale-95"
                                             x-transition:enter-end="transform opacity-100 scale-100"
                                             x-transition:leave="transition ease-in duration-75"
                                             x-transition:leave-start="transform opacity-100 scale-100"
                                             x-transition:leave-end="transform opacity-0 scale-95"
                                             class="absolute z-10 mt-2 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg"
                                             x-cloak>

                                            <ul class="py-2 text-sm text-gray-700 dark:text-gray-200">
                                                <li class="px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600 cursor-pointer"
                                                    @click="selectedType = 'CONDITION'; open = false">
                                                    {{ __('care-plan.conditions/diagnoses') }}
                                                </li>

                                                <li class="px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600 cursor-pointer"
                                                    @click="selectedType = 'OBSERVATION'; open = false">
                                                    {{ __('care-plan.observations') }}
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="form-group group">
                                        <label for="episode" class="label">
                                            {{ __('care-plan.episode') }}
                                        </label>

                                        <select id="episode"
                                                name="episode"
                                                class="input-select peer"
                                                type="text"
                                        >
                                            <option selected value="">{{ __('forms.select') }}</option>
                                        </select>

                                        @error('care-plan.episode')
                                        <p class="text-error">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>
                            </fieldset>
                        </div>

                        <div class="mt-6 flex flex-row items-center gap-4 border-t border-gray-200 p-6">
                            <button type="button" @click="openMedicalModal = false" class="button-minor">
                                {{__('forms.cancel')}}
                            </button>
                            <button type="submit" class="button-primary">
                                {{__('forms.save')}}
                            </button>
                        </div>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </template>
</fieldset>
