@props([
    'bind',
    'options' => [],
    'label' => null,
    'placeholder' => null,
    'live' => false,
    'id' => null,
    'showAllIfEmpty' => false,
    'initial' => null,
])

@php
    $elementId = $id ?? $bind;
@endphp

{{--
  Avoid $wire.entangle() for arrays/objects: Livewire clones via JSON.stringify,
  which can RPC-call missing toJSON on the $wire proxy and crash the page.
--}}
<div
    {{ $attributes->merge(['class' => 'relative w-full']) }}
    :class="{ 'z-50': open }"
    x-data="{
        open: false,
        selected: [],
        options: @js($options),
        get displayText() {
            const selectedArr = Array.isArray(this.selected) ? this.selected : [];
            if (selectedArr.length === 0) {
                return @if($showAllIfEmpty) Object.values(this.options).join(', ') @else '{{ $placeholder ?? __('forms.select') }}' @endif;
            }
            return selectedArr.map(val => this.options[val] ?? val).join(', ');
        }
    }"
    x-init="
        @if ($initial !== null)
            selected = @js($initial);
        @else
            $nextTick(() => {
                const wireValue = $wire.get('{{ $bind }}');
                if (Array.isArray(wireValue)) {
                    selected = [...wireValue];
                }
            });
        @endif
        let syncingFromWire = true;
        $nextTick(() => { syncingFromWire = false; });
        $watch('selected', (val) => {
            if (syncingFromWire) {
                return;
            }
            $wire.set('{{ $bind }}', Array.isArray(val) ? [...val] : val, {{ $live ? 'true' : 'false' }});
        });
    "
    x-effect="$dispatch('open-changed', { open })"
    @click.outside="open = false"
>
    @if ($label)
        <label for="{{ $elementId }}" class="label mb-1">{{ $label }}</label>
    @endif
    <div class="relative">
        <input
            type="text"
            id="{{ $elementId }}"
            class="input peer w-full cursor-pointer truncate pr-10 pl-1 text-gray-900 dark:text-white"
            :placeholder="'{{ $placeholder ?? __('forms.select') }}'"
            @click="open = ! open"
            :value="displayText"
            readonly
        />
        <svg
            class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-gray-500 dark:text-gray-400"
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
            class="multiselect-dropdown absolute z-30 mt-2 max-h-60 w-full overflow-y-auto rounded-md border border-gray-200 shadow-lg dark:border-gray-600"
        >
            <ul class="space-y-2 !bg-white px-3 py-2 text-sm text-gray-700 dark:!bg-gray-800 dark:text-gray-200">
                <template x-for="(optLabel, optValue) in options" :key="optValue">
                    <li>
                        <label class="flex cursor-pointer items-center space-x-2 rounded p-1 hover:bg-gray-50 dark:hover:bg-gray-600">
                            <input
                                type="checkbox"
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
