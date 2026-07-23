@props([
    'bind',
    'options' => [],
    'label' => null,
    'placeholder' => null,
    'live' => false,
    'id' => null,
    'showAllIfEmpty' => false,
])

@php
    $elementId = $id ?? $bind;
@endphp

<div
    {{ $attributes->merge(['class' => 'relative w-full']) }}
    x-data="{
        open: false,
        selected: @if($live) $wire.entangle('{{ $bind }}').live @else $wire.entangle('{{ $bind }}') @endif,
        options: @js($options),
        get displayText() {
            const selectedArr = Array.isArray(this.selected) ? this.selected : [];
            if (selectedArr.length === 0) {
                return @if($showAllIfEmpty) Object.values(this.options).join(', ') @else '{{ $placeholder ?? __('forms.select') }}' @endif;
            }
            return selectedArr.map(val => this.options[val] ?? val).join(', ');
        }
    }"
    x-init="if (!Array.isArray(selected)) selected = []"
    x-effect="$dispatch('open-changed', { open })"
    @click.outside="open = false"
>
    @if($label)
        <label for="{{ $elementId }}" class="label mb-1">
            {{ $label }}
        </label>
    @endif
    <div class="relative">
        <input
            type="text"
            id="{{ $elementId }}"
            class="input peer w-full cursor-pointer text-gray-900 dark:text-white pl-1 truncate pr-10"
            :placeholder="'{{ $placeholder ?? __('forms.select') }}'"
            @click="open = !open"
            :value="displayText"
            readonly
        />
        <svg
            class="w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400 pointer-events-none"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            viewBox="0 0 24 24"
        >
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
        </svg>

        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="transform opacity-0 scale-95"
            x-transition:enter-end="transform opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="transform opacity-100 scale-100"
            x-transition:leave-end="transform opacity-0 scale-95"
            class="absolute z-30 mt-2 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg max-h-60 overflow-y-auto"
        >
            <ul class="py-2 px-3 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                <template x-for="(optLabel, optValue) in options" :key="optValue">
                    <li>
                        <label class="flex items-center space-x-2 cursor-pointer p-1 rounded hover:bg-gray-50 dark:hover:bg-gray-600">
                            <input
                                type="checkbox"
                                :value="optValue"
                                x-model="selected"
                                class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                            />
                            <span x-text="optLabel"></span>
                        </label>
                    </li>
                </template>
            </ul>
        </div>
    </div>
</div>

