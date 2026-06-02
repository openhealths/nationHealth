{{-- E-Prescription Form Drawer Overlay --}}
<div x-show="showEPrescriptionDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     x-cloak
     @click="showEPrescriptionDrawer = false"
     class="fixed top-0 right-0 h-screen pt-20 bg-gray-900/50"
     style="z-index: 46; width: calc(80% - 30px);"
></div>

{{-- E-Prescription Form Drawer --}}
<div id="eprescription-form-drawer-right"
     x-show="showEPrescriptionDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="translate-x-full"
     x-cloak
     class="fixed top-0 right-0 h-screen pt-20 p-4 overflow-y-auto bg-white dark:bg-gray-800 shadow-2xl"
     style="z-index: 47; width: calc(80% - 60px);"
     tabindex="-1"
>
    <h3 class="modal-header">
        Виписати Електронний Рецепт (на основі Плану Лікування)
    </h3>

    @if(!empty($ePrescriptionForm))
    <form wire:submit.prevent="validateEPrescription">
        {{-- Section 1: Main Reference Data --}}
        <fieldset class="fieldset">
            <legend class="legend">Основні дані</legend>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                <div class="form-group group">
                    <label class="label">План лікування</label>
                    <input type="text" class="input bg-gray-50 dark:bg-gray-700 cursor-not-allowed" value="{{ $carePlan->title }}" disabled />
                </div>
                <div class="form-group group">
                    <label class="label">Призначення (Activity)</label>
                    <input type="text" class="input bg-gray-50 dark:bg-gray-700 cursor-not-allowed" value="{{ $ePrescriptionSelectedActivity ? ($ePrescriptionSelectedActivity['description'] ?? 'Призначення ЛЗ') : '' }}" disabled />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                <div class="form-group group">
                    <label class="label">Лікарський засіб (ЛЗ)</label>
                    <input type="text" class="input bg-gray-50 dark:bg-gray-700 cursor-not-allowed font-medium text-gray-900 dark:text-white" value="{{ $ePrescriptionSelectedProduct ? ($ePrescriptionSelectedProduct['name'] ?? '') : '' }}" disabled />
                </div>
                <div class="form-group group">
                    <label class="label">Медична програма</label>
                    <input type="text" class="input bg-gray-50 dark:bg-gray-700 cursor-not-allowed" value="{{ $ePrescriptionSelectedProgram ? ($ePrescriptionSelectedProgram['name'] ?? '') : 'Загальні призначення' }}" disabled />
                </div>
            </div>
        </fieldset>

        {{-- Section 2: Treatment Duration and Quantities --}}
        <fieldset class="fieldset">
            <legend class="legend">Курс лікування та Кількість</legend>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                <div class="form-group group">
                    <label class="label">Дата початку лікування*</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                            @icon('calendar-month', 'w-4 h-4 text-gray-500')
                        </div>
                        <input type="text"
                               class="input peer ps-10"
                               placeholder="dd.mm.yyyy"
                               wire:model.live="ePrescriptionForm.started_at"
                               @if(!$ePrescriptionSkipTreatmentPeriod) disabled @endif
                        />
                    </div>
                </div>

                <div class="form-group group">
                    <label class="label">Тривалість курсу (дні)*</label>
                    <input type="number" class="input peer" min="1" wire:model.live="ePrescriptionForm.duration" />
                </div>

                <div class="form-group group">
                    <label class="label">Дата закінчення лікування</label>
                    <input type="text" class="input bg-gray-50 dark:bg-gray-700 cursor-not-allowed" value="{{ $ePrescriptionForm['ended_at'] ?? '' }}" disabled />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                <div class="form-group group">
                    <label class="label">Кількість ЛЗ на весь курс*</label>
                    <div class="flex gap-2">
                        @if(!empty($ePrescriptionMultiples))
                            <select class="input-select peer w-full" wire:model="ePrescriptionForm.medication_qty">
                                <option value="">Оберіть кількість...</option>
                                @foreach($ePrescriptionMultiples as $qty)
                                    <option value="{{ $qty }}">{{ $qty }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="number" step="any" min="0.01" class="input peer w-full" wire:model="ePrescriptionForm.medication_qty" />
                        @endif
                        <span class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-500 text-sm">
                            {{ $ePrescriptionForm['medication_unit'] ?? 'од.' }}
                        </span>
                    </div>
                </div>

                @if(!empty($ePrescriptionPackages))
                    <div class="form-group group col-span-2">
                        <label class="label">Первинна упаковка (обсяг)*</label>
                        <select class="input-select peer w-full" wire:model="ePrescriptionForm.container_dosage">
                            <option value="">Оберіть упаковку...</option>
                            @foreach($ePrescriptionPackages as $package)
                                @php
                                    $value = $package['container_dosage']['value'] ?? null;
                                    $unit = $package['container_dosage']['numerator']['unit'] ?? ($package['container_dosage']['numerator_unit'] ?? 'од.');
                                    $code = $package['container_dosage']['code'] ?? '';
                                @endphp
                                <option value="{{ $value }}|{{ $unit }}|{{ $code }}">{{ $value }} {{ $unit }} (Код: {{ $code }})</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>

            {{-- Quantity Warnings --}}
            @if($ePrescriptionWarningMessage)
                <div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400 border border-red-200 dark:border-red-900" role="alert">
                    <div class="flex items-center gap-2">
                        @icon('alert-circle', 'w-5 h-5 text-red-500')
                        <span class="font-bold">Увага!</span>
                    </div>
                    <div class="mt-2">{!! $ePrescriptionWarningMessage !!}</div>
                </div>
            @endif

            @if($ePrescriptionShowRemainingQtyWarning)
                <div class="p-4 mb-4 text-sm text-yellow-800 rounded-lg bg-yellow-50 dark:bg-gray-800 dark:text-yellow-300 border border-yellow-200 dark:border-yellow-900" role="alert">
                    <div class="flex items-center gap-2">
                        @icon('alert-circle', 'w-5 h-5 text-yellow-500')
                        <span class="font-bold">Увага! залишкова кількість</span>
                    </div>
                    <div class="mt-2">
                        Для пацієнта в плані лікування залишалось лікарського засобу в кількості {{ $ePrescriptionRemainingQty }} {{ $ePrescriptionForm['medication_unit'] ?? '' }}.
                    </div>
                </div>
            @endif
        </fieldset>

        {{-- Section 3: Dosage instructions (Signature) --}}
        <fieldset class="fieldset">
            <legend class="legend">Спосіб застосування (Сигнатура)</legend>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                <div class="form-group group">
                    <label class="label">Разова доза ЛЗ (на один прийом)*</label>
                    <div class="flex gap-2">
                        <input type="number" step="any" min="0.01" class="input peer w-full" wire:model="ePrescriptionForm.max_dose_per_administration" />
                        <span class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-500 text-sm">
                            {{ $ePrescriptionForm['medication_unit'] ?? 'од.' }}
                        </span>
                    </div>
                </div>

                <div class="form-group group">
                    <label class="label">Максимальна добова доза ЛЗ*</label>
                    <div class="flex gap-2">
                        <input type="number" step="any" min="0.01" class="input peer w-full" wire:model="ePrescriptionForm.max_dose_per_period" />
                        <span class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-500 text-sm">
                            {{ $ePrescriptionForm['medication_unit'] ?? 'од.' }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="form-group group mb-4">
                <label class="label">Текст сигнатури (спосіб застосування)*</label>
                <textarea class="block w-full p-4 text-sm text-gray-900 bg-white border border-gray-200 rounded-2xl focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                          rows="3"
                          placeholder="Приймати по 1 таблетці 2 рази на день після їжі"
                          wire:model="ePrescriptionForm.signature_text"
                ></textarea>
            </div>

            @if($ePrescriptionShowDailyDoseWarning)
                <div class="p-4 mb-4 text-sm text-yellow-800 rounded-lg bg-yellow-50 dark:bg-gray-800 dark:text-yellow-300 border border-yellow-200 dark:border-yellow-900" role="alert">
                    <div class="flex items-center gap-2">
                        @icon('alert-circle', 'w-5 h-5 text-yellow-500')
                        <span class="font-bold">Увага! Перевищено добову дозу</span>
                    </div>
                    <div class="mt-2">
                        Пацієнту перевищено підтримуючу/визначену в плані лікування добову дозу лікарського засобу [{{ $ePrescriptionSelectedProduct['name'] ?? '' }}].
                        <br/>
                        Чи впевнені Ви у виписуванні пацієнту такої кількості лікарського засобу на добу?
                    </div>
                    <div class="mt-4 flex gap-4">
                        <button type="button" class="button-primary py-1 px-3 text-xs" wire:click="confirmExceededDailyDose(true)">
                            Так, впевнений
                        </button>
                        <button type="button" class="button-minor py-1 px-3 text-xs" wire:click="confirmExceededDailyDose(false)">
                            Ні, скоригувати
                        </button>
                    </div>
                </div>
            @endif
        </fieldset>

        {{-- Section 4: Authentication --}}
        <fieldset class="fieldset">
            <legend class="legend">Автентифікація пацієнта</legend>
            <div class="form-group group">
                <label class="label">Метод автентифікації*</label>
                <select class="input-select peer w-full" wire:model="ePrescriptionForm.inform_with">
                    <option value="">Оберіть метод...</option>
                    @foreach($ePrescriptionAuthMethods as $method)
                        @php
                            $typeLabel = $method['type'] === 'OTP' ? 'Автентифікація через СМС (OTP)' : ($method['type'] === 'THIRD_PERSON' ? 'Автентифікація через довірену особу' : 'Автентифікація через документи');
                            $valueLabel = $method['phone_number'] ?? $method['alias'] ?? '';
                        @endphp
                        <option value="{{ $method['uuid'] }}|{{ $method['type'] }}|{{ $valueLabel }}">{{ $typeLabel }} {{ $valueLabel ? '('.$valueLabel.')' : '' }}</option>
                    @endforeach
                </select>
            </div>
        </fieldset>

        <div class="mt-6 flex justify-start gap-3">
            <button type="button" class="button-minor" @click="showEPrescriptionDrawer = false">
                {{ __('forms.cancel') }}
            </button>
            <button type="submit" class="button-primary" @if($ePrescriptionShowDailyDoseWarning || $ePrescriptionWarningMessage) disabled class="opacity-50 cursor-not-allowed button-primary" @endif>
                Сформувати Заявку
            </button>
        </div>
    </form>
    @endif
</div>
