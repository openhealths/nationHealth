@use('App\Livewire\CarePlan\CarePlanIndex')

<section class="section-form">
    <x-header-navigation x-data="{ showFilter: false }" class="breadcrumb-form">
        <x-slot name="title">
            {{ __('care-plan.care_plan') }}
        </x-slot>
        <x-slot name="actions">
            <button wire:click="syncWithEHealth" class="button-success">
                @icon('refresh', 'w-4 h-4 mr-2 inline')
                {{ __('patients.sync_ehealth_data') }}
            </button>
        </x-slot>
    </x-header-navigation>

    <div class="form shift-content">
        <div class="mb-6 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100 pl-1 mt-2">
            @icon('search-outline', 'w-4.5 h-4.5')
            <p>Пошук плану лікування</p>
        </div>

        <div class="space-y-6" x-data="{ showAdditionalParams: false }">
            <div class="form-row-3">
                <div class="form-group group relative">
                    <input wire:model="searchRequisition"
                           type="text"
                           name="searchRequisition"
                           id="searchRequisition"
                           class="input peer"
                           placeholder=" "
                           autocomplete="off"
                    />
                    <label for="searchRequisition" class="label">
                        Медичний запис №*
                    </label>
                    @if($searchRequisition)
                        <button type="button" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 z-10" wire:click="$set('searchRequisition', '')">
                            @icon('close', 'w-4 h-4')
                        </button>
                    @endif
                </div>

                <div class="form-group group">
                    <select wire:model="status"
                            name="status"
                            id="status"
                            class="input-select peer w-full"
                    >
                        <option value="">Обрати</option>
                        <option value="draft">Чернетка</option>
                        <option value="completed">Завершений</option>
                        <option value="cancelled">Скасований</option>
                    </select>
                    <label for="status" class="label">
                        Статус
                    </label>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button"
                        wire:click="searchByRequisition"
                        class="button-primary px-6"
                >
                    @icon('search', 'w-4 h-4 mr-2 inline')
                    Шукати
                </button>
                <button type="button"
                        wire:click="resetFilters"
                        class="button-primary-outline-red"
                >
                    Скинути фільтри
                </button>
                <button type="button"
                        class="flex items-center gap-2 button-minor px-5 py-2.5 text-sm whitespace-nowrap"
                        @click.prevent="showAdditionalParams = !showAdditionalParams"
                >
                    @icon('adjustments', 'w-4 h-4 text-gray-500')
                    <span>{{ __('patients.additional_params') }}</span>
                </button>
            </div>

            <div x-show="showAdditionalParams" x-transition x-cloak class="mt-4">
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="startDateFrom"
                                   type="text"
                                   name="startDateFrom"
                                   id="startDateFrom"
                                   class="datepicker-input with-leading-icon input peer text-sm"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="startDateFrom" class="wrapped-label">
                                Дата початку від - до
                            </label>
                        </div>
                    </div>

                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="endDateFrom"
                                   type="text"
                                   name="endDateFrom"
                                   id="endDateFrom"
                                   class="datepicker-input with-leading-icon input peer text-sm"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="endDateFrom" class="wrapped-label">
                                Дата завершення від - до
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-row-3 mb-2">
                    <div class="form-group group relative">
                        <input wire:model="isPartOfCarePlan"
                               type="text"
                               name="isPartOfCarePlan"
                               id="isPartOfCarePlan"
                               class="input peer"
                               placeholder=" "
                               autocomplete="off"
                        />
                        <label for="isPartOfCarePlan" class="label">
                            Є частиною плана лікування
                        </label>
                        @if($isPartOfCarePlan)
                            <button type="button" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 z-10" wire:click="$set('isPartOfCarePlan', '')">
                                @icon('close', 'w-4 h-4')
                            </button>
                        @endif
                    </div>

                    <div class="form-group group relative">
                        <input wire:model="includesCarePlan"
                               type="text"
                               name="includesCarePlan"
                               id="includesCarePlan"
                               class="input peer"
                               placeholder=" "
                               autocomplete="off"
                        />
                        <label for="includesCarePlan" class="label">
                            Включає в себе план лікування
                        </label>
                        @if($includesCarePlan)
                            <button type="button" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 z-10" wire:click="$set('includesCarePlan', '')">
                                @icon('close', 'w-4 h-4')
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 space-y-4">
            @forelse($carePlans as $plan)
                @php
                    /** @var \App\Models\CarePlan $plan */
                    $status = $plan['status'] ?? $plan->status ?? '';
                    if(is_array($status)) $status = $status['coding'][0]['code'] ?? ($status['text'] ?? '');
                @endphp

                <div class="record-inner-card">
                    <div class="record-inner-header">
                        <div class="record-inner-checkbox-col">
                            <input type="checkbox" class="default-checkbox w-5 h-5">
                        </div>

                        <div class="record-inner-column flex-1">
                            <div class="record-inner-label">{{ __('care-plan.name_care_plan') }}</div>
                            <div class="record-inner-value text-[16px] font-semibold dark:text-gray-100">
                                {{ $plan['title'] ?? $plan->title ?? 'План лікування носової кровотечі' }}
                            </div>
                        </div>

                        <div class="record-inner-column-bordered w-full md:w-36 shrink-0 h-full flex flex-col justify-center gap-1">
                            <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                            <div>
                                <span class="{{ in_array(strtoupper($status), ['ACTIVE', 'active']) ? 'badge-green' : 'badge-secondary' }}">
                                    {{ $plan['status_display'] ?? $status ?? 'Активний' }}
                                </span>
                            </div>
                        </div>

                        <div class="record-inner-action-col">
                            <div class="flex justify-center relative">
                                <div x-data="{
                                         open: false,
                                         toggle() {
                                             if (this.open) {
                                                 return this.close();
                                             }
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
                                            class="record-inner-action-btn"
                                    >
                                        @icon('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
                                    </button>

                                    <div x-show="open"
                                         x-cloak
                                         x-ref="panel"
                                         x-transition.origin.top.right
                                         @click.outside="close($refs.button)"
                                         :id="$id('dropdown-button')"
                                         class="absolute right-0 mt-2 w-48 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-md z-50 py-1"
                                    >
                                        <a href="{{ route('care-plan.edit', [legalEntity(), $plan['id'] ?? $plan->id ?? 1]) }}"
                                           class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                                        >
                                            @icon('edit', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                            Редагувати
                                        </a>

                                        <a href="{{ route('care-plan.show', [legalEntity(), $plan['id'] ?? $plan->id ?? 1]) }}"
                                           class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                                        >
                                            @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                            Переглянути
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="record-inner-body">
                        <div class="record-inner-grid-container">
                            <div class="grid grid-cols-2 xl:grid-cols-6 gap-y-4 gap-x-4 w-full [&>div]:min-w-0 [&_.record-inner-value]:break-words">
                                <div>
                                    <div class="record-inner-label">Створено</div>
                                    <div class="record-inner-value">
                                        {{ \Carbon\Carbon::parse($plan['created_at'] ?? $plan->created_at ?? now())->format('d.m.Y') }}
                                    </div>
                                </div>

                                <div>
                                    <div class="record-inner-label">Початок</div>
                                    <div class="record-inner-value">
                                        @if(isset($plan['period']['start']))
                                            {{ \Carbon\Carbon::parse($plan['period']['start'])->format('d.m.Y') }}
                                        @elseif($plan->period_start ?? null)
                                            {{ $plan->period_start->format('d.m.Y') }}
                                        @endif
                                    </div>
                                </div>

                                <div>
                                    <div class="record-inner-label">Кінець</div>
                                    <div class="record-inner-value">
                                        @if(isset($plan['period']['end']))
                                            {{ \Carbon\Carbon::parse($plan['period']['end'])->format('d.m.Y') }}
                                        @elseif($plan->period_end ?? null)
                                            {{ $plan->period_end->format('d.m.Y') }}
                                        @endif
                                    </div>
                                </div>

                                <div>
                                    <div class="record-inner-label">Лікар</div>
                                    <div class="record-inner-value">
                                        {{ $plan['author']['party']['full_name'] ?? $plan->author->party->full_name ?? 'Петров І.І.' }}
                                    </div>
                                </div>

                                <div>
                                    <div class="record-inner-label">Умови надання медичної допомоги</div>
                                    <div class="record-inner-value">
                                        Амбулаторні умови
                                    </div>
                                </div>

                                <div>
                                    <div class="record-inner-label">Медичний запис №</div>
                                    <div class="record-inner-value">
                                        {{ $plan['requisition'] ?? $plan->requisition ?? '3123213211' }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-id-col">
                            <div class="min-w-0">
                                <div class="record-inner-label">ID ECO3</div>
                                <div class="record-inner-id-value">{{ $plan['uuid'] ?? $plan->uuid ?? '1231-adsadas-aqeqe-casdda' }}</div>
                            </div>
                            <div class="min-w-0">
                                <div class="record-inner-label">ID Епізоду</div>
                                <div class="record-inner-id-value">{{ $plan['episode_id'] ?? $plan->episode_id ?? '1231-adsadas-aqeqe-casdda' }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <fieldset class="fieldset pl-[3.5px] ml-0 mr-auto w-full max-w-full">
                    <legend class="legend relative -top-5 ml-0">
                        @icon('nothing-found', 'w-28 h-28')
                    </legend>

                    <div class="p-4 rounded-lg bg-blue-100 flex items-start mb-4 max-w-2xl">
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 mt-0.5">
                                @icon('alert-circle', 'w-5 h-5 text-blue-500 mr-3 mt-1')
                            </div>
                            <div class="flex-1">
                                <p class="font-bold text-blue-800">
                                    {{ __('forms.nothing_found') }}
                                </p>
                                <p class="text-sm text-blue-600">
                                    {{ __('forms.changing_search_parameters') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </fieldset>
            @endforelse
        </div>
    </div>
</section>
