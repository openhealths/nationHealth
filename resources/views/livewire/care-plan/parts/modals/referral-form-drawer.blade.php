{{-- Outgoing Referral Form Drawer Overlay --}}
<div x-show="showReferralDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     x-cloak
     @click="showReferralDrawer = false"
     class="fixed top-0 right-0 h-screen pt-20 bg-gray-900/50"
     style="z-index: 46; width: calc(80% - 30px);"
></div>

{{-- Outgoing Referral Form Drawer --}}
<div id="referral-form-drawer-right"
     x-show="showReferralDrawer"
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
        Виписати Електронне Направлення (на основі Плану Лікування)
    </h3>

    @if(!empty($referralForm))
    <form wire:submit.prevent="validateReferral">
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
                    <input type="text" class="input bg-gray-50 dark:bg-gray-700 cursor-not-allowed" value="{{ $referralSelectedActivity ? ($referralSelectedActivity['description'] ?? 'Призначення') : '' }}" disabled />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                <div class="form-group group">
                    <label class="label">Послуга / Виріб (Код)</label>
                    <input type="text" class="input bg-gray-50 dark:bg-gray-700 cursor-not-allowed font-medium text-gray-900 dark:text-white" value="{{ $referralForm['code'] }}" disabled />
                </div>
                <div class="form-group group">
                    <label class="label">Тип направлення</label>
                    <input type="text" class="input bg-gray-50 dark:bg-gray-700 cursor-not-allowed" value="{{ $referralForm['kind'] === 'service_request' ? 'Направлення на Послугу' : 'Направлення на виріб (Device)' }}" disabled />
                </div>
            </div>
        </fieldset>

        {{-- Section 2: Validity and Quantities --}}
        <fieldset class="fieldset">
            <legend class="legend">Термін дії та Кількість</legend>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                <div class="form-group group">
                    <label class="label">Дата початку дії*</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                            @icon('calendar-month', 'w-4 h-4 text-gray-500')
                        </div>
                        <input type="text"
                               class="input peer ps-10"
                               placeholder="dd.mm.yyyy"
                               wire:model.live="referralForm.started_at"
                        />
                    </div>
                </div>

                <div class="form-group group">
                    <label class="label">Дата закінчення дії*</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                            @icon('calendar-month', 'w-4 h-4 text-gray-500')
                        </div>
                        <input type="text"
                               class="input peer ps-10"
                               placeholder="dd.mm.yyyy"
                               wire:model.live="referralForm.ended_at"
                        />
                    </div>
                </div>

                <div class="form-group group">
                    <label class="label">Кількість*</label>
                    <div class="flex gap-2">
                        <input type="number" step="any" min="0.01" class="input peer w-full" wire:model.live="referralForm.quantity" />
                        <span class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-500 text-sm">
                            од.
                        </span>
                    </div>
                </div>
            </div>

            @if($referralForm['kind'] === 'service_request')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                    <div class="form-group group">
                        <label class="label">Категорія направлення*</label>
                        <input type="text" class="input bg-gray-50 dark:bg-gray-700 cursor-not-allowed" value="{{ $referralForm['category_label'] ?? $referralForm['category'] }}" disabled />
                    </div>

                    <div class="form-group group">
                        <label class="label">Пріоритет*</label>
                        <select class="input-select peer w-full" wire:model="referralForm.priority">
                            <option value="routine">Планове (Routine)</option>
                            <option value="urgent">Ургентне (Urgent)</option>
                            <option value="asap">Якнайшвидше (ASAP)</option>
                            <option value="stat">Негайно (STAT)</option>
                        </select>
                    </div>
                </div>

                @if(!empty($referralSelectedActivity['program'] ?? null))
                    <div class="form-group group mb-4">
                        <label class="label">Медична програма</label>
                        <input type="text" class="input bg-gray-50 dark:bg-gray-700 cursor-not-allowed" value="{{ $dictionaries['medical_programs'][$referralSelectedActivity['program']] ?? $referralSelectedActivity['program'] }}" disabled />
                    </div>
                @endif
            @else
                <div class="grid grid-cols-1 gap-6 mb-4">
                    <div class="form-group group">
                        <label class="label">Пріоритет*</label>
                        <select class="input-select peer w-full" wire:model="referralForm.priority">
                            <option value="routine">Планове (Routine)</option>
                            <option value="urgent">Ургентне (Urgent)</option>
                            <option value="asap">Якнайшвидше (ASAP)</option>
                            <option value="stat">Негайно (STAT)</option>
                        </select>
                    </div>
                </div>
            @endif

            {{-- Warnings --}}
            @if($referralWarningMessage)
                <div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400 border border-red-200 dark:border-red-900" role="alert">
                    <div class="flex items-center gap-2">
                        @icon('alert-circle', 'w-5 h-5 text-red-500')
                        <span class="font-bold">Увага!</span>
                    </div>
                    <div class="mt-2">{!! $referralWarningMessage !!}</div>
                </div>
            @endif

            @if($referralShowRemainingQtyWarning)
                <div class="p-4 mb-4 text-sm text-yellow-800 rounded-lg bg-yellow-50 dark:bg-gray-800 dark:text-yellow-300 border border-yellow-200 dark:border-yellow-900" role="alert">
                    <div class="flex items-center gap-2">
                        @icon('alert-circle', 'w-5 h-5 text-yellow-500')
                        <span class="font-bold">Увага! залишкова кількість</span>
                    </div>
                    <div class="mt-2">
                        Для пацієнта в плані лікування залишалось послуг/виробів в кількості {{ $referralRemainingQty }} од.
                    </div>
                </div>
            @endif
        </fieldset>

        {{-- Section 3: Extra Info --}}
        <fieldset class="fieldset">
            <legend class="legend">Додаткова інформація</legend>

            <div class="form-group group mb-4">
                <label class="label">Нотатка / Обґрунтування лікаря</label>
                <textarea class="block w-full p-4 text-sm text-gray-900 bg-white border border-gray-200 rounded-2xl focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                          rows="3"
                          placeholder="Вкажіть додаткову інформацію до направлення"
                          wire:model="referralForm.note"
                ></textarea>
            </div>

            @if(!empty($referralForm['supporting_info']))
                <div class="form-group group mb-4">
                    <label class="label">Клінічне обґрунтування (з призначення)</label>
                    <div class="space-y-1 bg-gray-50 dark:bg-gray-700/50 p-3 rounded-xl border border-gray-100 dark:border-gray-700">
                        @foreach($referralForm['supporting_info'] as $info)
                            <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                                <span class="badge badge-minor uppercase">{{ $info['type'] }}</span>
                                <span class="font-mono text-gray-800 dark:text-gray-200">{{ $info['uuid'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </fieldset>

        <div class="mt-6 flex justify-start gap-3">
            <button type="button" class="button-minor" @click="showReferralDrawer = false">
                {{ __('forms.cancel') }}
            </button>
            <button type="submit" class="button-primary" @if($referralWarningMessage) disabled class="opacity-50 cursor-not-allowed button-primary" @endif>
                Сформувати Заявку на Направлення
            </button>
        </div>
    </form>
    @endif
</div>
