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

<div {{ $attributes->merge(['class' => 'relative w-full']) }}
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
     x-effect="
         $dispatch('open-changed', { open });
         const group = $el.closest('.form-group');
         if (group) {
             group.style.zIndex = open ? '50' : '';
         }
     "
>
    @if($label)
        <label for="{{ $elementId }}" class="label mb-1">{{ $label }}</label>
    @endif
    <div class="relative">
        <input type="text"
               id="{{ $elementId }}"
               class="input peer w-full cursor-pointer text-gray-900 dark:text-white pl-1 truncate pr-10"
               :placeholder="'{{ $placeholder ?? __('forms.select') }}'"
               @click="open = !open"
               @click.outside="open = false"
               :value="displayText"
               readonly
        />
        <svg class="w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400 pointer-events-none"
             fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
        </svg>

        <div x-show="open"
             x-cloak
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="transform scale-95"
             x-transition:enter-end="transform scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="transform scale-100"
             x-transition:leave-end="transform scale-95"
             class="multiselect-dropdown absolute z-50 mt-2 w-full rounded-md border border-gray-200 !bg-white shadow-lg max-h-60 overflow-y-auto dark:border-gray-600 dark:!bg-gray-800"
        >
            <ul class="space-y-2 !bg-white px-3 py-2 text-sm text-gray-700 dark:!bg-gray-800 dark:text-gray-200">
                <template x-for="(optLabel, optValue) in options" :key="optValue">
                    <li>
                        <label class="flex cursor-pointer items-center space-x-2 rounded p-1 hover:bg-gray-50 dark:hover:bg-gray-600">
                            <input type="checkbox"
                                   :value="optValue"
                                   x-model="selected"
                                   class="rounded-sm border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:checked:border-transparent dark:checked:bg-blue-600"
                            />
                            <span x-text="optLabel"></span>
                        </label>
                    </li>
                </template>
            </ul>
        </div>
    </div>
</div>
