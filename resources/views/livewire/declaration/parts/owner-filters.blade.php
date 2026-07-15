@php
    use App\Enums\Declaration\ReorganizedStatus;
@endphp

<div>
    {{-- First row of filters --}}
    <div class="form-row-3">
        <x-forms.multiselect
            bind="statusFilter"
            :options="$dictionaries['DECLARATION_STATUSES']"
            label="{{ __('declarations.show') }}"
            placeholder="{{ __('Оберіть статус декларацій') }}"
            @open-changed="openType = $event.detail.open"
        />

        {{-- Search by declaration number --}}
        <div class="form-group group">
            <input
                type="text"
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

    {{-- Second row of filters --}}
    <div class="form-row-3 !z-[10]" :class="openType ? 'mt-20' : 'mt-6'">
        {{-- Filter by doctor --}}
        <x-forms.multiselect
            bind="doctorFilter"
            :options="$this->doctors->pluck('fullName', 'uuid')->toArray()"
            label="{{ __('employees.doctor') }}"
            placeholder="{{ __('employees.doctor_full_name') }}"
        />

        {{-- Filter by reorganization --}}
        @if(legalEntity()->legators->isNotEmpty())
            <x-forms.multiselect
                bind="reorganizationFilter"
                :options="[ ReorganizedStatus::RESIGNED->value => ReorganizedStatus::RESIGNED->label() ]"
                label="{{ __('declarations.for_reorganized') }}"
                placeholder="{{ __('Оберіть тип декларацій') }}"
            />
        @endif
    </div>
</div>
