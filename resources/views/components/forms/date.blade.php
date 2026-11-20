{{--
    Date input with the datepicker and its calendar icon.
    The page variant has a floating label after the input, the modal variant a label above it.
    Attributes (id, name, wire:model or x-model, datepicker-max-date, required) go to the input.
    Alpine bindings are passed as x-bind:required.
    The label is a string or a slot, an input without one has no label.
--}}
@props([
    'label' => null,
    'error' => null,
    'modal' => false
])

@if ($modal && $label !== null)
    <label for="{{ $attributes->get('id') }}" class="label-modal">
        {{ $label }}
    </label>
@endif

<div class="datepicker-wrapper">
    {{-- The placeholder stays out of the attribute bag, which trims the space the floating label relies on --}}
    <input
        placeholder="{{ $attributes->get('placeholder', ' ') }}"
        {{
            $attributes->except('placeholder')->merge(['type' => 'text', 'autocomplete' => 'off'])->class([
            'datepicker-input',
            'input-modal pl-10!' => $modal,
            'with-leading-icon input peer' => !$modal,
            'input-error' => $error !== null && $errors->has($error)
            ])
        }}
    />

    @if (!$modal && $label !== null)
        <label for="{{ $attributes->get('id') }}" class="wrapped-label">
            {{ $label }}
        </label>
    @endif
</div>

@if ($error !== null)
    @error($error)
        <p class="text-error mt-1 text-xs">{{ $message }}</p>
    @enderror
@endif
