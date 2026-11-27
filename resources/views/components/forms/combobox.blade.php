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
        dropdown and inside the input. The list is also filtered on this key.
        Example: bindParam="name"

    - model (string, optional):
        Alpine expression of an object to bind into instead of a Livewire property.
        Use this inside an Alpine x-for when there is no Livewire property to entangle.
        When provided, `bind` is ignored and the selected value is written to model[modelKey].
        Example: model="usedReference"

    - modelKey (string, optional):
        The key on the `model` object that receives the selected value. Pairs with `model`.
        Example: modelKey="id"

    - label (string, not required):
        The floating label rendered for the input.
        Example: label="Episode"

    - limit (int, not required):
        Maximum number of options rendered in the dropdown at once (default 50).
        Filtering still runs over the full options list; only the displayed slice is capped.
        Example: limit="50"

    - isRequired (boolean, not required):
        This value responds for requirement combobox field if it shown
        and set no requirement if combobox has been hidden
        Example: isRequired=false (default)

    - allowCustom (boolean, optional):
        Allow adding a typed identifier without a local option using Enter or the add button.
        Local choices remain searchable by their label and identifier.

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
    'model' => null,
    'modelKey' => '',
    'bindValue' => '',
    'bindParam' => '',
    'label' => '',
    'limit' => 50,
    'isRequired' => false,
    'allowCustom' => false
])

@php
    $hasSearchError = $errors->has($bind);
    $fieldAttributes = ['disabled', ':disabled', 'x-bind:disabled', 'readonly', ':readonly', 'x-bind:readonly'];
@endphp

<div
    {{ $attributes->except($fieldAttributes)->merge(['class' => 'form-group group']) }}
    :class="{ '!z-30': open }"
    x-id="['input', 'listbox', 'error']"
    x-data="combobox({
        options: @js($options),
        entangled: @if($bind !== '')$wire.entangle('{{ $bind }}')@else null @endif,
        model: @if($model !== null && $model !== '') {{ $model }} @else null @endif,
        modelKey: '{{ $modelKey }}',
        value: '{{ $bindValue }}',
        param: '{{ $bindParam }}',
        limit: {{ (int) $limit }},
        allowCustom: @js((bool) $allowCustom)
    })"
    @reset-comboboxes.window="reset()"
    @click.outside="close()"
    @focusout="if (!$el.contains($event.relatedTarget)) close();"
