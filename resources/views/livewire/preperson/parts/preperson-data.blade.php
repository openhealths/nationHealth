@php
    $preperson = \App\Models\Preperson::findOrFail($prepersonId);
    $emergencyContact = (array) $preperson->emergencyContact;
    $taxId = $preperson->externalId;
    $firstName = $preperson->firstName;
    $lastName = $preperson->lastName;
    $secondName = $preperson->secondName;
    $gender = $preperson->gender?->value;
    $birthDate = $preperson->birthDate ? \Carbon\Carbon::parse($preperson->birthDate)->format('d.m.Y') : '-';
@endphp

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
        <a href="#" class="button-primary" style="margin: 0 !important;">
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
