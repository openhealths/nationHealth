@if ($showEncounterEPrescriptionDrawer)
    <div
        wire:click="closeEncounterEPrescriptionDrawer"
        class="fixed top-0 right-0 z-[46] h-screen bg-gray-900/50 pt-20"
        style="width: calc(100% - 300px)"
    ></div>

    <div
        class="fixed top-0 right-0 z-[47] h-screen overflow-y-auto bg-gray-50 p-8 pt-20 shadow-2xl dark:bg-gray-900"
        style="width: calc(100% - 300px)"
        tabindex="-1"
    >
        <h2 class="mb-6 text-2xl font-bold text-gray-900 dark:text-white">Електронний рецепт без плану лікування</h2>
        <p class="mb-6 text-sm text-gray-600 dark:text-gray-300">
            Взаємодія №{{ $encounterUuid ?? $encounterId }}
        </p>

        <form wire:submit.prevent="validateEncounterEPrescription" class="mx-auto max-w-3xl space-y-6">
            @if ($encounterEPrescriptionWarningMessage !== '')
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                    {{ $encounterEPrescriptionWarningMessage }}
                </div>
            @endif

            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-semibold">Лікарський засіб</h3>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="encounterEPrescriptionProgram" class="mb-1 block text-sm font-medium">
                            Медична програма *
                        </label>
                        <select
                            id="encounterEPrescriptionProgram"
                            class="input-select peer w-full"
                            wire:model.live="encounterEPrescriptionForm.program_id"
                        >
                            <option value="">{{ __('care-plan.prescription_medication') }}</option>
                            @foreach ($encounterEPrescriptionPrograms as $program)
                                <option value="{{ $program['id'] }}">{{ $program['name'] }}</option>
                            @endforeach
                        </select>
                        @if ($encounterEPrescriptionPrograms === [])
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                {{ __('encounters.messages.medication_programs_unavailable') }}
                            </p>
                        @endif
                        @error('encounterEPrescriptionForm.program_id')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="md:col-span-2">
                        <label for="encounterEPrescriptionSearch" class="mb-1 block text-sm font-medium">
                            Пошук лікарського засобу *
                        </label>
                        <div class="flex gap-2">
                            <input
                                type="text"
                                id="encounterEPrescriptionSearch"
                                class="input peer w-full"
                                placeholder="Введіть щонайменше 3 символи та натисніть Enter"
                                wire:model="encounterEPrescriptionSearchQuery"
                                wire:keydown.enter.prevent="searchEncounterEPrescriptionMedications"
                            />
                            <button
                                type="button"
                                class="button-primary"
                                wire:click="searchEncounterEPrescriptionMedications"
                            >
                                Пошук
                            </button>
                        </div>
                    </div>
                    @if ($encounterEPrescriptionSearchResults !== [])
                        <div class="max-h-64 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-3 md:col-span-2 dark:border-gray-600 dark:bg-gray-900/40">
                            @foreach ($encounterEPrescriptionSearchResults as $drug)
                                <button
                                    type="button"
                                    wire:click="selectEncounterEPrescriptionMedication('{{ $drug['id'] }}')"
                                    class="w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-left text-sm transition-colors hover:border-blue-300 hover:bg-blue-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:border-blue-400 dark:hover:bg-gray-700"
                                >
                                    <div class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ $drug['name'] ?: 'Лікарський засіб' }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        МНН: {{ $drug['innm_name'] ?: '-' }} · Форма: {{ $drug['innm_dosage_form'] ?: '-' }}
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @endif
                    <div class="md:col-span-2">
                        <label for="encounterEPrescriptionMedication" class="mb-1 block text-sm font-medium">
                            Обраний лікарський засіб
                        </label>
                        <input
                            type="text"
                            id="encounterEPrescriptionMedication"
                            class="input peer w-full"
                            value="{{ $encounterEPrescriptionSelectedMedication['name'] ?? '' }}"
                            placeholder="Лікарський засіб не обрано"
                            readonly
                        />
                        <p class="mt-1 text-xs text-gray-500">
                            UUID: {{ $encounterEPrescriptionForm['medication_id'] ?? '-' }}
                        </p>
                        @error('encounterEPrescriptionForm.medication_id')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="encounterEPrescriptionCategory" class="mb-1 block text-sm font-medium"
                            >Категорія</label>
                        <select
                            id="encounterEPrescriptionCategory"
                            class="input-select peer w-full"
                            wire:model="encounterEPrescriptionForm.category"
                        >
                            <option value="community">Амбулаторно</option>
                        </select>
                    </div>
                    <div>
                        <label for="encounterEPrescriptionQuantity" class="mb-1 block text-sm font-medium"
                            >Кількість *</label>
                        <input
                            type="number"
                            id="encounterEPrescriptionQuantity"
                            min="0.01"
                            step="any"
                            class="input peer w-full"
                            wire:model="encounterEPrescriptionForm.medication_qty"
                        />
                        @if (!empty($encounterEPrescriptionSelectedMedication))
                            <p class="mt-1 text-xs text-gray-500">Кількість має відповідати фасуванню обраного ЛЗ.</p>
                        @endif
                    </div>
                    <div>
                        <label for="encounterEPrescriptionUnit" class="mb-1 block text-sm font-medium">Одиниця</label>
                        <input
                            type="text"
                            id="encounterEPrescriptionUnit"
                            class="input peer w-full"
                            wire:model="encounterEPrescriptionForm.medication_unit"
                        />
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-semibold">Дозування</h3>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="encounterEPrescriptionSignature" class="mb-1 block text-sm font-medium"
                            >Сигнатура *</label>
                        <input
                            type="text"
                            id="encounterEPrescriptionSignature"
                            class="input peer w-full"
                            wire:model="encounterEPrescriptionForm.signature_text"
                        />
                        @error('encounterEPrescriptionForm.signature_text')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label
                            for="encounterEPrescriptionMaxDosePerAdministration"
                            class="mb-1 block text-sm font-medium"
                        >
                            Макс. доза за прийом *
                        </label>
                        <input
                            type="number"
                            id="encounterEPrescriptionMaxDosePerAdministration"
                            min="0.01"
                            step="any"
                            class="input peer w-full"
                            wire:model="encounterEPrescriptionForm.max_dose_per_administration"
                        />
                    </div>
                    <div>
                        <label for="encounterEPrescriptionMaxDosePerPeriod" class="mb-1 block text-sm font-medium">
                            Макс. доза за період *
                        </label>
                        <input
                            type="number"
                            id="encounterEPrescriptionMaxDosePerPeriod"
                            min="0.01"
                            step="any"
                            class="input peer w-full"
                            wire:model="encounterEPrescriptionForm.max_dose_per_period"
                        />
                    </div>
                    <div>
                        <label for="encounterEPrescriptionStartedAt" class="mb-1 block text-sm font-medium"
                            >Початок курсу</label>
                        <input
                            type="date"
                            id="encounterEPrescriptionStartedAt"
                            class="input peer w-full"
                            wire:model="encounterEPrescriptionForm.started_at"
                        />
                    </div>
                    <div>
                        <label for="encounterEPrescriptionEndedAt" class="mb-1 block text-sm font-medium"
                            >Кінець курсу</label>
                        <input
                            type="date"
                            id="encounterEPrescriptionEndedAt"
                            class="input peer w-full"
                            wire:model="encounterEPrescriptionForm.ended_at"
                        />
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-semibold">Автентифікація</h3>
                <div>
                    <label for="encounterEPrescriptionInformWith" class="mb-1 block text-sm font-medium">
                        Метод автентифікації пацієнта *
                    </label>
                    <select
                        id="encounterEPrescriptionInformWith"
                        class="input-select peer w-full"
                        wire:model="encounterEPrescriptionForm.inform_with"
                    >
                        <option value="">Оберіть</option>
                        @foreach ($encounterEPrescriptionAuthMethods as $method)
                            <option value="{{ $method['value'] ?? $method['uuid'] }}">{{ $method['label'] }}</option>
                        @endforeach
                    </select>
                    @error('encounterEPrescriptionForm.inform_with')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" class="button-minor" wire:click="closeEncounterEPrescriptionDrawer">
                    Скасувати
                </button>
                <button type="submit" class="button-primary">Створити та підписати</button>
            </div>
        </form>
    </div>
@endif
