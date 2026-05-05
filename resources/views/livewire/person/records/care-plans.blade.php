<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', \App\Models\MedicalEvents\Sql\CarePlan::class)
            <a href="{{ route('care-plan.create', [legalEntity(), 'personId' => $personId]) }}"
               class="flex items-center gap-2 button-primary px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('patients.starts_interacting') }}
            </a>
        @endcan

        <button type="button"
                class="button-primary-outline whitespace-nowrap px-5 py-2 text-sm"
        >
            {{ __('patients.data_access') }}
        </button>

        <button wire:click.prevent="sync"
                type="button"
                class="button-sync flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm"
        >
            @icon('refresh', 'w-4 h-4')
            {{ __('patients.sync_ehealth_data') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form p-4 shift-content">
        <div class="w-full mt-6" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>Пошук плану лікування</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <div class="relative">
                        <input wire:model="filterName"
                               type="text"
                               name="filterName"
                               id="filterName"
                               class="input peer w-full"
                               placeholder=" "
                               autocomplete="off"
                        />
                        <label for="filterName" class="label">
                            Назва
                        </label>
                        <button type="button" wire:click="$set('filterName', '')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                x-show="$wire.filterName">
                            @icon('close', 'w-4 h-4')
                        </button>
                    </div>
                </div>

                <div class="form-group group">
                    <div class="relative">
                        <input wire:model="filterEncounterId"
                               type="text"
                               name="filterEncounterId"
                               id="filterEncounterId"
                               class="input peer w-full"
                               placeholder=" "
                               autocomplete="off"
                        />
                        <label for="filterEncounterId" class="label">
                            ID взаємодії
                        </label>
                        <button type="button" wire:click="$set('filterEncounterId', '')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                x-show="$wire.filterEncounterId">
                            @icon('close', 'w-4 h-4')
                        </button>
                    </div>
                </div>

                <div class="form-group group">
                    <select wire:model="filterStatus"
                            name="filterStatus"
                            id="filterStatus"
                            class="input-select peer w-full"
                    >
                        <option value="">{{ __('forms.select') }} ...</option>
                        <option value="active">Активний</option>
                    </select>
                    <label for="filterStatus" class="label">
                        Статус
                    </label>
                </div>
            </div>

            <div class="mb-9 flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="search"
                            class="flex items-center gap-2 button-primary px-5 py-2.5 text-sm shadow-sm"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('patients.search') }}</span>
                    </button>
                    <button type="button" wire:click="resetFilters"
                            class="button-primary-outline-red px-5 py-2.5 text-sm"
                    >
                        {{ __('patients.reset_filters') }}
                    </button>
                    <button type="button"
                            class="flex items-center gap-2 button-minor px-5 py-2.5 text-sm whitespace-nowrap"
                            @click.prevent="showAdditionalParams = !showAdditionalParams"
                    >
                        @icon('adjustments', 'w-4 h-4 text-gray-500')
                        <span>Додаткові параметри пошуку</span>
                    </button>
                </div>

                <div class="relative" x-data="{ openGroupActions: false }" @click.outside="openGroupActions = false">
                    <button type="button"
                            @click="openGroupActions = !openGroupActions"
                            class="button-primary-outline px-5 py-2.5 text-sm"
                    >
                        {{ __('patients.group_actions') }}
                    </button>

                    <div x-show="openGroupActions"
                         x-transition
                         x-cloak
                         class="absolute right-0 top-full mt-2 z-10 w-[240px] bg-white rounded-lg shadow-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600 overflow-hidden"
                    >
                        <div class="py-1">
                            <button type="button"
                                    @click="openGroupActions = false"
                                    class="dropdown-button !flex items-center gap-2.5 w-full px-4 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors text-left"
                            >
                                <span class="text-gray-500">
                                    @icon('close', 'w-4 h-4')
                                </span>
                                {{ __('patients.revoke_access') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="care-plans-search-filters">
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterStartDateRange"
                                   type="text"
                                   name="filterStartDateRange"
                                   id="filterStartDateRange"
                                   class="datepicker-input with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="filterStartDateRange" class="wrapped-label">
                                Дата початку від - до
                            </label>
                        </div>
                    </div>

                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterEndDateRange"
                                   type="text"
                                   name="filterEndDateRange"
                                   id="filterEndDateRange"
                                   class="datepicker-input with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="filterEndDateRange" class="wrapped-label">
                                Дата завершення від - до
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <div class="relative">
                            <input wire:model="filterIsPartOf"
                                   type="text"
                                   name="filterIsPartOf"
                                   id="filterIsPartOf"
                                   class="input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="filterIsPartOf" class="label">
                                Є частиною плана лікування
                            </label>
                            <button type="button" wire:click="$set('filterIsPartOf', '')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                    x-show="$wire.filterIsPartOf">
                                @icon('close', 'w-4 h-4')
                            </button>
                        </div>
                    </div>

                    <div class="form-group group">
                        <div class="relative">
                            <input wire:model="filterIncludes"
                                   type="text"
                                   name="filterIncludes"
                                   id="filterIncludes"
                                   class="input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="filterIncludes" class="label">
                                Включає в себе план лікування
                            </label>
                            <button type="button" wire:click="$set('filterIncludes', '')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                    x-show="$wire.filterIncludes">
                                @icon('close', 'w-4 h-4')
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @foreach($carePlans as $plan)
                    <div class="record-inner-card" wire:key="care-plan-{{ $plan->id }}">
                        <div class="record-inner-header !grid !grid-cols-[56px_1fr_240px_64px] !p-0">
                            <div
                                class="record-inner-checkbox-col !w-full border-r border-gray-200 dark:border-gray-700 flex items-center justify-center">
                                <input type="checkbox" class="default-checkbox w-5 h-5">
                            </div>

                            <div class="record-inner-column !pl-4">
                                <div class="record-inner-label">Назва</div>
                                <div
                                    class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">{{ $plan->title }}</div>
                            </div>

                            <div
                                class="record-inner-column-bordered !w-full border-l border-gray-200 dark:border-gray-700">
                                <div class="record-inner-label">Статус:</div>
                                <div>
                                <span class="badge-green">
                                    {{ $plan->status_display }}
                                </span>
                                </div>
                            </div>

                            <div
                                class="record-inner-action-col border-l border-gray-200 dark:border-gray-700 !w-full flex items-center justify-center shrink-0 h-full relative">
                                <div x-data="{
                                open: false,
                                toggle() {
                                    if (this.open) { return this.close(); }
                                    this.$refs.button.focus();
                                    this.open = true;
                                },
                                close(focusAfter) {
                                    if (!this.open) return;
                                    this.open = false;
                                    focusAfter && focusAfter.focus()
                                }
                            }"
                                     @keydown.escape.prevent.stop="close($refs.button)"
                                     @focusin.window="!$refs.panel.contains($event.target) && close()"
                                     x-id="['dropdown-button']"
                                     class="relative"
                                >
                                    <button @click="toggle()"
                                            x-ref="button"
                                            :aria-expanded="open"
                                            :aria-controls="$id('dropdown-button')"
                                            type="button"
                                            class="record-inner-action-btn transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50 p-2 rounded-lg"
                                    >
                                        @icon('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
                                    </button>

                                    <div x-show="open"
                                         x-cloak
                                         x-ref="panel"
                                         x-transition.origin.top.right
                                         @click.outside="close($refs.button)"
                                         :id="$id('dropdown-button')"
                                         class="absolute right-0 mt-2 w-56 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-lg z-50 py-1"
                                    >
                                        @if($plan->status === 'new')
                                            <a href="{{ route('care-plan.edit', [legalEntity(), $plan->id]) }}" class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                                                @icon('edit', 'w-5 h-5 text-gray-500')
                                                {{ __('forms.edit') ?? 'Редагувати' }}
                                            </a>
                                        @endif

                                        <a href="{{ route('care-plan.show', [legalEntity(), $plan->id]) }}" class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                                            @icon('eye', 'w-5 h-5 text-gray-500')
                                            {{ __('patients.view_details') ?? 'Переглянути' }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body !grid md:!grid-cols-[56px_1fr_240px_64px] !divide-x-0 !p-0">
                            <div
                                class="record-inner-checkbox-col !w-full border-r border-gray-200 dark:border-gray-700"></div>

                            <div class="p-3.5 pl-4 overflow-hidden">
                                <!-- First Row of Details -->
                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-x-4 gap-y-3 mb-4">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">Створено</div>
                                        <div
                                            class="record-inner-value text-[14px] font-semibold break-words">{{ $plan->created_at?->format('d.m.Y') ?? '-' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">Початок</div>
                                        <div
                                            class="record-inner-value text-[14px] font-semibold break-words">{{ $plan->period_start?->format('d.m.Y') ?? '-' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">Кінець</div>
                                        <div
                                            class="record-inner-value text-[14px] font-semibold break-words">{{ $plan->period_end?->format('d.m.Y') ?? '-' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">Лікар</div>
                                        <div
                                            class="record-inner-value text-[14px] font-semibold break-words uppercase">{{ $plan->author->party->full_name }}</div>
                                    </div>
                                    <div class="min-w-0 col-span-1">
                                        <div class="record-inner-label text-[10px] uppercase">Умови надання медичної
                                            допомоги
                                        </div>
                                        <div
                                            class="record-inner-value text-[14px] font-semibold break-words">{{ $plan->care_provision_conditions }}</div>
                                    </div>
                                </div>

                                <!-- Second Row of Details -->
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-x-4 gap-y-3">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">Медичний стан/діагноз
                                        </div>
                                        <div
                                            class="record-inner-value text-[14px] font-semibold break-words">{{ $plan->medical_condition }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">Розширений опис</div>
                                        <div
                                            class="record-inner-value text-[14px] font-semibold break-words">{{ $plan->extended_description }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">Допоміжна інформація</div>
                                        <div
                                            class="record-inner-value text-[14px] font-semibold break-words">{{ $plan->additional_info }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">Нотатки</div>
                                        <div
                                            class="record-inner-value text-[14px] font-semibold break-words">{{ $plan->notes }}</div>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="p-3.5 px-4 overflow-hidden flex flex-col justify-center col-span-2 border-l border-gray-200 dark:border-gray-700">
                                <div class="space-y-4">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">ID ECO3</div>
                                        <div
                                            class="record-inner-id-value text-[13px] break-all whitespace-normal leading-normal">{{ $plan->ehealth_id }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">ID Епізоду</div>
                                        <div
                                            class="record-inner-id-value text-[13px] break-all whitespace-normal leading-normal">{{ $plan->episode_id }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <x-forms.loading/>
</x-layouts.patient>
