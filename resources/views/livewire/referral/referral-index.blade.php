<div x-data="referralIndex($wire)" @if ($pendingCompletions !== []) wire:poll.5s="checkPendingCompletions" @endif>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <x-forms.loading />

    <x-header-navigation class="items-start">
        <x-slot name="title">{{ __('referrals.title') }}</x-slot>

        <x-slot name="actions">
            @can('service_request:read')
                <button
                    type="button"
                    wire:click="search"
                    wire:loading.attr="disabled"
                    wire:target="search"
                    @click="
                        patient = '';
                        status = [];
                    "
                    class="button-sync gap-2 whitespace-nowrap disabled:cursor-default disabled:opacity-50"
                    :disabled="! requisition.trim()"
                >
                    @icon('refresh', 'w-4 h-4')
                    <span>{{ __('referrals.refresh_search_results') }}</span>
                </button>
            @endcan
        </x-slot>

        <x-slot name="navigation">
            <form
                wire:submit="search"
                @submit="
                    patient = '';
                    status = [];
                "
                class="-my-4"
            >
                <div class="mb-6 flex items-center gap-2 font-semibold text-gray-900 dark:text-white">
                    @icon('search-outline', 'w-4.5 h-4.5')
                    <span>{{ __('referrals.search') }}</span>
                </div>

                <p id="requisition-hint" class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('referrals.search_hint') }}
                </p>

                <div class="form-row-3">
                    <div class="form-group group">
                        <input
                            type="text"
                            id="requisition"
                            placeholder=" "
                            class="input peer pr-8"
                            x-model="requisition"
                            @input="
                                requisition = maskRequisition($event.target.value);
                                $event.target.value = requisition;
                            "
                            autocomplete="off"
                            aria-describedby="requisition-hint"
                            maxlength="19"
                            required
                        />
                        <label for="requisition" class="label">{{ __('referrals.requisition') }}</label>
                        <button
                            type="button"
                            @click="requisition = ''"
                            class="absolute top-3 right-0 text-gray-400 hover:text-gray-700 dark:hover:text-white"
                            aria-label="{{ __('referrals.clear_requisition') }}"
                        >
                            @icon('close', 'w-4 h-4')
                        </button>
                        @error('requisition')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mt-6 mb-9 flex flex-col gap-2 sm:flex-row">
                    @can('service_request:read')
                        <button
                            type="submit"
                            class="button-primary gap-2"
                            wire:loading.attr="disabled"
                            wire:target="search"
                        >
                            @icon('search', 'w-4 h-4')
                            <span>{{ __('forms.search') }}</span>
                        </button>
                    @endcan
                    <button type="button" @click="resetFilters()" class="button-primary-outline-red">
                        {{ __('referrals.btn_reset') }}
                    </button>
                </div>

                <div class="mt-6" x-show="$wire.searchResults.length > 0" x-cloak>
                    <p class="mb-4 font-semibold text-gray-900 dark:text-white">{{ __('referrals.result_filters') }}</p>
                    <div
                        class="form-row-3"
                        x-data="{ statusOpen: false }"
                        @open-changed.stop="statusOpen = $event.detail.open"
                        :class="{ 'pb-60': statusOpen }"
                    >
                        <div class="form-group group">
                            <input
                                type="text"
                                id="patient"
                                placeholder=" "
                                class="input peer pr-8"
                                x-model="patient"
                                @keydown.enter.prevent
                                autocomplete="off"
                                aria-describedby="patient-hint"
                            />
                            <label for="patient" class="label"> {{ __('referrals.patient_filter') }} </label>
                            <button
                                type="button"
                                @click="patient = ''"
                                class="absolute top-3 right-0 text-gray-400 hover:text-gray-700 dark:hover:text-white"
                                aria-label="{{ __('referrals.clear_patient') }}"
                            >
                                @icon('close', 'w-4 h-4')
                            </button>
                        </div>

                        <div class="form-group group">
                            <x-forms.multiselect
                                bind="status"
                                alpine-model="status"
                                :initial="[]"
                                :options="[
                                    'active' => __('referrals.statuses.active'),
                                    'completed' => __('referrals.statuses.completed'),
                                    'entered_in_error' => __('referrals.statuses.entered_in_error'),
                                    'revoked' => __('referrals.statuses.revoked'),
                                    'in_progress' => __('referrals.statuses.in_progress'),
                                ]"
                                :label="__('referrals.show')"
                                :placeholder="__('referrals.registry.all')"
                            />
                        </div>
                    </div>
                    <p id="patient-hint" class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('referrals.patient_filter_hint') }}
                    </p>
                </div>
            </form>
        </x-slot>
    </x-header-navigation>

    <div class="shift-content mt-8 min-w-0 px-3.5">
        <div class="max-w-screen-xl">
            @if (!empty($searchResults))
                @include('livewire.referral.parts.referrals-table', ['referrals' => $searchResults])
                <div x-show="visibleCount === 0">
                    <x-nothing-found :description="__('referrals.no_filtered_results')" />
                </div>
            @elseif ($hasSearched)
                <x-nothing-found
                    :title="__('referrals.messages.not_found')"
                    :description="__('referrals.no_search_results')"
                />
            @else
                <x-nothing-found :title="__('referrals.search_prompt')" :description="__('referrals.search_hint')" />
            @endif
        </div>
    </div>

    <!-- Cancel Usage Modal -->
    @if ($showCancelModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50" aria-modal="true" role="dialog">
            <div class="relative w-full max-w-lg rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                <button
                    type="button"
                    wire:click="$set('showCancelModal', false)"
                    class="absolute top-3 right-2.5 ml-auto inline-flex h-8 w-8 items-center justify-center rounded-lg bg-transparent text-sm text-gray-400 hover:bg-gray-200 hover:text-gray-900 dark:hover:bg-gray-600 dark:hover:text-white"
                >
                    <svg class="h-3 w-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" />
                    </svg>
                    <span class="sr-only">Закрити</span>
                </button>
                <div class="mt-2">
                    <h3 class="mb-3 text-lg font-bold text-gray-900 dark:text-white">
                        {{ __('referrals.modals.cancel_usage.title') }}
                    </h3>
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('referrals.modals.cancel_usage.description') }}
                    </p>
                    <div class="mb-5">
                        <label for="cancelLetter" class="mb-2 block text-sm font-medium text-gray-900 dark:text-white">
                            {{ __('referrals.modals.cancel_usage.letter_label') }} <span class="text-red-500">*</span>
                        </label>
                        <textarea
                            id="cancelLetter"
                            wire:model.live.debounce.300ms="cancelExplanatoryLetter"
                            rows="4"
                            class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400"
                            placeholder="{{ __('referrals.modals.cancel_usage.letter_placeholder') }}"
                        ></textarea>
                        @error('cancelExplanatoryLetter')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="flex justify-end gap-3">
                        <button
                            wire:click="confirmCancelUsage"
                            type="button"
                            class="rounded-lg bg-red-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-red-700 focus:ring-4 focus:ring-red-300 focus:outline-none"
                            {{ empty(trim($cancelExplanatoryLetter ?? '')) ? 'disabled' : '' }}
                        >
                            {{ __('referrals.modals.cancel_usage.confirm') }}
                        </button>
                        <button
                            wire:click="$set('showCancelModal', false)"
                            type="button"
                            class="rounded-lg border border-gray-200 bg-white px-5 py-2.5 text-sm font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:ring-4 focus:ring-gray-200 focus:outline-none"
                        >
                            Скасувати
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($showUseModal)
        <div class="modal" role="dialog" aria-modal="true">
            <div class="modal-wrapper">
                <form class="modal-content w-full max-w-lg p-6" wire:submit="confirmUse">
                    <h3 class="modal-header">{{ __('referrals.use_options') }}</h3>
                    <label for="referralExecutor" class="default-label">{{ __('referrals.executor') }}</label>
                    <select id="referralExecutor" class="input-select w-full" wire:model="selectedExecutorId">
                        <option value="0">{{ __('forms.select') }}</option>
                        @foreach ($this->executorChoices as $employee)
                            <option value="{{ $employee['id'] }}">{{ $employee['label'] }}</option>
                        @endforeach
                    </select>
                    @error('selectedExecutorId')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                    <label for="referralProgram" class="default-label">{{ __('care-plan.program') }}</label>
                    <select id="referralProgram" class="input-select w-full" wire:model="selectedProgramId">
                        <option value="">{{ __('forms.select') }}</option>
                        @foreach ($this->programChoices as $program)
                            <option value="{{ $program['id'] }}">{{ $program['name'] }}</option>
                        @endforeach
                    </select>
                    @error('selectedProgramId')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                    <div class="mt-4 flex gap-3">
                        <button type="submit" class="button-primary">{{ __('forms.confirm') }}</button>
                        <button type="button" class="button-secondary" wire:click="$set('showUseModal', false)">
                            {{ __('forms.cancel') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Complete Referral Modal -->
    @if ($showCompleteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50" aria-modal="true" role="dialog">
            <div class="relative w-full max-w-lg rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                <button
                    type="button"
                    wire:click="$set('showCompleteModal', false)"
                    class="absolute top-3 right-2.5 ml-auto inline-flex h-8 w-8 items-center justify-center rounded-lg bg-transparent text-sm text-gray-400 hover:bg-gray-200 hover:text-gray-900 dark:hover:bg-gray-600 dark:hover:text-white"
                >
                    <svg class="h-3 w-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" />
                    </svg>
                    <span class="sr-only">Закрити</span>
                </button>
                <div class="mt-2 text-center">
                    <h3 class="mb-5 text-lg font-bold text-gray-900 dark:text-white">
                        {{ __('care-plan.referral_complete_title') }}
                    </h3>
                    <div class="mb-4 text-left">
                        <label for="emzType" class="mb-2 block text-sm font-medium text-gray-900 dark:text-white">
                            {{ __('care-plan.referral_complete_emz_type') }} *
                        </label>
                        <select
                            id="emzType"
                            wire:model.live="selectedEmzType"
                            class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        >
                            <option value="encounter">{{ __('care-plan.emz_type.encounter') }}</option>
                            <option value="procedure">{{ __('care-plan.emz_type.procedure') }}</option>
                            <option value="diagnostic_report">{{ __('care-plan.emz_type.diagnostic_report') }}</option>
                        </select>
                    </div>
                    <div class="mb-5 text-left">
                        <label for="emzUuid" class="mb-2 block text-sm font-medium text-gray-900 dark:text-white">
                            {{ __('care-plan.referral_complete_emz_select') }} *
                        </label>
                        @if (empty($availableEmzResources))
                            <p class="text-sm text-amber-700 dark:text-amber-300">
                                {{ __('care-plan.referral_complete_emz_empty') }}
                            </p>
                        @else
                            <select
                                id="emzUuid"
                                wire:model.change="selectedEmzUuid"
                                class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            >
                                <option value="">Оберіть запис…</option>
                                @foreach ($availableEmzResources as $resource)
                                    <option value="{{ $resource['uuid'] }}">{{ $resource['label'] }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    @if ($remainingQuantity > 0)
                        <div class="show-alert-warning mb-4 text-left">
                            <p>
                                {{ __('referrals.messages.remaining_quantity_warning', ['quantity' => $remainingQuantity]) }}
                            </p>
                            <label class="mt-2 flex gap-2">
                                <input type="checkbox" wire:model="acknowledgeRemainingQuantity" />
                                <span>{{ __('referrals.messages.remaining_quantity_confirm') }}</span>
                            </label>
                        </div>
                    @endif
                    <div class="flex justify-center gap-3">
                        <button
                            wire:click="confirmComplete"
                            type="button"
                            class="inline-flex items-center rounded-lg bg-blue-600 px-5 py-2.5 text-center text-sm font-medium text-white hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 focus:outline-none disabled:opacity-50 dark:focus:ring-blue-800"
                            @disabled(empty($selectedEmzUuid))
                        >
                            Підтвердити погашення
                        </button>
                        <button
                            wire:click="$set('showCompleteModal', false)"
                            type="button"
                            class="rounded-lg border border-gray-200 bg-white px-5 py-2.5 text-sm font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:z-10 focus:ring-4 focus:ring-gray-200 focus:outline-none dark:border-gray-500 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 dark:hover:text-white dark:focus:ring-gray-600"
                        >
                            Скасувати
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
