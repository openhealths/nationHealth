@props([
    'bind',
    'alpineModel' => null,
    'options' => [],
    'label' => null,
    'placeholder' => null,
    'live' => false,
    'id' => null,
    'showAllIfEmpty' => false,
    'initial' => null,
    'searchable' => false,
    'allowCustom' => false,
])

@php
    $elementId = $id ?? $bind;
    $fieldAttributes = ['disabled', ':disabled', 'x-bind:disabled'];
@endphp

{{--
  Avoid $wire.entangle() for arrays/objects: Livewire clones via JSON.stringify,
  which can RPC-call missing toJSON on the $wire proxy and crash the page.
--}}
<div
    {{ $attributes->except($fieldAttributes)->merge(['class' => 'relative w-full']) }}
    x-id="['choices']"
    @if ($alpineModel)
        x-modelable="selected"
        x-model="{{ $alpineModel }}"
    @endif
    :class="{ 'z-50': open }"
    x-data="{
        open: false,
        selected: [],
        options: @js($options),
        searchValue: '',
        @if ($allowCustom)
        addCustomValue() {
            const value = this.searchValue.trim();

            if (! value) {
                return;
            }

            const key = Object.keys(this.options).find(option => option.toLowerCase() === value.toLowerCase()) ?? value;
            this.options = { ...this.options, [key]: this.options[key] ?? value };

            if (! this.selected.includes(key)) {
                this.selected = [...this.selected, key];
            }

            this.searchValue = '';
        },
        @endif
        get filteredOptions() {
            const searchValue = this.searchValue.trim().toLowerCase();

            if (! searchValue) {
                return this.options;
            }

            return Object.fromEntries(
                Object.entries(this.options).filter(([value, label]) =>
                    String(value).toLowerCase().includes(searchValue) || String(label).toLowerCase().includes(searchValue)
                )
            );
        },
        get displayText() {
            const selectedArr = Array.isArray(this.selected) ? this.selected : [];
            if (selectedArr.length === 0) {
                return @if($showAllIfEmpty) Object.values(this.options).join(', ') @else '{{ $placeholder ?? __('forms.select') }}' @endif;
            }
            return selectedArr.map(val => this.options[val] ?? val).join(', ');
        }
    }"
    x-init="
        @if ($initial !== null || $alpineModel)
            selected = @js($initial ?? []);
        @else
            $nextTick(() => {
                const wireValue = $wire.get('{{ $bind }}');
                if (Array.isArray(wireValue)) {
                    selected = [...wireValue];
                }
            });
        @endif
        @if (!$alpineModel)
        $watch(() => $wire.get('{{ $bind }}'), (value) => {
            const next = Array.isArray(value) ? [...value] : [];
            if (JSON.stringify(next) !== JSON.stringify(selected)) selected = next;
        });
        $watch('selected', (val) => {
            const next = Array.isArray(val) ? [...val] : val;
            if (JSON.stringify(next) === JSON.stringify($wire.get('{{ $bind }}'))) {
                return;
            }
            $wire.set('{{ $bind }}', next, {{ $live ? 'true' : 'false' }});
        });
        @endif
    "
    x-effect="$dispatch('open-changed', { open })"
    @click.outside="open = false"
    @focusout="if (!$el.contains($event.relatedTarget)) open = false;"
    @keydown.escape="
        if (open && !$event.isComposing) {
            $event.stopPropagation();
            open = false;
            $refs.trigger.focus();
        }
    "
>
    @if ($label)
        <label for="{{ $elementId }}" class="label mb-1">{{ $label }}</label>
    @endif
    <div class="relative">
        <button
            type="button"
            id="{{ $elementId }}"
            x-ref="trigger"
            {{ $attributes->only($fieldAttributes) }}
            class="dropdown-field input peer w-full cursor-pointer truncate pl-1 text-start text-gray-900 dark:text-white"
            :aria-expanded="open"
            :aria-controls="$id('choices')"
            @click="
                $refs.trigger.focus();
                open = !open;
            "
            @keydown.arrow-down.prevent="
                open = true;
                $nextTick(() => $refs.menu.querySelector('input:not(:disabled)')?.focus());
            "
            x-text="displayText"
        ></button>
        <x-forms.dropdown-actions
            visible="selected.length > 0 || searchValue !== ''"
            clear="selected = []; searchValue = ''; open = false"
        />

        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="transform opacity-0 scale-95"
            x-transition:enter-end="transform opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="transform opacity-100 scale-100"
            x-transition:leave-end="transform opacity-0 scale-95"
            x-ref="menu"
            :id="$id('choices')"
            class="form-dropdown-menu multiselect-dropdown"
        >
            {{-- Search input for filtering options --}}
            @if ($searchable || $allowCustom)
                <div class="!bg-white px-3 pt-2 dark:!bg-gray-800">
                    {{-- sr-only deliberately hides the label visually while keeping it available to screen readers --}}
                    <label for="{{ $elementId }}-search" class="sr-only">{{ __('forms.search') }}</label>
                    <input
                        type="text"
                        id="{{ $elementId }}-search"
                        x-model="searchValue"
                        @if ($allowCustom)
                            @keydown.enter.prevent="addCustomValue()"
                        @endif
                        class="input w-full placeholder:text-gray-400 dark:placeholder:text-gray-500"
                        placeholder="{{ $allowCustom ? ($placeholder ?? __('forms.search')) : __('forms.search') }}"
                    />
                    @if ($allowCustom)
                        <button
                            type="button"
                            @click="addCustomValue()"
                            :disabled="! searchValue.trim()"
                            class="button-primary mt-2 px-4! py-2! text-xs!"
                        >
                            {{ __('forms.add_to_search') }}
                        </button>
                    @endif
                </div>
            @endif

            <ul>
                <template x-for="(optLabel, optValue) in filteredOptions" :key="optValue">
                    <li>
                        <label class="dropdown-option flex items-center gap-2">
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
            <li x-show="!Object.keys(filteredOptions).length" class="px-3 py-2 text-sm text-gray-500">
                    {{ __('forms.nothing_found') }}
                </li>
            </ul>
        </div>
    </div>
</div>
