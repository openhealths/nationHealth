@use('App\Enums\Person\AuthenticationMethod')
@use('App\Enums\Person\VerificationStatus as Status')

@php
    $isReferenceMode = request()->has('person');
    if ($isReferenceMode) {
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

<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName" :title="$isReferenceMode ? 'ID ' . strtoupper($uuid) : null">
    @if($isReferenceMode)
        <div class="breadcrumb-form p-4 shift-content space-y-6" x-data="{ showCertificate: false }">

            <div id="accordion-identification" data-accordion="open" class="fieldset-card rounded-lg shadow-sm">
                <h2>
                    <button type="button" class="accordion-button rounded-t-xl group">
                        <span class="text-lg">Ідентифікація пацієнта</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-500 transition-transform group-aria-expanded:rotate-180')
                    </button>
                </h2>
                <div class="accordion-content">
                    <a href="#" class="cursor-pointer text-blue-600 hover:text-blue-800 flex items-center gap-1.5 font-medium">
                        @icon('plus', 'w-4 h-4')
                        <span class="text-sm">Приєднати записи неідентифікованого пацієнта до записів ідентифікованого пацієнта</span>
                    </a>
                </div>
            </div>

            <div id="accordion-basic-info" data-accordion="open" class="fieldset-card rounded-lg shadow-sm">
                <h2>
                    <button type="button" class="accordion-button rounded-t-xl group">
                        <span class="text-lg">Основна інформація</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-500 transition-transform group-aria-expanded:rotate-180')
                    </button>
                </h2>
                <div class="accordion-content space-y-6">
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

            <div id="accordion-contact-person" data-accordion="open" class="fieldset-card rounded-lg shadow-sm">
                <h2>
                    <button type="button" class="accordion-button rounded-t-xl group">
                        <span class="text-lg">Контактна особа</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-500 transition-transform group-aria-expanded:rotate-180')
                    </button>
                </h2>
                <div class="accordion-content space-y-6">
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

            <div class="flex flex-wrap gap-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                <a href="{{ route('persons.index', [legalEntity()]) }}" class="button-minor">
                    Закрити
                </a>
                <a href="{{ route('persons.update', [legalEntity(), 'person' => $personId]) }}" class="button-primary">
                    Редагувати дані
                </a>
                <button type="button"
                        class="button-primary-outline flex items-center gap-2"
                        @click="showCertificate = true"
                >
                    @icon('printer', 'w-4 h-4')
                    <span>Інформаційна довідка</span>
                </button>
                <button type="button" class="button-primary-outline-red">
                    Зареєструвати смерть
                </button>
            </div>

            @include('livewire.person.records.partials.information-certificate')

        </div>
    @else
        <div class="breadcrumb-form p-4 shift-content">
            <div class="flex items-center gap-14 mb-10">
                <p class="default-p">
                    @php($status = Status::from($verificationStatus))
                    {{ __('patients.verification_in_eHealth') }}:
                    <span @class([$status->color()])>
                        {{ $status->label() ?? '-' }}
                    </span>
                </p>

                <div>
                    <button wire:click.once="getVerificationStatus"
                            type="button"
                            class="flex items-center gap-2 button-primary"
                    >
                        {{ __('patients.update_status') }}
                        @icon('refresh', 'w-4 h-4')
                    </button>
                </div>
            </div>

            <div id="accordion-open" data-accordion="open">
                <h2 id="accordion-open-heading-1">
                    <button type="button"
                            class="accordion-button rounded-t-xl border-b-0 group"
                            data-accordion-target="#accordion-open-body-1"
                            aria-expanded="true"
                            aria-controls="accordion-open-body-1"
                            data-accordion-icon
                    >
                        <span class="text-lg">{{ __('patients.passport_data') }}</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-500 dark:text-gray-400 transition-transform group-aria-expanded:rotate-180')
                    </button>
                </h2>
                <div id="accordion-open-body-1" class="hidden" aria-labelledby="accordion-open-heading-1" wire:ignore.self>
                    <div class="accordion-content dark:bg-gray-900 border-b-0">
                        <div class="form-row-4 items-baseline">
                            <div class="form-group group">
                                <p class="default-p">{{ __('forms.last_name') }}</p>
                            </div>
                            <div>
                                <input wire:model="lastName"
                                       type="text"
                                       name="lastName"
                                       id="lastName"
                                       class="input"
                                       placeholder=" "
                                       required
                                       autocomplete="off"
                                />
                            </div>
                        </div>

                        <div class="form-row-4 items-baseline">
                            <div class="form-group group">
                                <p class="default-p">{{ __('forms.first_name') }}</p>
                            </div>
                            <div>
                                <input wire:model="firstName"
                                       type="text"
                                       name="firstName"
                                       id="firstName"
                                       class="input"
                                       placeholder=" "
                                       required
                                       autocomplete="off"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <h2 id="accordion-open-heading-2">
                    <button type="button"
                            class="accordion-button border-b-0 group"
                            data-accordion-target="#accordion-open-body-2"
                            aria-expanded="false"
                            aria-controls="accordion-open-body-2"
                            data-accordion-icon
                    >
                        <span class="text-lg">{{ __('patients.contact_data') }}</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-500 dark:text-gray-400 transition-transform group-aria-expanded:rotate-180')
                    </button>
                </h2>
                <div id="accordion-open-body-2" class="hidden" aria-labelledby="accordion-open-heading-2" wire:ignore.self>
                    <div class="accordion-content dark:bg-gray-900 border-b-0">
                        @foreach($phones as $key => $phone)
                            <div class="form-row-4 items-baseline">
                                <div class="form-group group">
                                    <p class="default-p">{{ __('forms.phone') }}</p>
                                </div>
                                <div>
                                    <input wire:model="phones.{{ $key }}.number"
                                           type="text"
                                           name="phoneNumber_{{ $key }}"
                                           id="phoneNumber_{{ $key }}"
                                           class="input"
                                           placeholder=" "
                                           required
                                           autocomplete="off"
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <h2 id="accordion-open-heading-3" wire:ignore>
                    <button wire:click.once="getConfidantPersons"
                            type="button"
                            class="accordion-button border-b-0 group"
                            data-accordion-target="#accordion-open-body-3"
                            aria-expanded="false"
                            aria-controls="accordion-open-body-3"
                            data-accordion-icon
                    >
                        <span class="text-lg">{{ __('patients.patient_legal_representative') }}</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-500 dark:text-gray-400 transition-transform group-aria-expanded:rotate-180')
                    </button>
                </h2>
                <div id="accordion-open-body-3" class="hidden" aria-labelledby="accordion-open-heading-3" wire:ignore.self>
                    <div class="accordion-content dark:bg-gray-900 border-t-0">
                        @if(!empty($confidantPersonRelationships))
                            @foreach($confidantPersonRelationships as $key => $confidantPersonRelationship)
                                <div class="form-row-4 items-baseline">
                                    <div class="form-group group">
                                        <p class="default-p">{{ __('forms.full_name') }}</p>
                                    </div>
                                    <div>
                                        <input wire:model="confidantPersonRelationships.{{ $key }}.confidant_person.name"
                                               type="text"
                                               name="name_{{ $key }}"
                                               id="name_{{ $key }}"
                                               class="input"
                                               placeholder=" "
                                               required
                                               autocomplete="off"
                                        />
                                    </div>
                                </div>
                                <div class="form-row-4 items-baseline">
                                    <div class="form-group group">
                                        <p class="default-p">{{ __('forms.active_to') }}</p>
                                    </div>
                                    <div>
                                        <input wire:model="confidantPersonRelationships.{{ $key }}.active_to"
                                               type="text"
                                               name="activeTo_{{ $key }}"
                                               id="activeTo_{{ $key }}"
                                               class="input"
                                               placeholder=" "
                                               required
                                               autocomplete="off"
                                        />
                                    </div>
                                </div>
                            @endforeach
                        @else
                            <p class="default-p">{{ __('patients.confidant_person_not_exist') }}</p>
                        @endif
                    </div>
                </div>

                <h2 id="accordion-open-heading-4" wire:ignore>
                    <button wire:click.once="getAuthenticationMethods"
                            type="button"
                            class="accordion-button group"
                            data-accordion-target="#accordion-open-body-4"
                            aria-expanded="false"
                            aria-controls="accordion-open-body-4"
                            data-accordion-icon
                    >
                        <span class="text-lg">{{ __('patients.authentication_methods') }}</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-500 dark:text-gray-400 transition-transform group-aria-expanded:rotate-180')
                    </button>
                </h2>
                <div id="accordion-open-body-4" class="hidden" aria-labelledby="accordion-open-heading-4" wire:ignore.self>
                    <div class="accordion-content dark:bg-gray-900 border-t-0">
                        @include('livewire.person.records.authentication-methods')
                    </div>
                </div>
            </div>
        </div>

        <x-forms.loading />
    @endif
</x-layouts.patient>
