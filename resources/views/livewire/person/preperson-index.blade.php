@use('App\Models\Person\PersonRequest')

<div x-data="
         {
             searchId: '',
             searchName: '',
             searchBirthDate: '',
             searchSecondName: '',
             searchTaxId: '',
             searchPhoneNumber: '',
             searchBirthCertificate: '',
             showAdditionalParams: false,

             // Currently active filters (applied on click 'Search')
             activeId: '',
             activeName: '',
             activeBirthDate: '',
             activeSecondName: '',
             activeTaxId: '',
             activePhoneNumber: '',
             activeBirthCertificate: '',

             mockPatients: [
                 {
                     id: 1,
                     uuid: 'cf24a58a-1314-4c45-a5a9-9c43dc871898',
                     external_id: '31232132131111321',
                     first_name: 'Олександр',
                     last_name: '',
                     second_name: '',
                     gender: 'MALE',
                     birth_date: '1814-03-09',
                     phone: '+380901212312',
                     note: '№ картки виїзду швидкої медичної допомоги КА123112',
                     status: 'active'
                 },
                 {
                     id: 2,
                     uuid: 'af12b34c-5678-40de-a123-4567890abcde',
                     external_id: '1232321321321313',
                     first_name: 'Марія',
                     last_name: '',
                     second_name: '',
                     gender: 'FEMALE',
                     birth_date: '1985-05-15',
                     phone: '+380951112233',
                     note: 'Госпіталізація пацієнта бригадою екстреної медичної допомоги',
                     status: 'active'
                 },
                 {
                     id: 3,
                     uuid: 'b1234567-89ab-cdef-0123-456789abcdef',
                     external_id: '31232132131111322',
                     first_name: 'Іван',
                     last_name: '',
                     second_name: '',
                     gender: 'MALE',
                     birth_date: '1990-10-10',
                     phone: '+380991234567',
                     note: 'Пацієнт на момент реєстрації не має документів, що посвідчують особу',
                     status: 'active'
                 }
             ],

             get filteredPatients() {
                 return this.mockPatients.filter(patient => {
                     if (this.activeId) {
                         let s = this.activeId.toLowerCase();
                         if (!patient.uuid.toLowerCase().includes(s) && !patient.external_id.toLowerCase().includes(s)) {
                             return false;
                         }
                     }
                     if (this.activeName) {
                         let s = this.activeName.toLowerCase();
                         if (!patient.first_name.toLowerCase().includes(s) &&
                             !patient.last_name.toLowerCase().includes(s) &&
                             !patient.second_name.toLowerCase().includes(s)) {
                             return false;
                         }
                     }
                     if (this.activeBirthDate) {
                         let formattedSearchDate = this.activeBirthDate;
                         let parts = this.activeBirthDate.split('.');
                         if (parts.length === 3) {
                             formattedSearchDate = `${parts[2]}-${parts[1].padStart(2, '0')}-${parts[0].padStart(2, '0')}`;
                         }
                         if (patient.birth_date !== formattedSearchDate) {
                             return false;
                         }
                     }
                     if (this.activeSecondName) {
                         let s = this.activeSecondName.toLowerCase();
                         if (!patient.second_name.toLowerCase().includes(s)) {
                             return false;
                         }
                     }
                     if (this.activeTaxId) {
                         let s = this.activeTaxId.toLowerCase();
                         if (!patient.external_id.toLowerCase().includes(s)) {
                             return false;
                         }
                     }
                     return true;
                 });
             },

             search() {
                 this.activeId = this.searchId;
                 this.activeName = this.searchName;
                 this.activeBirthDate = this.searchBirthDate;
                 this.activeSecondName = this.searchSecondName;
                 this.activeTaxId = this.searchTaxId;
                 this.activePhoneNumber = this.searchPhoneNumber;
                 this.activeBirthCertificate = this.searchBirthCertificate;
             },

             reset() {
                 this.searchId = '';
                 this.searchName = '';
                 this.searchBirthDate = '';
                 this.searchSecondName = '';
                 this.searchTaxId = '';
                 this.searchPhoneNumber = '';
                 this.searchBirthCertificate = '';
                 this.activeId = '';
                 this.activeName = '';
                 this.activeBirthDate = '';
                 this.activeSecondName = '';
                 this.activeTaxId = '';
                 this.activePhoneNumber = '';
                 this.activeBirthCertificate = '';
                 this.showAdditionalParams = false;
             },

             formatDate(dateStr) {
                 if (!dateStr) return '';
                 let parts = dateStr.split('-');
                 if (parts.length === 3) {
                     return `${parseInt(parts[2])}.${parseInt(parts[1])}.${parts[0]}`;
                 }
                 return dateStr;
             },

             triggerAction(type, msg) {
                 Livewire.dispatch('flashMessage', { type: type, message: msg });
             },

             registerDeath(uuid) {
                 let patient = this.mockPatients.find(p => p.uuid === uuid);
                 if (patient) {
                     patient.status = 'inactive';
                     this.triggerAction('success', 'Смерть пацієнта успішно зареєстровано.');
                 }
             }
         }
     "
