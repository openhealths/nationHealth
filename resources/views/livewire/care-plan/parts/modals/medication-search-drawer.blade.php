<x-dialog-drawer id="medication-search-drawer-right" x-model="showMedicationSearchDrawer" :stopClickPropagation="true" maxWidth="4/5" topClass="top-[57px]" zIndex="44" overlayWidth="100%" onCloseClick="showMedicationSearchDrawer = false">
    <x-slot name="title">
        <span class="block"><span class="care-plan-service-context block">{{ $carePlan->person?->full_name }} — {{ __('care-plan.care_plan') }} №{{ $carePlan->requisition ?? $carePlan->id }}</span>{{ __('care-plan.new_medication_wizard') }}</span>
    </x-slot>
    <div class="care-plan-service-form care-plan-medication-search"
        x-data="{
            showFilter: true,

            search(page = 1) { $wire.set('searchPage', page, false); $wire.searchMedications() },

            resetFilters() { $wire.set('searchQuery', '', false); this.search() }
        }">
        <div class="mb-5 flex items-center gap-2 text-sm">@icon('search', 'w-4 h-4') {{ __('care-plan.medication_search') }}</div>
        <div class="form-group group max-w-sm">
            <input type="search" id="medication-search-query" class="input peer" wire:model="searchQuery" @keydown.enter.prevent="search()" placeholder=" " />
            <label for="medication-search-query" class="label">{{ __('care-plan.medication_search_placeholder') }}</label>
        </div>
        <div class="my-7 flex flex-wrap gap-3">
            <button type="button" @click="search()" class="button-primary flex items-center gap-2">@icon('search', 'w-4 h-4') {{ __('forms.search') }}</button>
            <button type="button" @click="resetFilters()" class="button-primary-outline-red">{{ __('forms.reset_all_filters') }}</button>
            <button type="button" class="button-minor flex items-center gap-2" @click="showFilter = !showFilter" :aria-expanded="showFilter">@icon('adjustments', 'w-4 h-4') {{ __('forms.additional_search_parameters') }}</button>
        </div>
        <div class="form-row-2 mb-12" x-show="showFilter" x-cloak>
            <div class="form-group group"><input id="medication-innm" type="text" class="input peer" disabled aria-describedby="medication-filter-hint" placeholder=" " /><label for="medication-innm" class="label">{{ __('care-plan.inn_name') }}</label></div>
            <div class="form-group group">
                <select id="medication-atc" class="input-select peer" disabled aria-describedby="medication-filter-hint"><option value="">{{ __('care-plan.code') }}</option>@foreach (($dictionaries['MEDICATION_ATC'] ?? []) as $code => $label)<option value="{{ $code }}">{{ $code }}</option>@endforeach</select>
                <label for="medication-atc" class="label">{{ __('care-plan.atc_code') }}</label>
            </div>
            <div class="form-group group">
                <select id="medication-dosage-form" class="input-select peer" disabled aria-describedby="medication-filter-hint"><option value="">{{ __('forms.select') }}</option>@foreach (($dictionaries['MEDICATION_FORM'] ?? []) as $code => $label)<option value="{{ $code }}">{{ $dictionaries['MEDICATION_FORM'][$code] ?? $code }}</option>@endforeach</select>
                <label for="medication-dosage-form" class="label">{{ __('care-plan.dosage_form') }}</label>
            </div>
            <div class="form-group group">
                <select id="medication-blank-type" class="input-select peer" disabled aria-describedby="medication-filter-hint"><option value="">{{ __('forms.select') }}</option>@foreach (($dictionaries['MR_BLANK_TYPES'] ?? []) as $code => $label)<option value="{{ $code }}">{{ $dictionaries['MR_BLANK_TYPES'][$code] ?? $code }}</option>@endforeach</select>
                <label for="medication-blank-type" class="label">{{ __('care-plan.prescription_form_type') }}</label>
            </div>
        </div>
        <p id="medication-filter-hint" class="mb-8 text-sm text-gray-500" x-show="showFilter">{{ __('care-plan.catalog_filters_unavailable') }}</p>
        @forelse ($searchResults as $index => $drug)
            @php
                $ingredient = collect($drug['ingredients'] ?? [])->firstWhere('is_primary', true) ?? ($drug['ingredients'][0] ?? []);
                $unitCode = $ingredient['dosage']['denumerator_unit'] ?? '';
                $unitNames = ['ML' => 'мл', 'MG' => 'мг', 'G' => 'г', 'PIECE' => 'шт'];
                $unitName = $dictionaries['MEDICATION_UNIT'][$unitCode] ?? $unitNames[$unitCode] ?? $unitCode;
                $numeratorUnit = $ingredient['dosage']['numerator_unit'] ?? '';
                $numeratorUnitName = $dictionaries['MEDICATION_UNIT'][$numeratorUnit] ?? $unitNames[$numeratorUnit] ?? $numeratorUnit;
                $packs = collect($drug['packages'] ?? [])->pluck('package_qty')->filter()->map(fn ($qty) => '№'.$qty)->implode(', ');
                $formCode = $drug['form'] ?? $drug['dosage_form'] ?? '';
            @endphp
            <fieldset class="care-plan-medication-result" wire:key="medication-result-{{ $drug['id'] ?? $index }}"
                data-inn="{{ $drug['innm_name'] ?? $ingredient['name'] ?? '' }}" data-atc="{{ $drug['medication_code_atc'] ?? '' }}"
                data-form="{{ $formCode }}" data-blank="{{ $drug['mr_blank_type'] ?? '' }}" >
                <legend>{{ $drug['name'] ?? __('care-plan.medication') }}</legend>
                <dl class="care-plan-medication-facts">
                    <div><dt>{{ __('care-plan.inn_basic') }}:</dt><dd>{{ $ingredient['name'] ?? $drug['innm_name'] ?? '-' }} {{ $ingredient['dosage']['numerator_value'] ?? '-' }} {{ $numeratorUnitName }}/{{ $ingredient['dosage']['denumerator_value'] ?? '-' }} {{ $unitName }}</dd></div>
                    <div><dt>{{ __('care-plan.medication_dosage_form') }}:</dt><dd>{{ $drug['dosage_form_label'] ?? $dictionaries['MEDICATION_FORM'][$formCode] ?? ($formCode ?: '-') }}</dd></div>
                    <div><dt>{{ __('care-plan.release_form') }}:</dt><dd>{{ $drug['release_form'] ?? __('forms.no_data') }}</dd></div>
                    <div><dt>{{ __('care-plan.package_quantity') }}:</dt><dd>{{ $packs ?: '-' }}</dd></div>
                    <div><dt>{{ __('care-plan.dosed_medication') }}:</dt><dd>{{ is_bool($drug['dosage_form_is_dosed'] ?? null) ? __($drug['dosage_form_is_dosed'] ? 'forms.yes' : 'forms.no') : __('forms.no_data') }}</dd></div>
                    <div><dt>{{ __('care-plan.maintenance_daily_dose') }}:</dt><dd>{{ $drug['daily_dosage'] ?? '-' }}</dd></div>
                    <div><dt>{{ __('care-plan.maximum_daily_dose') }}:</dt><dd>{{ $drug['display_max_daily_dosage'] ?? '-' }}</dd></div>
                    <div><dt>{{ __('care-plan.prescription_form_type') }}:</dt><dd>{{ $drug['mr_blank_type'] ?? '-' }}</dd></div>
                </dl>
                <button type="button" class="button-primary mt-8" @click="$wire.selectProduct($wire.searchResults[@js($index)], 'medication_request')" wire:loading.attr="disabled">{{ __('forms.add') }}</button>
            </fieldset>
        @empty
            <p class="mb-10 text-sm text-gray-500">{{ __('forms.nothing_found') }}</p>
        @endforelse
        <div class="mb-8 text-center text-xs text-gray-500">
            {{ $searchPage }}
            <div class="mt-3 flex justify-center gap-2">
                <button type="button" class="button-minor" @click="search({{ $searchPage - 1 }})" @disabled($searchPage <= 1)>{{ __('care-plan.previous_page') }}</button>
                <button type="button" class="button-minor" @click="search({{ $searchPage + 1 }})" @disabled(count($searchResults) < 15)>{{ __('care-plan.next_page') }}</button>
            </div>
        </div>
        <button type="button" class="button-minor mb-8" @click="showMedicationSearchDrawer = false">{{ __('forms.cancel') }}</button>
    </div>
</x-dialog-drawer>
