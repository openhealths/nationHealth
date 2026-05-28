{{-- Services Drawer
     Alpine slide-over panel matching figma designs and mockup specifications. --}}
<template x-teleport="body">
    <div x-show="showServiceDrawer"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         x-cloak
         class="fixed inset-0 overflow-hidden z-[45]"
         role="dialog"
         aria-modal="true"
         aria-labelledby="services-drawer-label"
    >
        {{-- Full-viewport backdrop --}}
        <div class="fixed inset-0 bg-black/25 transition-opacity"
             aria-hidden="true"
             @click="$wire.set('showServiceDrawer', false)"
        ></div>

        {{-- Panel container --}}
        <div class="fixed inset-y-0 right-0 max-w-4xl w-full flex pl-10">
            <div
                x-show="showServiceDrawer"
                x-transition:enter="transform transition ease-in-out duration-300"
                x-transition:enter-start="translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transform transition ease-in-out duration-300"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full"
                class="w-screen max-w-4xl"
            >
                <div class="h-full flex flex-col bg-white dark:bg-gray-800 shadow-2xl overflow-y-auto p-8 relative pt-24">
                    {{-- Close Button --}}
                    <div class="absolute right-6 top-6 z-10">
                        <button type="button" 
                                @click="$wire.set('showServiceDrawer', false)" 
                                class="relative inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                        >
                            <span class="sr-only">Close drawer</span>
                            <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>

                    {{-- Header Area --}}
                    <div class="mb-6">
                        <div class="text-sm text-gray-500 mb-1">
                            {{ $carePlan->person->fullName }} - План лікування №{{ $carePlan->requisition ?? $carePlan->id }}
                        </div>
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white" id="services-drawer-label">
                            Нове призначення на послуги
                        </h2>
                    </div>

                    {{-- Content --}}
                    <form wire:submit.prevent="saveActivity" class="space-y-6 flex-1 flex flex-col justify-between">
                        <div class="space-y-6">
                            {{-- Section 1: Main Data --}}
                            <fieldset class="fieldset">
                                <legend class="legend">Основні дані</legend>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                    {{-- Service Selector --}}
                                    <div class="form-group group">
                                        <label for="service" class="label">Послуга*</label>
                                        <div class="relative">
                                            <button type="button"
                                                    class="input-select peer pr-12 w-full text-left {{ !empty($selectedProduct) ? 'text-gray-900 dark:text-white font-medium' : 'text-gray-500' }}"
                                                    aria-controls="service-search-drawer-right"
                                                    @click="$wire.set('showServiceSearchDrawer', true)"
                                            >
                                                {{ !empty($selectedProduct) ? (($selectedProduct['code'] ?? '') . ' - ' . ($selectedProduct['name'] ?? '')) : __('care-plan.select_service') }}
                                            </button>
                                            <button type="button" @click="$wire.set('showServiceSearchDrawer', true)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                                <svg class="w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                    <path stroke="currentColor" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"/>
                                                </svg>
                                            </button>
                                        </div>
                                        @error('activityForm.product_reference') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                    </div>
                                    {{-- Program Selector --}}
                                    <div class="form-group group">
                                        <label for="program" class="label">Програма</label>
                                        <select id="program" name="program" class="input-select peer w-full">
                                            <option selected value="">Державних фінансових гарантій</option>
                                        </select>
                                    </div>

                                    {{-- Quantity --}}
                                    <div class="form-group group">
                                        <label for="quantity" class="label">Кількість</label>
                                        <div class="flex gap-2">
                                            <input type="number" id="quantity" class="input peer w-full" wire:model="activityForm.quantity">
                                            <select class="input-select peer w-24" wire:model="activityForm.quantity_system">
                                                <option value="units">шт</option>
                                            </select>
                                        </div>
                                    </div>

                                    {{-- Start Date & Time --}}
                                    <div class="form-group group">
                                        <label class="label">Дата початку:</label>
                                        <div class="grid grid-cols-2 gap-2">
                                            <div class="relative">
                                                <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                                                    @icon('calendar-month', 'w-4 h-4 text-gray-500')
                                                </div>
                                                <input type="text"
                                                       class="input peer ps-10"
                                                       placeholder="02.04.2025"
                                                       datepicker-autohide
                                                       datepicker-format="dd.mm.yyyy"
                                                       datepicker-button="false"
                                                       wire:model.live="activityForm.scheduled_period_start"
                                                />
                                            </div>
                                            <div class="relative">
                                                <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                                                    <svg class="w-4 h-4 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                                    </svg>
                                                </div>
                                                <input type="text" class="input timepicker-uk ps-10" placeholder="02:30 PM" />
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Quantity per time --}}
                                    <div class="form-group group">
                                        <label for="quantity_per_time" class="label">Кількість за 1 раз</label>
                                        <div class="flex gap-2">
                                            <input type="number" id="quantity_per_time" name="quantity_per_time" class="input peer w-full" value="1">
                                            <select class="input-select peer w-24">
                                                <option selected value="units">шт</option>
                                            </select>
                                        </div>
                                    </div>

                                    {{-- End Date & Time --}}
                                    <div class="form-group group">
                                        <label class="label">Дата завершення:</label>
                                        <div class="grid grid-cols-2 gap-2">
                                            <div class="relative">
                                                <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                                                    @icon('calendar-month', 'w-4 h-4 text-gray-500')
                                                </div>
                                                <input type="text"
                                                       class="input peer ps-10"
                                                       placeholder="02.08.2025"
                                                       datepicker-autohide
                                                       datepicker-format="dd.mm.yyyy"
                                                       datepicker-button="false"
                                                       wire:model.live="activityForm.scheduled_period_end"
                                                />
                                            </div>
                                            <div class="relative">
                                                <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                                                    <svg class="w-4 h-4 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                                    </svg>
                                                </div>
                                                <input type="text" class="input timepicker-uk ps-10" placeholder="02:30 PM" />
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Number of times --}}
                                    <div class="form-group group">
                                        <label for="number_of_times" class="label">Кількість разів</label>
                                        <div class="flex gap-2">
                                            <input type="number" id="number_of_times" name="number_of_times" class="input peer w-full" value="1">
                                            <select class="input-select peer w-28">
                                                <option selected value="per_day">на день</option>
                                            </select>
                                        </div>
                                    </div>

                                    {{-- Duration --}}
                                    <div class="form-group group">
                                        <label for="duration" class="label">Тривалість</label>
                                        <div class="flex gap-2">
                                            <input type="number" id="duration" name="duration" class="input peer w-full" value="10">
                                            <select class="input-select peer w-24">
                                                <option selected value="days">днів</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </fieldset>

                            {{-- Section 2: Grounds for Prescription --}}
                            <fieldset class="fieldset" x-data="{ selectedGround: '' }">
                                <legend class="legend">Підстави для призначення</legend>

                                <div class="mb-6 max-w-xl">
                                    <select x-model="selectedGround" class="input-select peer w-full">
                                        <option value="">Обрати код за МКХ-10</option>
                                        @if(!empty($availableConditions))
                                            <optgroup label="Діагнози (Стани)">
                                                @foreach($availableConditions as $cond)
                                                    <option value="Condition|{{ $cond['uuid'] }}">{{ $cond['name'] }} (від {{ $cond['date'] }})</option>
                                                @endforeach
                                            </optgroup>
                                        @endif
                                        @if(!empty($availableReports))
                                            <optgroup label="Діагностичні звіти">
                                                @foreach($availableReports as $report)
                                                    <option value="DiagnosticReport|{{ $report['uuid'] }}">{{ $report['name'] }} (від {{ $report['date'] }})</option>
                                                @endforeach
                                            </optgroup>
                                        @endif
                                        @if(!empty($availableObservations))
                                            <optgroup label="Спостереження">
                                                @foreach($availableObservations as $obs)
                                                    <option value="Observation|{{ $obs['uuid'] }}">{{ $obs['name'] }} (від {{ $obs['date'] }})</option>
                                                @endforeach
                                            </optgroup>
                                        @endif
                                    </select>
                                </div>

                                <div class="mb-4">
                                    <h4 class="text-base font-bold text-gray-900 dark:text-white mb-4">
                                        Обґрунтування підстав
                                    </h4>

                                    <div class="overflow-x-auto rounded-lg border border-gray-100 dark:border-gray-700">
                                        <table class="w-full text-sm text-left">
                                            <thead class="thead-input">
                                                <tr>
                                                    <th scope="col" class="px-4 py-3 text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">ДАТА</th>
                                                    <th scope="col" class="px-4 py-3 text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">НАЗВА</th>
                                                    <th scope="col" class="px-4 py-3 text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider text-right">ДІЯ</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                                @forelse($linkedGrounds as $ground)
                                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                                            {{ $ground['date'] }}
                                                        </td>
                                                        <td class="px-4 py-3 text-gray-900 dark:text-white">
                                                            {{ $ground['name'] }}
                                                        </td>
                                                        <td class="px-4 py-3 text-right">
                                                            <button type="button" wire:click="removeLinkedGround('{{ $ground['uuid'] }}')" class="text-black dark:text-white hover:opacity-70 transition-opacity inline-block cursor-pointer">
                                                                @icon('delete', 'w-5 h-5')
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="3" class="px-4 py-8 text-center text-gray-400 italic">
                                                            Немає доданих обґрунтувань
                                                        </td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="mt-4">
                                        <button type="button" 
                                                @click="showMedicalRecordsSearchDrawer = true" 
                                                class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-medium text-sm flex items-center gap-1 transition-colors cursor-pointer"
                                        >
                                            + Додати медичний запис
                                        </button>
                                    </div>
                                </div>
                            </fieldset>

                            {{-- Section 3: Additional Information --}}
                            <fieldset class="fieldset">
                                <legend class="legend">Додаткова інформація</legend>

                                <div class="form-row-3 mb-4">
                                    <label for="expected_result" class="label">Очікуваний результат</label>
                                    <select id="expected_result" name="expected_result" class="input-select peer w-full">
                                        <option selected value="">Обрати результат</option>
                                    </select>
                                </div>

                                <div class="form-row">
                                    <label for="description" class="label">Розширений опис</label>
                                    <textarea id="description"
                                              class="input peer w-full"
                                              rows="4"
                                              placeholder="Опис"
                                              wire:model="activityForm.description"
                                    ></textarea>
                                </div>
                            </fieldset>
                        </div>

                        {{-- Footer Buttons --}}
                        <div class="mt-8 flex justify-start gap-3 pt-6 border-t border-gray-100">
                            <button type="button"
                                    class="button-minor"
                                    @click="$wire.set('showServiceDrawer', false)"
                            >
                                Скасувати
                            </button>

                            <button type="submit"
                                    class="button-primary"
                            >
                                Зберегти
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