>
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">{{ __('patients.unidentified_patients') }}</x-slot>
        <x-slot name="navigation">
            <div class="flex items-center gap-4 justify-end mb-8">
                @can('create', PersonRequest::class)
                    <a href="{{ route('persons.create', [legalEntity(), 'type' => 'unidentified']) }}"
                       class="text-blue-600 hover:text-blue-800 font-medium flex items-center gap-1.5 text-sm"
                    >
                        @icon('plus', 'w-4 h-4')
                        {{ __('patients.unidentified_patient') }}
                    </a>

                    <a href="{{ route('persons.create', [legalEntity()]) }}"
                       class="button-primary flex items-center gap-2"
                    >
                        @icon('plus', 'w-4 h-4')
                        {{ __('patients.add_patient') }}
                    </a>
                @endcan
            </div>

            <div class="mb-8 flex items-center gap-1 font-semibold text-gray-900 dark:text-white">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('patients.patient_search') }}</p>
            </div>

            <div x-data="
                     {
                         focusNext(el) {
                             let container = el.closest('.breadcrumb-form, [x-data]');
                             if (!container) return;
                             let elements = Array.from(container.querySelectorAll('input:not([readonly]):not([type=hidden]), button.button-primary')).filter(e => e.offsetWidth > 0 && e.offsetHeight > 0);
                             let index = elements.indexOf(el);
                             if (index > -1 && elements[index + 1]) {
                                 elements[index + 1].focus();
                             }
                         }
                     }
                 "
            >
                <div class="form-row-3">
                    <div class="form-group group">
                        <input
                            x-model="searchId"
                            type="text"
                            name="searchId"
                            id="searchId"
                            class="input peer"
                            placeholder=" "
                            autocomplete="off"
                            x-on:keydown.enter.prevent="focusNext($el)"
                        />
                        <label for="searchId" class="label">ID</label>
                    </div>

                    <div class="form-group group">
                        <input
                            x-model="searchName"
                            type="text"
                            name="searchName"
                            id="searchName"
                            class="input peer"
                            placeholder=" "
                            autocomplete="off"
                            x-on:keydown.enter.prevent="focusNext($el)"
                        />
                        <label for="searchName" class="label">{{ __('patients.patient_full_name') }}</label>
                    </div>

                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input
                                x-model="searchBirthDate"
                                x-on:change="searchBirthDate = $event.target.value"
                                datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                                type="text"
                                name="searchBirthDate"
                                id="searchBirthDate"
                                class="datepicker-input with-leading-icon input peer"
                                placeholder=" "
                                autocomplete="off"
                                x-on:keydown.enter.prevent="focusNext($el)"
                            />
                            <label for="searchBirthDate" class="wrapped-label">
                                {{ __('forms.birth_date') }}
                            </label>
                        </div>
                    </div>
                </div>

                <div>
                    <button type="button"
                            class="flex items-center gap-2 button-minor mb-2"
                            @click.prevent="showAdditionalParams = !showAdditionalParams"
                    >
                        @icon('adjustments', 'w-4 h-4')
                        <span>{{ __('forms.additional_search_parameters') }}</span>
                    </button>

                    <div x-show="showAdditionalParams" x-transition x-cloak>
                        <div class="form-row-3">
                            <div class="form-group group">
                                <input
                                    x-model="searchSecondName"
                                    type="text"
                                    name="searchSecondName"
                                    id="searchSecondName"
                                    class="input peer"
                                    placeholder=" "
                                    autocomplete="off"
                                    x-on:keydown.enter.prevent="focusNext($el)"
                                />
                                <label for="searchSecondName" class="label">
                                    {{ __('forms.second_name') }}
                                </label>
                            </div>

                            <div class="form-group group">
                                <input
                                    x-model="searchTaxId"
                                    type="text"
                                    name="searchTaxId"
                                    id="searchTaxId"
                                    class="input peer"
                                    placeholder=" "
                                    maxlength="10"
                                    autocomplete="off"
                                    x-on:keydown.enter.prevent="focusNext($el)"
                                />
                                <label for="searchTaxId" class="label">
                                    {{ __('forms.rnokpp') }} ({{ __('forms.ipn') }})
                                </label>
                            </div>
                        </div>

                        <div class="form-row-3">
                            <div class="form-group group">
                                <input
                                    x-model="searchPhoneNumber"
                                    name="searchPhoneNumber"
                                    id="searchPhoneNumber"
                                    type="text"
                                    class="input peer"
                                    placeholder=" "
                                    autocomplete="off"
                                    x-mask="+380999999999"
                                    x-on:keydown.enter.prevent="focusNext($el)"
                                />
                                <label for="searchPhoneNumber" class="label">
                                    {{ __('forms.phone_number') }}
                                </label>
                            </div>

                            <div class="form-group group">
                                <input
                                    x-model="searchBirthCertificate"
                                    type="text"
                                    name="searchBirthCertificate"
                                    id="searchBirthCertificate"
                                    class="input peer"
                                    placeholder=" "
                                    autocomplete="off"
                                    x-on:keydown.enter.prevent="focusNext($el)"
                                />
                                <label for="searchBirthCertificate" class="label">
                                    {{ __('forms.birth_certificate') }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mb-9 mt-6 flex gap-2">
                <button @click.prevent="search" class="flex items-center gap-2 button-primary">
                    @icon('search', 'w-4 h-4')
                    <span>{{ __('patients.search') }}</span>
                </button>
                <button type="button" @click="reset" class="button-primary-outline-red">
                    {{ __('forms.reset_all_filters') }}
                </button>
            </div>
        </x-slot>
    </x-header-navigation>

    <!-- Search Results List -->
    <div class="space-y-6 pl-3.5 mt-12">
        <template x-for="patient in filteredPatients" :key="patient.id">
            <fieldset class="shift-content p-4 sm:p-8 sm:pb-10 mb-16 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-[1280px]">
                <legend class="legend" x-text="'ID ' + patient.uuid"></legend>

                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 dark:border-gray-700 pb-4">
                    <div class="flex items-center flex-wrap gap-x-6 gap-y-2 text-sm text-gray-500 mt-2">
                        <span class="flex items-center gap-1.5" x-show="patient.birth_date">
                            <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true"
                                 xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"
                                 viewBox="0 0 24 24">
                                   <path stroke="currentColor" stroke-linecap="round" stroke-width="2"
                                         d="M8 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H8z" />
                                   <path stroke="currentColor" stroke-linecap="round" stroke-width="2"
                                         d="M16 2v4M8 2v4M3 10h18" />
                            </svg>
                            <span x-text="'Д.Н. ' + formatDate(patient.birth_date)"></span>
                        </span>

                        <span class="flex items-center gap-1.5 min-w-0" x-show="patient.phone">
                            @icon('tabler-phone', 'w-6 h-6 text-gray-800 dark:text-white')
                            <a :href="'tel:' + patient.phone"
                               class="truncate hover:underline font-medium text-gray-900 dark:text-gray-200 text-base"
                               :title="patient.phone"
                               x-text="patient.phone"
                            ></a>
                        </span>

                        <span class="flex items-center gap-1.5" x-show="patient.gender">
                            <span x-show="patient.gender === 'MALE'" class="flex items-center gap-1.5">
                                @icon('men', 'w-6 h-6 text-gray-800 dark:text-white')
                                <span>{{ __('patients.male') }}</span>
                            </span>
                            <span x-show="patient.gender === 'FEMALE'" class="flex items-center gap-1.5">
                                @icon('women', 'w-6 h-6 text-gray-800 dark:text-white')
                                <span>{{ __('patients.female') }}</span>
                            </span>
                        </span>
                    </div>

                    <div class="flex items-center space-x-6">
                        <button @click="triggerAction('success', 'Перехід до перегляду картки.')"
                                class="cursor-pointer text-blue-600 hover:text-blue-800 flex items-center gap-1.5 font-medium"
                        >
                            @icon('file-lines', 'w-4 h-4')
                            <span class="text-sm">{{ __('patients.view_record') }}</span>
                        </button>
                        <button @click="triggerAction('success', 'Перехід до створення взаємодії.')"
                                class="cursor-pointer text-blue-600 hover:text-blue-800 flex items-center gap-1.5 font-medium"
                        >
                            @icon('plus', 'w-4 h-4')
                            <span class="text-sm">{{ __('patients.start_interacting') }}</span>
                        </button>
                    </div>
                </div>

                <div class="flow-root mt-4">
                    <div class="max-w-screen-xl">
                        <table class="table-input w-full table-auto">
                            <thead class="thead-input">
                                <tr>
                                    <th scope="col" class="th-input">{{ __('patients.patient_full_name') }}</th>
                                    <th scope="col" class="th-input">ПРИМІТКА</th>
                                    <th scope="col" class="th-input">{{ __('forms.status.label') }}</th>
                                    <th scope="col" class="th-input text-center">{{ __('forms.actions') }}</th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr>
                                    <td class="td-input whitespace-nowrap overflow-hidden text-ellipsis align-top font-bold text-gray-900 dark:text-white"
                                        x-text="[patient.last_name, patient.first_name, patient.second_name].filter(Boolean).join(' ') || '-'"
                                    ></td>
                                    <td class="td-input align-top text-gray-700 dark:text-gray-300" x-text="patient.note || '-'"></td>
                                    <td class="td-input whitespace-nowrap align-top">
                                        <span :class="patient.status === 'active' ? 'badge-green' : 'badge-red'"
                                              class="px-2 py-0.5 rounded text-xs"
                                              x-text="patient.status === 'active' ? '{{ __('forms.status.active') }}' : '{{ __('forms.status.non_active') }}'"
                                        ></span>
                                    </td>
                                    <td class="td-input text-center">
                                        <div class="relative"
                                             x-data="{ openDropdown: false }"
                                             @click.outside="openDropdown = false"
                                        >
                                            <button @click="openDropdown = !openDropdown"
                                                    type="button"
                                                    class="cursor-pointer p-1 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                            >
                                                @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-gray-200')
                                            </button>

                                            <div x-show="openDropdown"
                                                 x-transition
                                                 x-cloak
                                                 class="absolute right-0 z-10 w-56 bg-white rounded shadow-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600"
                                            >
                                                <div class="py-1">
                                                    <a @click="triggerAction('success', 'Довідку успішно сформовано.'); openDropdown = false"
                                                       class="dropdown-button !flex items-center gap-2 px-4 py-2 text-sm border-b border-gray-100 dark:border-gray-600 w-full hover:bg-gray-50 dark:hover:bg-gray-600 cursor-pointer text-left text-gray-700 dark:text-gray-200"
                                                    >
                                                        @icon('file-text', 'w-4 h-4')
                                                        {{ __('patients.get_certificate') }}
                                                    </a>

                                                    <a @click="triggerAction('success', 'Перехід до редагування даних.'); openDropdown = false"
                                                       class="dropdown-button !flex items-center gap-2 px-4 py-2 text-sm border-b border-gray-100 dark:border-gray-600 w-full hover:bg-gray-50 dark:hover:bg-gray-600 cursor-pointer text-left text-gray-700 dark:text-gray-200"
                                                    >
                                                        @icon('pencil-clipboard', 'w-4 h-4')
                                                        {{ __('patients.edit_data') }}
                                                    </a>

                                                    <button @click="registerDeath(patient.uuid); openDropdown = false"
                                                            class="dropdown-button !flex items-center gap-2 w-full px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20"
                                                            type="button"
                                                    >
                                                        @icon('trash', 'w-4 h-4')
                                                        {{ __('prepersons.register_death') }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </fieldset>
        </template>

        <div class="max-w-6xl font-medium text-gray-500 text-center py-12" x-show="filteredPatients.length === 0">
            <x-nothing-found />
        </div>
    </div>

    <x-forms.loading />
</div>
