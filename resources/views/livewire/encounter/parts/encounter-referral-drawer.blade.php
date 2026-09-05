@if ($showEncounterReferralDrawer)
    <div
        wire:click="closeEncounterReferralDrawer"
        class="fixed top-0 right-0 z-[46] h-screen bg-gray-900/50 pt-20"
        style="width: calc(80% - 30px)"
    ></div>

    <div
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
                        <label class="label required">{{ __('care-plan.service') }}</label>
                        <div class="flex gap-2">
                            <input
                                type="text"
                                class="input peer w-full"
                                placeholder="Код або назва послуги"
                                wire:model="encounterReferralServiceSearch"
                                wire:keydown.enter.prevent="searchEncounterReferralServices"
                            />
                            <button type="button" class="button-primary" wire:click="searchEncounterReferralServices">
                                Пошук
                            </button>
                        </div>
                        @error('encounterReferralForm.service_id')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    @if ($encounterReferralServiceResults !== [])
                        <div class="max-h-64 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-3 md:col-span-2">
                            @foreach ($encounterReferralServiceResults as $service)
                                <button
                                    type="button"
                                    wire:click="selectEncounterReferralService('{{ $service['id'] }}')"
                                    class="w-full rounded-md border border-gray-200 px-3 py-2 text-left text-sm hover:border-blue-300 hover:bg-blue-50"
                                >
                                    <div class="font-medium text-gray-900">
                                        {{ ($service['code'] ?? '') }} — {{ $service['name'] ?? 'Послуга' }}
                                    </div>
                                    @php
                                        $serviceCategoryKey = 'care-plan.referral_category.'.strtolower((string) ($service['category'] ?? ''));
                                    @endphp
                                    @if (\Illuminate\Support\Facades\Lang::has($serviceCategoryKey))
                                        <div class="text-xs text-gray-500">{{ __($serviceCategoryKey) }}</div>
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
                        <label class="label">Обрана послуга</label>
                        <input
                            type="text"
                            class="input peer w-full"
                            value="{{ !empty($encounterReferralSelectedService) ? (($encounterReferralSelectedService['code'] ?? '') . ' — ' . ($encounterReferralSelectedService['name'] ?? '')) : '' }}"
                            placeholder="{{ __('care-plan.select_service') }}"
                            readonly
                        />
                    </div>
                    <div class="form-group group">
                        <label class="label required">Категорія</label>
                        <select class="input-select peer w-full" wire:model="encounterReferralForm.category">
                            @foreach (__('care-plan.referral_category') as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                            <option value="counselling">{{ __('care-plan.referral_category.counseling') }}</option>
                            <option value="transfer_of_care">{{ __('care-plan.referral_category.transfer') }}</option>
                        </select>
                    </div>
                    <div class="form-group group">
                        <label class="label">Програма</label>
                        <select class="input-select peer w-full" wire:model="encounterReferralForm.program_id">
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
                        <label class="label required">Дата початку</label>
                        <input
                            type="text"
                            class="input peer"
                            placeholder="dd.mm.yyyy"
                            wire:model="encounterReferralForm.started_at"
                        />
                    </div>
                    <div class="form-group group">
                        <label class="label required">Дата закінчення</label>
                        <input
                            type="text"
                            class="input peer"
                            placeholder="dd.mm.yyyy"
                            wire:model="encounterReferralForm.ended_at"
                        />
                    </div>
                    <div class="form-group group">
                        <label class="label required">Кількість</label>
                        <input
                            type="number"
                            min="0.01"
                            step="any"
                            class="input peer"
                            wire:model="encounterReferralForm.quantity"
                        />
                    </div>
                </div>
                <div class="mb-4 grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div class="form-group group">
                        <label class="label required">Пріоритет</label>
                        <select class="input-select peer w-full" wire:model="encounterReferralForm.priority">
                            <option value="routine">{{ __('care-plan.priority_options.routine') }}</option>
                            <option value="urgent">{{ __('care-plan.priority_options.urgent') }}</option>
                            <option value="asap">{{ __('care-plan.priority_options.asap') }}</option>
                            <option value="stat">{{ __('care-plan.priority_options.stat') }}</option>
                        </select>
                    </div>
                </div>
            </fieldset>

            <fieldset class="fieldset">
                <legend class="legend">Додатково</legend>
                <div class="mb-4 grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div class="form-group group">
                        <label class="label">Метод автентифікації</label>
                        <select class="input-select peer w-full" wire:model="encounterReferralForm.inform_with">
                            <option value="">Не обрано</option>
                            @foreach ($encounterReferralAuthMethods as $method)
                                <option value="{{ \App\Services\MedicalEvents\InformWith::formValue($method) }}">
                                    {{ $method['label'] ?? '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group group">
                        <label class="label">Інструкція пацієнту</label>
                        <input type="text" class="input peer" wire:model="encounterReferralForm.patient_instruction" />
                    </div>
                </div>
                <div class="form-group group">
                    <label class="label">Примітки</label>
                    <textarea class="input peer min-h-20" wire:model="encounterReferralForm.note"></textarea>
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
    </div>
@endif
