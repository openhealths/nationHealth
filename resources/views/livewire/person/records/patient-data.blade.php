@use('App\Enums\Person\AuthenticationMethod')
@use('App\Enums\Person\VerificationStatus as Status')

@php
    if ($isUnidentified) {
        $patient = \App\Models\Person\Person::with('phones')->findOrFail($personId);
        $emergencyContact = (array) $patient->emergencyContact;
        $taxId = $patient->taxId;
        $firstName = $patient->firstName;
        $lastName = $patient->lastName;
        $secondName = $patient->secondName;
        $gender = $patient->gender;
        $birthDate = $patient->birthDate ? \Carbon\Carbon::parse($patient->birthDate)->format('d.m.Y') : '-';
    }
@endphp

<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName" :title="$isUnidentified ? 'ID ' . strtoupper($uuid) : null" :activeTab="'patient-data'">
    <!-- Temporary Toggle Switch at the top -->
    <div class="px-4 pt-4 shift-content flex items-center justify-between border-b border-gray-100 dark:border-gray-800 pb-4 mb-4 z-30 relative">
        <label class="inline-flex items-center cursor-pointer select-none">
            <input type="checkbox" wire:model.live="isUnidentified" class="sr-only peer">
            <div class="relative w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
            <span class="ms-3 text-sm font-medium text-gray-900 dark:text-gray-300">
                Переглянути як неідентифікований пацієнт
            </span>
        </label>
    </div>

    @if($isUnidentified)
        <div class="breadcrumb-form p-4 shift-content space-y-6" x-data="{ showCertificate: false }">

            <div x-data="{ open: true }"
                class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                <h2>
                    <button type="button"
                        class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                        @click="open = !open"
                        :aria-expanded="open"
                    >
                        <span class="text-base font-semibold text-gray-900 dark:text-white">Ідентифікація пацієнта</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                    </button>
                </h2>
                <div x-show="open" wire:ignore.self>
                    <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-4">
                        <a href="#" class="cursor-pointer text-blue-600 hover:text-blue-800 flex items-center gap-1.5 font-medium">
                            @icon('plus', 'w-4 h-4')
                            <span class="text-sm">Приєднати записи неідентифікованого пацієнта до записів ідентифікованого пацієнта</span>
                        </a>
                    </div>
                </div>
            </div>

            <div x-data="{ open: true }"
                class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                <h2>
                    <button type="button"
                        class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                        @click="open = !open"
                        :aria-expanded="open"
                    >
                        <span class="text-base font-semibold text-gray-900 dark:text-white">Основна інформація</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                    </button>
                </h2>
                <div x-show="open" wire:ignore.self>
                    <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-4 space-y-6">
                        <div class="form-row-3">
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly value="{{ $taxId ?? '-' }}" />
                                <label class="label">ID пацієнта в закладі охорони здоров'я</label>
                            </div>
                        </div>

                        <div class="form-row-3">
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly value="{{ $firstName }}" />
                                <label class="label">Ім'я пацієнта</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly value="{{ $lastName }}" />
                                <label class="label">Прізвище пацієнта</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly value="{{ $secondName ?: '-' }}" />
                                <label class="label">По-батькові пацієнта</label>
                            </div>
                        </div>

                        <div class="form-row-3">
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly value="{{ $gender === 'FEMALE' ? 'Жіноча' : 'Чоловіча' }}" />
                                <label class="label">Стать</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly value="{{ $birthDate ?: '-' }}" />
                                <label class="label">Дата народження</label>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group group">
                                <label class="label-secondary">Примітка</label>
                                @php
                                    $note = '';
                                    if (!empty($emergencyContact['note'])) {
                                        $note = $emergencyContact['note'];
                                    } elseif (!empty($emergencyContact['description'])) {
                                        $note = $emergencyContact['description'];
                                    } else {
                                        $note = '№ картки виїзду швидкої медичної допомоги KA123112';
                                    }
                                @endphp
                                <textarea class="textarea w-full" rows="3" readonly placeholder="Примітка">{{ $note }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div x-data="{ open: true }"
                class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                <h2>
                    <button type="button"
                        class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                        @click="open = !open"
                        :aria-expanded="open"
                    >
                        <span class="text-base font-semibold text-gray-900 dark:text-white">Контактна особа</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                    </button>
                </h2>
                <div x-show="open" wire:ignore.self>
                    <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-4 space-y-6">
                        <div class="form-row-3">
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly value="{{ $emergencyContact['firstName'] ?? 'Тарас' }}" />
                                <label class="label">Ім'я</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly value="{{ $emergencyContact['lastName'] ?? 'Шевченко' }}" />
                                <label class="label">Прізвище</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly value="{{ $emergencyContact['secondName'] ?? 'Григорович' }}" />
                                <label class="label">По-батькові</label>
                            </div>
                        </div>

                        <div class="form-row-3">
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly value="Мобільний" />
                                <label class="label">Тип телефону</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly value="{{ $emergencyContact['phones'][0]['number'] ?? '+380943237223' }}" />
                                <label class="label">Телефон</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-4 pt-4 border-t border-gray-100 dark:border-gray-700 justify-start items-center">
                <a href="{{ route('persons.index', [legalEntity()]) }}" class="button-minor" style="margin: 0 !important;">
                    Закрити
                </a>
                <a href="{{ route('persons.update', [legalEntity(), 'person' => $personId]) }}" class="button-primary" style="margin: 0 !important;">
                    Редагувати дані
                </a>
                <button type="button"
                        class="button-primary-outline flex items-center gap-2"
                        style="margin: 0 !important;"
                        @click="showCertificate = true"
                >
                    @icon('printer', 'w-4 h-4')
                    <span>Інформаційна довідка</span>
                </button>
                <button type="button" class="button-primary-outline-red" style="margin: 0 !important;">
                    Зареєструвати смерть
                </button>
            </div>

            @include('livewire.person.records.partials.information-certificate')

        </div>
    @else
        <div class="breadcrumb-form px-4 pt-4 pb-10 shift-content">
            <div class="flex items-center gap-4 mb-4">
                @php($verificationStatusEnum = Status::from($verificationStatus))
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    <span class="font-medium">{{ __('patients.verification_in_eHealth') }}:</span>
                    <span class="ml-1 {{ $verificationStatusEnum->color() }}">{{ $verificationStatusEnum->label() }}</span>
                </p>

                <button
                    wire:click.once="getVerificationStatus"
                    type="button"
                    class="inline-flex items-center gap-2 px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer"
                >
                    {{ __('patients.update_status') }}
                    @icon('refresh', 'w-4 h-4')
                </button>
            </div>

            <div id="accordion-open" data-accordion="open" class="flex flex-col gap-4">

                <div
                    class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                    <h2 id="accordion-open-heading-1">
                        <button
                            type="button"
                            class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                            data-accordion-target="#accordion-open-body-1"
                            aria-expanded="true"
                            aria-controls="accordion-open-body-1"
                            data-accordion-icon
                        >
                            <span class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ __('patients.passport_data') }}
                            </span>
                            @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                        </button>
                    </h2>
                    <div id="accordion-open-body-1" aria-labelledby="accordion-open-heading-1" wire:ignore.self>
                        <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700">
                            <div class="mt-4 space-y-4">
                                <div class="flex items-center gap-4">
                                    <label class="w-40 text-sm text-gray-500 dark:text-gray-400 shrink-0">
                                        {{ __('forms.last_name') }}:
                                    </label>
                                    <input
                                        wire:model="lastName"
                                        type="text"
                                        name="lastName"
                                        id="lastName"
                                        class="flex-1 max-w-xs bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white text-sm rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder=" "
                                        autocomplete="off"
                                    />
                                </div>

                                <div class="flex items-center gap-4">
                                    <label class="w-40 text-sm text-gray-500 dark:text-gray-400 shrink-0">
                                        {{ __('forms.first_name') }}:
                                    </label>
                                    <input
                                        wire:model="firstName"
                                        type="text"
                                        name="firstName"
                                        id="firstName"
                                        class="flex-1 max-w-xs bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white text-sm rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder=" "
                                        autocomplete="off"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                    <h2 id="accordion-open-heading-2">
                        <button
                            type="button"
                            class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                            data-accordion-target="#accordion-open-body-2"
                            aria-expanded="false"
                            aria-controls="accordion-open-body-2"
                            data-accordion-icon
                        >
                            <span class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ __('patients.contact_data') }}
                            </span>
                            @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                        </button>
                    </h2>
                    <div
                        id="accordion-open-body-2"
                        class="hidden"
                        aria-labelledby="accordion-open-heading-2"
                        wire:ignore.self
                    >
                        <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700">
                            <div class="mt-4 space-y-4">
                                @foreach($phones as $key => $phone)
                                    <div class="flex items-center gap-4">
                                        <label class="w-40 text-sm text-gray-500 dark:text-gray-400 shrink-0">
                                            {{ __('forms.phone') }}:
                                        </label>
                                        <input
                                            wire:model="phones.{{ $key }}.number"
                                            type="text"
                                            name="phoneNumber_{{ $key }}"
                                            id="phoneNumber_{{ $key }}"
                                            class="flex-1 max-w-xs bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white text-sm rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                            placeholder=" "
                                            autocomplete="off"
                                        />
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                    <h2 id="accordion-open-heading-3" wire:ignore>
                        <button
                            wire:click.once="getConfidantPersons"
                            type="button"
                            class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                            data-accordion-target="#accordion-open-body-3"
                            aria-expanded="false"
                            aria-controls="accordion-open-body-3"
                            data-accordion-icon
                        >
                            <span class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ __('patients.patient_legal_representative') }}
                            </span>
                            @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                        </button>
                    </h2>
                    <div
                        id="accordion-open-body-3"
                        class="hidden"
                        aria-labelledby="accordion-open-heading-3"
                        wire:ignore.self
                    >
                        <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700">
                            <div class="mt-4 space-y-4">
                                @if(!empty($confidantPersonRelationships))
                                    @foreach($confidantPersonRelationships as $key => $confidantPersonRelationship)
                                        <div class="flex items-center gap-4">
                                            <label class="w-40 text-sm text-gray-500 dark:text-gray-400 shrink-0">
                                                {{ __('forms.full_name') }}:
                                            </label>
                                            <input
                                                wire:model="confidantPersonRelationships.{{ $key }}.confidantPerson.name"
                                                type="text"
                                                name="name_{{ $key }}"
                                                id="name_{{ $key }}"
                                                class="flex-1 max-w-xs bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white text-sm rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                placeholder=" "
                                                autocomplete="off"
                                            />
                                        </div>
                                        <div class="flex items-center gap-4">
                                            <label class="w-40 text-sm text-gray-500 dark:text-gray-400 shrink-0">
                                                {{ __('forms.active_to') }}:
                                            </label>
                                            <input
                                                type="text"
                                                name="activeTo_{{ $key }}"
                                                id="activeTo_{{ $key }}"
                                                class="flex-1 max-w-xs bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white text-sm rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                placeholder=" "
                                                autocomplete="off"
                                                value="{{ convertToAppDateFormat($confidantPersonRelationship['activeTo'] ?? null) }}"
                                                readonly
                                            />
                                        </div>
                                    @endforeach
                                @else
                                    <p class="text-sm text-gray-500 dark:text-gray-400 py-2">
                                        {{ __('patients.confidant_person_not_exist') }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                @include('livewire.person.parts.incapacitated', ['incapacitatedLabel' => __('patients.add_confidant_person')])

                <div
                    class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                    <h2 id="accordion-open-heading-4" wire:ignore>
                        <button
                            wire:click="openAuthMethodModal"
                            type="button"
                            class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                        >
                            <span class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ __('patients.authentication_methods') }}
                            </span>
                            @icon('edit-user-outline', 'w-5 h-5 text-gray-400 shrink-0')
                        </button>
                    </h2>
                </div>
            </div>

            <div class="mt-8">
                <a
                    href="{{ route('persons.update', [legalEntity(), $personId]) }}"
                    class="inline-flex items-center px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer"
                >
                    {{ __('patients.edit_data') }}
                </a>
            </div>
        </div>

        @if($showAuthMethodModal)
            @include('livewire.person.parts.modals.choose-auth-method')
        @endif
    @endif

    <x-forms.loading />
</x-layouts.patient>
