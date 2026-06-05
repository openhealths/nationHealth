{{-- Medication Search Drawer Overlay (below header z-60) --}}
<div x-show="showMedicationSearchDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     x-cloak
     @click="showMedicationSearchDrawer = false"
     aria-controls="medication-search-drawer-right"
     class="fixed top-0 right-0 h-screen pt-20 w-4/5 bg-gray-900/50"
     style="z-index: 44;"
></div>

{{-- Medication Search Drawer (30px gap on the LEFT) --}}
<div id="medication-search-drawer-right"
     x-show="showMedicationSearchDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="translate-x-full"
     x-cloak
     class="fixed top-0 right-0 h-screen pt-20 p-4 overflow-y-auto bg-white dark:bg-gray-800 shadow-2xl"
     style="z-index: 45; width: calc(80% - 30px);"
     tabindex="-1"
     aria-labelledby="medication-search-drawer-label"
     x-data="{ showFilter: false }"
>
    <h3 class="modal-header" id="medication-search-drawer-label">
        {{ __('care-plan.new_medication_prescription') }}
    </h3>

    {{-- Search Input --}}
    <div class="mb-4">
        <div class="relative">
            <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                @icon('search-outline', 'w-5 h-5 text-gray-500')
            </div>
            <input type="text"
                   class="input peer ps-10 w-full"
                   placeholder="{{ __('care-plan.medication_search_placeholder') }}"
                   wire:model.live.debounce.400ms="searchQuery"
                   wire:keydown.enter="searchMedications"
            />
        </div>
    </div>

    {{-- Action Buttons --}}
    <div class="flex flex-wrap gap-2 mb-6">
        <button type="button" wire:click="searchMedications" class="button-primary flex items-center gap-2">
            @icon('search', 'w-4 h-4')
            <span>{{ __('forms.search') }}</span>
        </button>
        <button type="button" wire:click="$set('searchQuery', '')" class="button-primary-outline-red">
            {{ __('forms.reset_all_filters') }}
        </button>
        <button type="button"
                class="button-minor flex items-center gap-2"
                @click="showFilter = !showFilter"
        >
            @icon('adjustments', 'w-4 h-4')
            <span>{{ __('forms.additional_search_parameters') }}</span>
        </button>
    </div>

    {{-- Filters --}}
    <div x-show="showFilter" x-cloak x-transition class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="form-group group">
            <label class="label">
                {{ __('care-plan.inn_name') }}
            </label>
            <select class="input-select peer w-full">
                <option selected value="">{{ __('care-plan.medication_search_placeholder') }}</option>
            </select>
        </div>
        <div class="form-group group">
            <label class="label">
                {{ __('care-plan.atc_code') }}
            </label>
            <select class="input-select peer w-full">
                <option selected value="">{{ __('care-plan.code') }}</option>
            </select>
        </div>
        <div class="form-group group">
            <label class="label">
                {{ __('care-plan.dosage_form') }}
            </label>
            <select class="input-select peer w-full">
                <option selected value="">{{ __('care-plan.tablets') }}</option>
            </select>
        </div>
        <div class="form-group group">
            <label class="label">
                {{ __('care-plan.prescription_form_type') }}
            </label>
            <select class="input-select peer w-full">
                <option selected value="">{{ __('care-plan.type') }}</option>
            </select>
        </div>
    </div>

    {{-- Results --}}
    <div class="space-y-4 mb-6">
        @forelse($searchResults as $drug)
            <fieldset class="fieldset">
                <legend class="legend">
                    {{ $drug['name'] ?? 'Лікарський засіб' }}
                </legend>

                <div class="space-y-1 text-sm text-gray-700 dark:text-gray-300 mb-4">
                    <p><span class="text-gray-500">{{ __('care-plan.inn_basic') }}:</span> {{ $drug['innm_name'] ?? 'МНН відсутнє' }}</p>
                    <p><span class="text-gray-500">{{ __('care-plan.dosage_form') }}:</span> {{ $drug['dosage_form'] ?? 'Форма випуску відсутня' }}</p>
                    <p><span class="text-gray-500">Код АТХ:</span> {{ $drug['medication_code_atc'] ?? '-' }}</p>
                    <p><span class="text-gray-500">Одиниця виміру:</span> {{ $drug['package_unit'] ?? '-' }}</p>
                </div>

                <button type="button" wire:click="selectProduct({{ json_encode($drug) }}, 'medication_request')" class="button-primary">
                    Обрати для призначення
                </button>
            </fieldset>
        @empty
            <div class="text-center py-8 text-gray-400 italic">
                @if(empty($searchQuery))
                    Введіть запит для пошуку лікарських засобів
                @else
                    Нічого не знайдено за запитом "{{ $searchQuery }}"
                @endif
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        <button type="button"
                class="button-minor"
                aria-controls="medication-search-drawer-right"
                @click="showMedicationSearchDrawer = false"
        >
            {{ __('forms.cancel') }}
        </button>
    </div>
</div>
