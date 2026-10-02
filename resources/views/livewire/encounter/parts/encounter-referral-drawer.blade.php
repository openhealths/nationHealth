@if ($showEncounterReferralDrawer)
    <div
        wire:click="closeEncounterReferralDrawer"
        class="fixed top-0 right-0 z-[46] h-screen bg-gray-900/50 pt-20"
        style="width: calc(80% - 30px)"
    ></div>

    <div
        x-data="{ openServiceCatalog: false }"
        @encounter-referral-service-catalog-close.window="openServiceCatalog = false"
        class="fixed top-0 right-0 z-[47] h-screen overflow-y-auto bg-white p-4 pt-20 shadow-2xl dark:bg-gray-800"
        style="width: calc(80% - 60px)"
        tabindex="-1"
    >
        <h3 class="modal-header">Виписати електронне направлення (без плану лікування)</h3>

        <form wire:submit.prevent="validateEncounterReferral" class="space-y-6">
            <fieldset class="fieldset">
                <legend class="legend">Послуга</legend>
                <div class="mb-4 grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div class="form-group group md:col-span-2">
                        <label for="encounterReferralServiceSearch" class="label required">
                            {{ __('encounters.service') }}
                        </label>
                        <div class="flex items-center gap-4">
                            <input
                                type="text"
                                id="encounterReferralServiceSearch"
                                class="input peer w-full"
                                placeholder="Код або назва послуги"
                                wire:model="encounterReferralServiceSearch"
                                wire:keydown.enter.prevent="searchEncounterReferralServices"
                            />

                            <button
                                type="button"
                                class="button-primary shrink-0"
                                wire:click="searchEncounterReferralServices"
                            >
                                Пошук
                            </button>

                            <button
                                type="button"
                                @click.prevent="openServiceCatalog = true"
                                class="inline-flex shrink-0 cursor-pointer items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                            >
                                <svg class="h-5 w-5" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                    <path
                                        d="M8 4.667C8 3.96 7.719 3.281 7.219 2.781 6.719 2.281 6.041 2 5.333 2H1.333V12H6c.53 0 1.039.21 1.414.586.375.375.586.884.586 1.414M8 4.667V14m0-9.333c0-.707.281-1.386.781-1.886.5-.5 1.179-.781 1.886-.781h4V12h-4.667c-.53 0-1.039.21-1.414.586-.375.375-.586.884-.586 1.414"
                                        stroke="currentColor"
                                        stroke-width="1.2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>

                                <span>{{ __('dictionaries.service_catalog.choose_from_catalog') }}</span>
                            </button>
                        </div>
                        @error('encounterReferralForm.service_id')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    @if ($encounterReferralServiceResults !== [])
                        <div class="max-h-64 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-3 md:col-span-2 dark:border-gray-600 dark:bg-gray-900/40">
                            @foreach ($encounterReferralServiceResults as $service)
                                <button
                                    type="button"
                                    wire:click="selectEncounterReferralService('{{ $service['id'] }}')"
                                    class="w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-left text-sm transition-colors hover:border-blue-300 hover:bg-blue-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:border-blue-400 dark:hover:bg-gray-700"
                                >
                                    <div class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ ($service['code'] ?? '') }} — {{ $service['name'] ?? 'Послуга' }}
                                    </div>
                                    @php
                                        $serviceCategoryKey = 'encounters.referral_category.'.strtolower((string) ($service['category'] ?? ''));
                                    @endphp
                                    @if (\Illuminate\Support\Facades\Lang::has($serviceCategoryKey))
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ __($serviceCategoryKey) }}
                                        </div>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @elseif ($encounterReferralHasSearched)
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-center text-sm text-gray-500 md:col-span-2 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                            Послуг за вашим запитом не знайдено.
                        </div>
                    @endif
                    <div class="form-group group md:col-span-2">
                        <label for="encounterReferralSelectedService" class="label">Обрана послуга</label>
                        <input
                            type="text"
                            id="encounterReferralSelectedService"
                            class="input peer w-full"
                            value="{{ !empty($encounterReferralSelectedService) ? (($encounterReferralSelectedService['code'] ?? '') . ' — ' . ($encounterReferralSelectedService['name'] ?? '')) : '' }}"
                            placeholder="{{ __('encounters.select_service') }}"
                            readonly
                        />
                    </div>
                    <div class="form-group group">
                        <label for="encounterReferralCategory" class="label required">Категорія</label>
                        <select
                            id="encounterReferralCategory"
                            class="input-select peer w-full"
                            wire:model="encounterReferralForm.category"
                            @disabled($encounterReferralIsTransfer)
                        >
                            @foreach (__('encounters.referral_category') as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                            <option value="counselling">{{ __('encounters.referral_category.counseling') }}</option>
                            <option value="transfer_of_care">{{ __('encounters.referral_category.transfer') }}</option>
                        </select>
                    </div>
                    @if ($encounterReferralIsTransfer)
                        <div class="form-group group md:col-span-2">
                            <label for="encounterReferralPerformer" class="label required"
                                >Заклад, до якого переводять</label>
                            <input
                                type="text"
                                id="encounterReferralPerformer"
                                class="input peer w-full"
                                value="{{ $encounterReferralPerformerName }}"
                                readonly
                            />
                        </div>
                        <div class="form-group group">
                            <label for="encounterReferralLocation" class="label required">Підрозділ виконавця</label>
                            <select
                                id="encounterReferralLocation"
                                class="input-select peer w-full"
                                wire:model="encounterReferralForm.location_reference"
                            >
                                <option value="">Оберіть підрозділ</option>
                                @foreach ($encounterReferralDivisions as $division)
                                    <option value="{{ $division['id'] }}">{{ $division['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group group">
                            <label for="encounterReferralPerformerType" class="label">Спеціальність виконавця</label>
                            <select
                                id="encounterReferralPerformerType"
                                class="input-select peer w-full"
                                wire:model="encounterReferralForm.performer_type"
                            >
                                <option value="">Не обрано</option>
                                @foreach ($encounterReferralSpecialities as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="form-group group">
                        <label for="encounterReferralProgram" class="label">Програма</label>
                        <select
                            id="encounterReferralProgram"
                            class="input-select peer w-full"
                            wire:model="encounterReferralForm.program_id"
                        >
                            <option value="">Не обрано</option>
                            @foreach ($encounterReferralPrograms as $program)
                                <option value="{{ $program['id'] }}">{{ $program['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </fieldset>

            <fieldset class="fieldset">
                <legend class="legend">Термін дії та кількість</legend>
                <div class="mb-4 grid grid-cols-1 gap-6 md:grid-cols-3">
                    <div class="form-group group">
                        <label for="encounterReferralStartedAt" class="label required">Дата початку</label>
                        <input
                            type="text"
                            id="encounterReferralStartedAt"
                            class="input peer"
                            placeholder="dd.mm.yyyy"
                            wire:model="encounterReferralForm.started_at"
                        />
                    </div>
                    <div class="form-group group">
                        <label for="encounterReferralEndedAt" class="label required">Дата закінчення</label>
                        <input
                            type="text"
                            id="encounterReferralEndedAt"
                            class="input peer"
                            placeholder="dd.mm.yyyy"
                            wire:model="encounterReferralForm.ended_at"
                        />
                    </div>
                    <div class="form-group group">
                        <label for="encounterReferralQuantity" class="label required">Кількість</label>
                        <input
                            type="number"
                            id="encounterReferralQuantity"
                            min="0.01"
                            step="any"
                            class="input peer"
                            wire:model="encounterReferralForm.quantity"
                        />
                    </div>
                </div>
                <div class="mb-4 grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div class="form-group group">
                        <label for="encounterReferralPriority" class="label required">Пріоритет</label>
                        <select
                            id="encounterReferralPriority"
                            class="input-select peer w-full"
                            wire:model="encounterReferralForm.priority"
                        >
                            <option value="routine">{{ __('encounters.priority_options.routine') }}</option>
                            <option value="urgent">{{ __('encounters.priority_options.urgent') }}</option>
                            <option value="asap">{{ __('encounters.priority_options.asap') }}</option>
                            <option value="stat">{{ __('encounters.priority_options.stat') }}</option>
                        </select>
                    </div>
                </div>
            </fieldset>

            <fieldset class="fieldset">
                <legend class="legend">Додатково</legend>
                <div class="mb-4 grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div class="form-group group">
                        <label for="encounterReferralInformWith" class="label">Метод автентифікації</label>
                        <select
                            id="encounterReferralInformWith"
                            class="input-select peer w-full"
                            wire:model="encounterReferralForm.inform_with"
                        >
                            <option value="">Не обрано</option>
                            @foreach ($encounterReferralAuthMethods as $method)
                                <option value="{{ \App\Services\MedicalEvents\InformWith::formValue($method) }}">
                                    {{ $method['label'] ?? '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group group">
                        <label for="encounterReferralPatientInstruction" class="label">Інструкція пацієнту</label>
                        <input
                            type="text"
                            id="encounterReferralPatientInstruction"
                            class="input peer"
                            wire:model="encounterReferralForm.patient_instruction"
                        />
                    </div>
                </div>
                <div class="form-group group">
                    <label for="encounterReferralNote" class="label">Примітки</label>
                    <textarea
                        id="encounterReferralNote"
                        class="input peer min-h-20"
                        wire:model="encounterReferralForm.note"
                    ></textarea>
                </div>
            </fieldset>

            @if ($encounterReferralWarningMessage !== '')
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                    {{ $encounterReferralWarningMessage }}
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <button type="button" class="button-minor" wire:click="closeEncounterReferralDrawer">Скасувати</button>
                <button type="submit" class="button-primary">Створити та підписати</button>
            </div>
        </form>
        <x-dialog-drawer
            x-model="openServiceCatalog"
            onCloseClick="openServiceCatalog = false"
            maxWidth="4/5"
            overlayWidth="100%"
            zIndex="50"
        >
            <livewire:dictionary.service-catalog
                :legal-entity="legalEntity()"
                :selection-mode="true"
                :request-allowed-only="true"
                selection-event="encounter-referral-service-selected"
                :key="'encounter-referral-service-catalog'"
            />

            <div class="mt-8">
                <button type="button" @click="openServiceCatalog = false" class="button-minor">
                    {{ __('forms.cancel') }}
                </button>
            </div>
        </x-dialog-drawer>
    </div>
@endif
