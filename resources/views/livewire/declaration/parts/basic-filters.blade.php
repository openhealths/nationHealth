@php
    use App\Enums\Declaration\ReorganizedStatus;

    $typeOptions = [
        'request' => __('declarations.requests'),
        'declaration' => __('forms.declarations'),
    ];
    $reorganizationOptions = [
        ReorganizedStatus::TO_BE_RESIGNED->value => ReorganizedStatus::TO_BE_RESIGNED->label(),
        ReorganizedStatus::RESIGNED->value => ReorganizedStatus::RESIGNED->label(),
    ];
@endphp

<div class="form-row-3">
    {{-- Filter by type --}}
    <x-forms.multiselect
        bind="typeFilter"
        :options="$typeOptions"
        label="{{ __('declarations.show') }}"
        placeholder="{{ __('Оберіть вид декларацій') }}"
        @open-changed="openType = $event.detail.open"
    />

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

{{-- Second row of filters --}}
<div class="form-row-3 !z-[10]" :class="openType ? 'mt-20' : 'mt-6'">
    {{-- Filter by declaration status --}}
    <x-forms.multiselect
        bind="statusFilter"
        :options="$dictionaries['DECLARATION_STATUSES']"
        label="{{ __('declarations.show') }}"
        placeholder="{{ __('Оберіть статус декларацій') }}"
    />

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
            :options="$reorganizationOptions"
            label="{{ __('declarations.for_reorganized') }}"
            placeholder="{{ __('Оберіть тип декларацій') }}"
        />
    @endif
</div>
