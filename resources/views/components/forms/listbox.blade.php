@props([
    'options' => [],
    'bind',
    'label',
    'isDisabled' => 'false',
    'isRequired' => false,
    'placeholder' => null
])

<div
    {{ $attributes->merge(['class' => 'form-group group min-w-0']) }}
    x-data="{ selectedValue: $wire.entangle('{{ $bind }}'), labels: @js($options) }"
>
    <div
        x-listbox
        class="relative"
        x-model="selectedValue"
        :disabled="{{ $isDisabled }}"
        :class="{ 'z-50': $listbox.isOpen }"
    >
        <button
            x-listbox:button
            type="button"
            :disabled="{{ $isDisabled }}"
            @class([
                'input-select peer relative h-auto min-h-10.5 text-left whitespace-normal! overflow-visible! wrap-break-word disabled:cursor-default',
                'input-error' => $errors->has($bind)
            ])
            aria-invalid="{{ $errors->has($bind) ? 'true' : 'false' }}"
            @if ($errors->has($bind)) aria-describedby="{{ $bind }}-error" @endif
        >
            <span x-text="labels[selectedValue] || @js($placeholder ?? __('forms.select'))"></span>
            <span aria-hidden="true" class="pointer-events-none absolute right-3 top-1/2 h-2 w-2 -translate-y-1/2 rotate-45 border-b-2 border-r-2 border-gray-500 dark:border-gray-400"></span>
        </button>

        <label x-listbox:label class="label">
            {{ $label }}@if ($isRequired)<span aria-hidden="true"> *</span>@endif
        </label>

        <ul
            x-listbox:options
            x-cloak
            aria-required="{{ $isRequired ? 'true' : 'false' }}"
            class="dropdown-panel max-h-80 w-full overflow-y-auto py-1"
        >
            @foreach (['' => $placeholder ?? __('forms.select')] + $options as $value => $text)
                <li
                    x-listbox:option
                    :value="@js((string) $value)"
                    :class="{ 'bg-gray-100 dark:bg-gray-600': $listboxOption.isActive, 'font-medium': $listboxOption.isSelected }"
                    class="cursor-pointer px-3 py-2 text-sm whitespace-normal wrap-break-word"
                >
                    {{ $text }}
                </li>
            @endforeach
        </ul>
    </div>

    @error($bind)
        <p id="{{ $bind }}-error" class="text-error">{{ $message }}</p>
    @enderror
</div>
