<div class="form-row-3"
     x-data="{ selectedTypes: $wire.entangle('typeFilter') }"
>
    {{-- Filter by type --}}
    <div class="form-group group">
        <label for="typeFilter" class="label mb-1">{{ __('declarations.show') }}</label>
        <div class="relative">
            <input type="text"
                   id="typeFilter"
                   class="input peer w-full cursor-pointer text-gray-500 dark:text-gray-400"
                   @click="openType = !openType"
                   :value="selectedTypes.length ? selectedTypes.map(type => type === 'request' ? 'Заявки на декларацію' : (type === 'declaration' ? 'Декларації' : type)).join(', ') : ''"
                   readonly
            />
            @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400 pointer-events-none')
            <div x-show="openType"
                 @click.away="openType = false"
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="transform opacity-0 scale-95"
                 x-transition:enter-end="transform opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-75"
                 x-transition:leave-start="transform opacity-100 scale-100"
                 x-transition:leave-end="transform opacity-0 scale-95"
                 class="absolute z-10 mt-2 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg"
            >
                <ul class="py-2 px-3 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                    <li>
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input wire:model="typeFilter"
                                   type="checkbox"
                                   value="request"
                                   class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                            />
                            <span>{{ __('declarations.requests') }}</span>
                        </label>
                    </li>
                    <li>
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input wire:model="typeFilter"
                                   type="checkbox"
                                   value="declaration"
                                   class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                            />
                            <span>{{ __('forms.declarations') }}</span>
                        </label>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Search by declaration number --}}
    <div class="form-group group">
        <input type="text"
               id="searchByNumber"
               placeholder=" "
               class="input peer"
               wire:model="searchByNumber"
               autocomplete="off"
        />
        <label for="searchByNumber" class="label">
            {{ __('declarations.number') }}
        </label>
    </div>
</div>

{{-- Filter by declaration status --}}
<div class="form-row-3"
     :class="openType ? 'mt-20' : 'mt-6'"
     x-data="{ open: false, open1: false, selectedStatuses: $wire.entangle('statusFilter'), reorganizedDeclarations: $wire.entangle('reorganizationFilter') }"
>
    <label for="statusFilter" class="label mb-1">{{ __('declarations.show') }}</label>
    <div class="relative">
        <input type="text"
               id="statusFilter"
               class="input peer w-full cursor-pointer text-gray-500 dark:text-gray-400"
               @click="open = !open"
               :value="selectedStatuses.length ? selectedStatuses.map(status => status === 'active' ? 'Активні декларації' : (status === 'CANCELLED' ? 'Відмінені декларації' : status)).join(', ') : ''"
               readonly
        />
        @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400 pointer-events-none')
        <div x-show="open"
             @click.away="open = false"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="transform opacity-0 scale-95"
             x-transition:enter-end="transform opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="transform opacity-100 scale-100"
             x-transition:leave-end="transform opacity-0 scale-95"
             class="absolute z-10 mt-2 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg"
        >
            <ul class="py-2 px-3 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                <li>
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input wire:model="statusFilter"
                               type="checkbox"
                               value="active"
                               class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                        />
                        <span>{{ __('declarations.active') }}</span>
                    </label>
                </li>
                <li>
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input wire:model="statusFilter"
                               type="checkbox"
                               value="CANCELLED"
                               class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                        />
                        <span>{{ __('declarations.cancelled') }}</span>
                    </label>
                </li>
            </ul>
        </div>
    </div>


    <div class="relative">
        <label for="reorganizationFilter" class="label mb-1">{{ __('declarations.for_reorganized') }}</label>
        <input type="text"
               id="reorganizationFilter"
               class="input peer w-full cursor-pointer text-gray-500 dark:text-gray-400"
               @click="open1 = !open1"
               :value="reorganizedDeclarations.length ? reorganizedDeclarations.map(status => status === 'to_be_resigned' ? 'Потребують переукладення' : (status === 'resigned' ? 'Переукладені' : status)).join(', ') : ''"
               placeholder="{{ __('Оберіть тип декларацій') }}"
               readonly
        />
        @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400 pointer-events-none')
        <div x-show="open1"
             @click.away="open1 = false"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="transform opacity-0 scale-95"
             x-transition:enter-end="transform opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="transform opacity-100 scale-100"
             x-transition:leave-end="transform opacity-0 scale-95"
             class="absolute z-10 mt-2 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg"
        >
            <ul class="py-2 px-3 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                <li>
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input wire:model="reorganizationFilter"
                               type="checkbox"
                               value="to_be_resigned"
                               class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                        />
                        <span>{{ __('declarations.to_be_resigned') }}</span>
                    </label>
                </li>
                <li>
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input wire:model="reorganizationFilter"
                               type="checkbox"
                               value="resigned"
                               class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                        />
                        <span>{{ __('declarations.resigned') }}</span>
                    </label>
                </li>
            </ul>
        </div>
    </div>
</div>
