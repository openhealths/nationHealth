<div class="px-4 pt-6">
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm dark:border-gray-700 sm:p-6 dark:bg-gray-800">
        <div class="w-full mb-4">
            <h1 class="text-xl font-semibold text-gray-900 sm:text-2xl dark:text-white">Пошук та Робота з Електронними Направленнями</h1>
            <p class="text-sm font-normal text-gray-500 dark:text-gray-400">
                Введіть 16-значний номер направлення (Requisition) для пошуку.
            </p>
        </div>

        <!-- Search Form -->
        <div class="flex items-center space-x-4 mb-6">
            <div class="relative w-full md:w-1/2">
                <input type="text" 
                       wire:model.defer="requisition" 
                       wire:keydown.enter="search"
                       x-data 
                       x-on:input="$event.target.value = $event.target.value.replace(/[^A-Za-z0-9]/g, '').replace(/(.{4})(?!$)/g, '$1-').toUpperCase().slice(0, 19)"
                       class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary-500 focus:border-primary-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-primary-500 dark:focus:border-primary-500" 
                       placeholder="XXXX-XXXX-XXXX-XXXX" 
                       required>
            </div>
            <button type="button" wire:click="search" class="text-white bg-primary-700 hover:bg-primary-800 focus:ring-4 focus:ring-primary-300 font-medium rounded-lg text-sm px-5 py-2.5 dark:bg-primary-600 dark:hover:bg-primary-700 focus:outline-none dark:focus:ring-primary-800">
                Знайти направлення
            </button>
        </div>

        <!-- Error Message -->
        @if ($errorMessage)
            <div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400" role="alert">
                {{ $errorMessage }}
            </div>
        @endif

        <!-- Search Results -->
        @if ($hasSearched && empty($errorMessage) && !empty($searchResults))
            @php
                $statuses = [
                    'active' => 'Активне',
                    'completed' => 'Погашене',
                    'entered_in_error' => 'Введено помилково',
                    'draft' => 'Чернетка',
                    'revoked' => 'Відкликане',
                    'new' => 'Нове',
                    'in_progress' => 'В роботі',
                    'in_queue' => 'В черзі',
                ];
                
                $categories = [
                    'hospitalization' => 'Госпіталізація',
                    'consultation' => 'Консультація',
                    'imaging' => 'Візуалізація (Діагностика)',
                    'laboratory_procedure' => 'Лабораторна процедура',
                    'surgical_procedure' => 'Хірургічна процедура',
                    'transfer' => 'Переведення',
                    'treatment' => 'Лікування (Процедура)',
                ];
            @endphp
            <div class="mt-6 border-t border-gray-200 dark:border-gray-700 pt-4">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Результати пошуку</h3>
                
                <div class="grid gap-4">
                    @foreach($searchResults as $referral)
                        <div class="p-4 bg-gray-50 rounded-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">ID: {{ $referral['id'] ?? 'Невідомо' }}</p>
                                    <h4 class="text-md font-semibold text-gray-900 dark:text-white">
                                        Категорія: {{ $categories[$referral['category']['coding'][0]['code'] ?? ''] ?? ($referral['category']['coding'][0]['code'] ?? 'Не вказана') }}
                                    </h4>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white mt-2">Статус: 
                                        <span class="bg-blue-100 text-blue-800 text-xs font-medium mr-2 px-2.5 py-0.5 rounded dark:bg-blue-900 dark:text-blue-300">
                                            {{ $statuses[$referral['status'] ?? ''] ?? ($referral['status'] ?? 'Невідомо') }}
                                        </span>
                                    </p>
                                    @if(isset($referral['program_processing_status']))
                                        <p class="text-sm font-medium text-gray-900 dark:text-white mt-1">Статус за програмою: 
                                            <span class="bg-purple-100 text-purple-800 text-xs font-medium mr-2 px-2.5 py-0.5 rounded dark:bg-purple-900 dark:text-purple-300">
                                                {{ $referral['program_processing_status'] }}
                                            </span>
                                        </p>
                                    @endif
                                </div>
                                <div class="flex flex-col space-y-2">
                                    @if(($referral['status'] ?? '') === 'active' || ($referral['status'] ?? '') === 'new')
                                        <button wire:click="process('{{ $referral['id'] }}', '{{ $referral['subject']['identifier']['value'] ?? '' }}')" type="button" class="text-white bg-green-600 hover:bg-green-700 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2 dark:bg-green-600 dark:hover:bg-green-700 dark:focus:ring-green-800">
                                            Взяти в роботу
                                        </button>
                                    @endif

                                    @if(($referral['status'] ?? '') === 'in_progress' || ($referral['status'] ?? '') === 'in_queue' || ($referral['program_processing_status'] ?? '') === 'in_progress')
                                        <button wire:click="openCompleteModal('{{ $referral['id'] }}')" type="button" class="text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-4 py-2 dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus:ring-blue-800">
                                            Погасити направлення
                                        </button>
                                        
                                        <button wire:click="openCancelModal('{{ $referral['id'] }}')" type="button" class="text-red-600 inline-flex items-center hover:text-white border border-red-600 hover:bg-red-600 focus:ring-4 focus:outline-none focus:ring-red-300 font-medium rounded-lg text-sm px-4 py-2 text-center dark:border-red-500 dark:text-red-500 dark:hover:text-white dark:hover:bg-red-600 dark:focus:ring-red-900 mt-2">
                                            Відмінити використання
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <!-- Cancel Usage Modal -->
    @if($showCancelModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900 bg-opacity-50" aria-modal="true" role="dialog">
            <div class="relative w-full max-w-lg bg-white rounded-lg shadow dark:bg-gray-800 p-6">
                <button type="button" wire:click="$set('showCancelModal', false)" class="absolute top-3 right-2.5 text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ml-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white">
                    <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/>
                    </svg>
                    <span class="sr-only">Закрити</span>
                </button>
                <div class="mt-2">
                    <h3 class="mb-3 text-lg font-bold text-gray-900 dark:text-white">Відміна використання направлення</h3>
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                        Вкажіть причину відміни використання направлення. Це поле є обов'язковим для ЄСОЗ.
                    </p>
                    <div class="mb-5">
                        <label for="cancelLetter" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">
                            Пояснення (explanatory letter) <span class="text-red-500">*</span>
                        </label>
                        <textarea id="cancelLetter"
                                  wire:model="cancelExplanatoryLetter"
                                  rows="4"
                                  class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white"
                                  placeholder="Введіть причину відміни використання направлення..."
                        ></textarea>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button wire:click="confirmCancelUsage"
                                type="button"
                                class="text-white bg-red-600 hover:bg-red-700 focus:ring-4 focus:outline-none focus:ring-red-300 font-medium rounded-lg text-sm px-5 py-2.5"
                                {{ empty(trim($cancelExplanatoryLetter ?? '')) ? 'disabled' : '' }}
                        >
                            Підтвердити відміну
                        </button>
                        <button wire:click="$set('showCancelModal', false)" type="button" class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-gray-200 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900">
                            Скасувати
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Complete Referral Modal -->
    @if($showCompleteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900 bg-opacity-50" aria-modal="true" role="dialog">
            <div class="relative w-full max-w-lg bg-white rounded-lg shadow dark:bg-gray-800 p-6">
                <button type="button" wire:click="$set('showCompleteModal', false)" class="absolute top-3 right-2.5 text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ml-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white">
                    <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/>
                    </svg>
                    <span class="sr-only">Закрити</span>
                </button>
                <div class="text-center mt-2">
                    <h3 class="mb-5 text-lg font-bold text-gray-900 dark:text-white">Погашення направлення</h3>
                    <div class="mb-5 text-left">
                        <label for="encounterUuid" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Введіть ID взаємодії (ЕМЗ)</label>
                        {{-- 
                        <select id="encounterUuid" wire:model="selectedEncounterUuid" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                            <option value="">Оберіть ЕМЗ...</option>
                            @foreach($availableEncounters as $enc)
                                <option value="{{ $enc['uuid'] }}">{{ $enc['label'] }}</option>
                            @endforeach
                        </select>
                        @if(empty($availableEncounters))
                            <p class="mt-2 text-sm text-red-600 dark:text-red-400">Не знайдено жодного ЕМЗ для цього пацієнта та лікаря. Спочатку створіть взаємодію.</p>
                        @endif
                        --}}
                        <input type="text" id="encounterUuid" wire:model="selectedEncounterUuid" placeholder="Введіть UUID взаємодії..." class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                    </div>
                    <div class="flex justify-center gap-3">
                        <button wire:click="confirmComplete" type="button" class="text-white bg-blue-600 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 dark:focus:ring-blue-800 font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center disabled:opacity-50">
                            Підтвердити погашення
                        </button>
                        <button wire:click="$set('showCompleteModal', false)" type="button" class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-gray-200 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900 focus:z-10 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-500 dark:hover:text-white dark:hover:bg-gray-600 dark:focus:ring-gray-600">
                            Скасувати
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
