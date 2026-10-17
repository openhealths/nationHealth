@php
    $action = 'store';
    $status = '';
@endphp

@extends('livewire.division.template.division')

@section('title')
        {{ __('forms.add_new_division') }}
@endsection

@section('additional-buttons')
    <div class="flex items-center gap-2 self-center">
    <button
            type="button"
            id="save_button"
            class="button-primary-outline leading-none inline-flex items-center"
            wire:click="store"
            wire:loading.attr="disabled"
            wire:target="store,create"
        >
            {{ __('forms.save') }}
        </button>

        <button
            type="button"
            id="save_button"
            class="button-primary cursor-pointer leading-none inline-flex items-center"
            wire:click="create"
            wire:loading.attr="disabled"
            wire:target="store,create"
        >
            {{ __('forms.save_and_send') }}
        </button>

    </div>
@endsection
