@php
    use App\Enums\Declaration\ReorganizedStatus;
    use App\Enums\Declaration\Status;
@endphp

<div class="form-row-3"
     x-data="{
        selectedTypes: $wire.entangle('typeFilter'),
        typesLabels: @js([
            'request' => __('Заявки на декларацію'),
            'declaration' => __('Декларації')
        ]),
    }"
>
    {{-- Filter by type --}}
    <div class="form-group group">
        <label for="typeFilter" class="label mb-1">{{ __('declarations.show') }}</label>
        <div class="relative">
            <input type="text"
                id="typeFilter"
                class="input peer w-full cursor-pointer text-gray-500 dark:text-gray-400"
                @click="openType = !openType"
                :value="selectedTypes.length ? selectedTypes.map(type => typesLabels[type] || type).join(', ') : ''"
                placeholder="{{ __('Оберіть вид декларацій') }}"
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
<div class="form-row-3 !z-[10]"
     :class="openType ? 'mt-20' : 'mt-6'"
     x-data="{
        open: false,
        open1: false,
        openDoctor: false,
        selectedStatuses: $wire.entangle('statusFilter'),
        statusLabels: @js([
            Status::ACTIVE->value => __('Активні'),
            Status::CANCELLED->value => __('Відмінені'),
            Status::TERMINATED->value => __('Протерміновані')
        ]),
        reorganizedDeclarations: $wire.entangle('reorganizationFilter'),
        reorganizedLabels: @js([
            ReorganizedStatus::TO_BE_RESIGNED->value => __('declarations.to_be_resigned'),
            ReorganizedStatus::RESIGNED->value => __('declarations.resigned')
        ]),
        selectedDoctors: $wire.entangle('doctorFilter'),
        doctors: @js($this->doctors->toArray()),
        getSelectedDoctorNames() {
            return this.doctors
                .filter(doctor => this.selectedDoctors.includes(doctor.uuid))
                .map(doctor => doctor.fullName)
                .join(', ');
        },
    }"
>
    <div class="form-group group">
        <label for="statusFilter" class="label mb-1">{{ __('declarations.show') }}</label>
        <div class="relative">
            <input type="text"
                id="statusFilter"
                class="input peer w-full cursor-pointer text-gray-500 dark:text-gray-400"
                @click="open = !open"
                :value="selectedStatuses.length ? selectedStatuses.map(status => (statusLabels[status] ?? status)).join(', ') : ''"
                placeholder="{{ __('Оберіть статус декларацій') }}"
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
                                value="{{ Status::ACTIVE->value }}"
                                class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                            />
                            <span>{{ __('declarations.active') }}</span>
                        </label>
                    </li>
                    <li>
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input wire:model="statusFilter"
                                type="checkbox"
                                value="{{ Status::TERMINATED->value }}"
                                class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                            />
                            <span>{{ __('declarations.terminated') }}</span>
                        </label>
                    </li>
                    <li>
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input wire:model="statusFilter"
                                type="checkbox"
                                value="{{ Status::CANCELLED->value }}"
                                class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                            />
                            <span>{{ __('declarations.cancelled') }}</span>
                        </label>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Filter by doctor --}}
    <div class="form-group group">
        <label for="doctorFilter" class="label mb-1">{{ __('employees.doctor') }}</label>
        <div class="relative">
            <input type="text"
                id="doctorFilter"
                class="input peer w-full cursor-pointer text-gray-500 dark:text-gray-400"
                @click="openDoctor = !openDoctor"
                :value="selectedDoctors.length ? getSelectedDoctorNames() : ''"
                placeholder="{{ __('employees.doctor_full_name') }}"
                readonly
            />
            @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400 pointer-events-none')
            <div x-show="openDoctor"
                @click.away="openDoctor = false"
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="transform opacity-0 scale-95"
                x-transition:enter-end="transform opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="transform opacity-100 scale-100"
                x-transition:leave-end="transform opacity-0 scale-95"
                class="absolute z-10 mt-2 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg max-h-60 overflow-y-auto"
            >
                <ul class="py-2 px-3 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                    <template x-for="doctor in doctors" :key="doctor.uuid">
                        <li>
                            <label class="flex items-center space-x-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-600 p-1 rounded">
                                <input type="checkbox"
                                    x-model="selectedDoctors"
                                    :value="doctor.uuid"
                                    class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                                />
                                <span x-text="doctor.fullName"></span>
                            </label>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </div>

    @if(legalEntity()->legators->isNotEmpty())
        <div class="relative">
            <label for="reorganizationFilter" class="label mb-1">{{ __('declarations.for_reorganized') }}</label>
            <input type="text"
                id="reorganizationFilter"
                class="input peer w-full cursor-pointer text-gray-500 dark:text-gray-400"
                @click="open1 = !open1"
                :value="reorganizedDeclarations.length ? reorganizedDeclarations.map(status => (reorganizedLabels[status] ?? status)).join(', ') : ''"
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
                                value="{{ ReorganizedStatus::TO_BE_RESIGNED->value }}"
                                class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                            />
                            <span>{{ ReorganizedStatus::TO_BE_RESIGNED->label() }}</span>
                        </label>
                    </li>
                    <li>
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input wire:model="reorganizationFilter"
                                type="checkbox"
                                value="{{ ReorganizedStatus::RESIGNED->value }}"
                                class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                            />
                            <span>{{ ReorganizedStatus::RESIGNED->label() }}</span>
                        </label>
                    </li>
                </ul>
            </div>
        </div>
    @endif
</div>
