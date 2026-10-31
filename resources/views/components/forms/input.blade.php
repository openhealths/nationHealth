@props(['disabled' => false, 'autocomplete' => 'off-all'])

@if ($attributes->get('type') === 'password')
    <x-password-field>
        <input autocomplete="{{ $autocomplete }}" {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'default-input']) !!}>
    </x-password-field>
@else
    <input autocomplete="{{ $autocomplete }}" {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'default-input']) !!}>
@endif