>
    <div class="relative">
        <input
            :id="$id('input')"
            x-ref="trigger"
            {{ $attributes->only($fieldAttributes) }}
            :required="{{ $isRequired ?: 'false' }}"
            :name="{{ $isRequired ? "'$bind'" : 'null' }}"
            type="text"
            placeholder=" "
            x-model="search"
            autocomplete="off"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="open"
            :aria-controls="$id('listbox')"
            :aria-activedescendant="open && active >= 0 ? $id('listbox') + '-' + active : null"
            @if($hasSearchError) :aria-describedby="$id('error')" aria-invalid="true" @endif
            class="dropdown-field input peer {{ $hasSearchError  ? 'input-error' : ''}}"
            @focus="open = !$el.readOnly"
            @click="open = !$el.readOnly"
            @input="
                typing = true;
                value = '';
                active = -1;
                open = true;
            "
            @keydown.arrow-down.prevent="move(1)"
            @keydown.arrow-up.prevent="move(-1)"
            @keydown.enter="
                if (open && active >= 0 && !$event.isComposing) {
                    $event.preventDefault();
                    select(filtered[active].label, filtered[active].index);
                } else if (allowCustom && !$event.isComposing) {
                    $event.preventDefault();
                    addCustomValue();
                }
            "
            @keydown.escape="
                if (open && !$event.isComposing) {
                    $event.stopPropagation();
                    close();
                }
            "
            x-effect="$el.classList.toggle('not-empty', !!search)"
        />
        <x-forms.dropdown-actions visible="search !== '' || (value != null && value !== '')" clear="reset()" />
        <label :for="$id('input')" class="label z-10">
            {{ $label }}
        </label>
        {{-- Keep Alpine's x-for scope intact when a parent Livewire component morphs. --}}
        <ul x-show="open" x-cloak wire:ignore role="listbox" :id="$id('listbox')" class="form-dropdown-menu">
            @if ($allowCustom)
                <li role="presentation" class="px-3 py-2">
                    <button
                        type="button"
                        @mousedown.prevent
                        @click="addCustomValue()"
                        :disabled="!search.trim() || $refs.trigger.readOnly || $refs.trigger.matches(':disabled')"
                        class="button-primary px-4! py-2! text-xs!"
                    >
                        {{ __('forms.add_to_search') }}
                    </button>
                </li>
            @endif

            <template x-for="(option, position) in filtered" :key="option.index">
                <li
                    role="option"
                    :id="$id('listbox') + '-' + position"
                    :aria-selected="value === options[option.index][valueName]"
                    :data-active="active === position"
                    @mousedown.prevent
                    @click="select(option.label, option.index)"
                    x-text="option.label"
                    class="dropdown-option"
                ></li>
            </template>
            <li x-show="!filtered.length" class="px-3 py-2 text-sm text-gray-500">{{ __('forms.nothing_found') }}</li>
        </ul>
    </div>

    @if($hasSearchError)
        <p :id="$id('error')" class="text-error">{{ $errors->first($bind) }}</p>
    @endif
</div>

@pushOnce('scripts')
    <script>
        function combobox({ options, entangled, model, modelKey, value, param, limit, allowCustom = false }) {
            const usesModel = model !== null && model !== undefined;

            return {
                options,
                search: '',
                usesModel: usesModel,
                model: model,
                modelKey: modelKey,
                value: usesModel ? model[modelKey] : entangled,
                valueName: value,
                param: param,
                limit: limit,
                allowCustom: allowCustom,
                open: false,
                typing: false,
                active: -1,

                init() {
                    if (this.value !== null && this.value !== undefined && this.value !== '') {
                        const selected = this.options.find((option) => option[this.valueName] === this.value);

                        if (selected) {
                            this.search = selected[this.param];
                        } else if (this.allowCustom) {
                            this.search = this.value;
                        }
                    }

                    if (this.usesModel) {
                        this.$watch(() => this.model[this.modelKey], (value) => {
                            if (value !== this.value) {
                                this.typing = false;
                                this.value = value;
                            }
                        });
                    }

                    this.$watch('value', (newValue) => {
                        if (this.usesModel) {
                            this.model[this.modelKey] = newValue ?? '';
                        }

                        if (!this.typing) {
                            const selected = this.options.find((option) => option[this.valueName] === newValue);
                            this.search = selected?.[this.param] ?? (this.allowCustom ? String(newValue ?? '') : '');
                        }
                    });
                },

                get filtered() {
                    if (!this.typing || !this.search) {
                        return this.options.slice(0, this.limit).map((option, index) => ({ label: option[this.param], index }));
                    }

                    const matches = this.options
                        .map((option, index) => ({ label: option[this.param], index }))
                        .filter((item) => {
                            const option = this.options[item.index];
                            const search = this.allowCustom ? this.search.trim().toLowerCase() : this.search.toLowerCase();

                            return String(option[this.param] ?? '').toLowerCase().includes(search)
                                || (this.allowCustom && String(option[this.valueName] ?? '').toLowerCase().includes(search));
                        });

                    return matches.slice(0, this.limit);
                },

                move(step) {
                    if (this.$refs.trigger.readOnly || this.$refs.trigger.matches(':disabled')) return;
                    this.open = true;
                    const count = this.filtered.length;
                    if (!count) return;
                    this.active = this.active < 0 ? (step > 0 ? 0 : count - 1) : (this.active + step + count) % count;
                    this.$nextTick(() => document.getElementById(this.$id('listbox') + '-' + this.active)?.scrollIntoView({ block: 'nearest' }));
                },

                select(label, index) {
                    if (this.$refs.trigger.readOnly || this.$refs.trigger.matches(':disabled')) return;
                    this.search = label;
                    this.value = this.options.find((option, currentIndex) => currentIndex === index && option[this.param] === label)[this.valueName];
                    this.open = false;
                    this.typing = false;
                },

                addCustomValue() {
                    const value = this.search.trim();

                    if (!this.allowCustom || !value || this.$refs.trigger.readOnly || this.$refs.trigger.matches(':disabled')) {
                        return;
                    }

                    const selected = this.options.find((option) =>
                        String(option[this.valueName] ?? '').toLowerCase() === value.toLowerCase()
                    );

                    this.value = selected ? selected[this.valueName] : value;
                    this.search = selected ? selected[this.param] : value;
                    this.open = false;
                    this.typing = false;
                },

                close() {
                    this.open = false;
                    this.typing = false;
                    this.active = -1;
                },

                reset() {
                    this.search = '';
                    this.value = '';
                    this.active = -1;
                    this.open = false;
                    this.typing = false;
                }
            }
        }
    </script>
@endPushOnce
