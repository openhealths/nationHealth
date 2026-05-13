{{--
    Blade Component: <x-forms.combobox>

    Description:
    This component renders an autocomplete input field (combobox) using Alpine.js.
    It allows selecting an option from a dropdown list of objects with customizable
    value and display keys.

    Parameters:
    - options (array, required):
        An array of objects to populate the dropdown. Each object must contain at least
        a "value" field (e.g. 'id', 'uuid') and a "label" field (e.g. 'name', 'description').
        Example:
            [
                ['uuid' => 'abc-123', 'name' => 'Legal Entity A'],
                ['uuid' => 'def-456', 'name' => 'Legfal Entity B'],
            ]

            or it may be looks as:

            [
                ['id' => 'abc-123', 'description' => 'Legal Entity A'],
                ['id' => 'def-456', 'description' => 'Legfal Entity B'],
            ]

    - bind (string, required):
        The Livewire model binding name (used for $wire.entangle). The selected value
        will be assigned to this Livewire property.
        Example: bind="selectedLegalEntityUUID"

    - bindValue (string, required):
        The key in each option object that represents the actual value to be stored
        in the Livewire model when an item is selected.
        Example: bindValue="uuid"

    - bindParam (string, required):
        The key in each option object that represents the label to display in the
        dropdown and inside the input.
        Example: bindParam="name"

    - isRequired (boolean, not required):
        This value responds for requirement combobox field if it shown
        and set no requirement if combobox has been hidden
        Example: isRequired=false (default)

    Example usage:
        <x-forms.combobox
            :options="$legalEntities"
            bind="selectedLegalEntityUUID"
            bindValue="uuid"
            bindParam="name"
        />

    Notes:
    - The input is required by default. You can remove the `required` attribute if optional.
    - If no matches are found, a non-selectable "No results" message will be shown.
    - Selecting an option sets the input text (search) to the label, and the bound Livewire
      model (via `bind`) to the actual value (`bindValue`).
--}}

@props([
    'options' => [],
    'bind' => '',
    'bindValue' => '',
    'bindParam' => '',
    'isRequired' => false
])

@php
    $hasSearchError = $errors->has($bind);
@endphp

<div {{ $attributes->merge(['class' => "form-group group"]) }}
     x-id="['input', 'listbox']"
     x-data="combobox({
         options: @js($options),
         entangled: $wire.entangle('{{ $bind }}'),
         value: '{{ $bindValue }}',
         param: '{{ $bindParam }}'
     })"
>
    <input :id="$id('input')"
           :required="{{ $isRequired }}"
           :name="{{ $isRequired ? "'$bind'" : 'null' }}"
           type="text"
           placeholder=" "
           x-model="search"
           autocomplete="off"
           aria-describedby="{{ $hasSearchError ? 'hasSearchErrorHelp' : '' }}"
           class="input {{ $hasSearchError  ? 'input-error border-red-500 focus:border-red-500' : ''}} peer"
           @focus="open=true"
           @mousedown="open=(!open ? true : false)"
           @keydown.escape.window="open = false;"
           @blur="setTimeout(() => open = false, 100)"
           x-init="if (search) $el.classList.add('not-empty')"
           x-effect="$el.classList.toggle('not-empty', !!search)"
    />

    <ul x-show="open"
        x-cloak
        :id="$id('listbox')"
        class="py-2 text-sm text-gray-700 dark:text-gray-400 absolute z-17 mt-1 w-full bg-white border border-gray-400 rounded shadow max-h-60 overflow-auto"
    >
        <template x-if="filtered.length > 0">
            <template x-for="option in filtered" :key="option.index">
                <li @mousedown.prevent="select(option.label, option.index)"
                    x-text="option.label"
                    tabindex=0
                    :id="`option-${option.index}`"
                    class="cursor-pointer px-4 py-2 hover:bg-gray-100 dark:hover:text-gray-200 dark:hover:bg-blue-800"
                ></li>
            </template>
        </template>

        <template x-if="filtered.length === 0">
            <li class="px-4 py-2 text-red-400 cursor-not-allowed">
                {{ __('Співпадінь не знайдено') }}
            </li>
        </template>
    </ul>

    @if($hasSearchError)
        <p id="hasSearchErrorHelp" class="text-error">
            {{ $errors->first($bind) }}
        </p>
    @endif

    <label :for="$id('input')" class="label z-10">
        {{ __('Медичний Заклад') }}
    </label>
</div>

@push('scripts')
    <script>
        function combobox({ options, entangled, value, param }) {
            return {
                options,
                search: '',
                value: entangled,
                valueName: value,
                param: param,
                open: false,

                get filtered() {
                    if (!this.search) {
                        return this.options.map((opt, idx) => ({ label: opt[this.param], index: idx }));
                    }

                    arr = this.options
                        .map((opt, idx) => ({ label: opt[this.param], index: idx }))
                        .filter(item => this.options[item.index].name.toLowerCase().includes(this.search.toLowerCase()));

                    if (arr.length === 0) {
                        this.value = '';
                    }

                    return arr;
                },

                select(value, index) {
                    this.search = value;
                    this.value = this.options.find((option, idx) => idx === index && option[this.param] === value)[this.valueName];
                    this.open = false;
                }
            }
        }
    </script>
@endpush
