@php
    $action = 'update';
    $division = \App\Models\Division::find($divisionForm->division['id']);
    $divisionType = dictionary()->basics()->byName('DIVISION_TYPE')->where('code', $divisionForm->division['type'])->value('description');
    $status = $divisionForm->division['status'];
@endphp

@extends('livewire.division.template.division')

@section('title')
        {{ __('forms.edit_division') }}
@endsection

@section('description')
    {{ $divisionType }} "{{ $divisionForm->division["name"] }}"
@endsection

@section('additional-buttons')
    <div class="flex items-center gap-2">
        @can('delete', $division)
            <button
                type="button"
                x-on:click.prevent="
                    divisionId={{ $division->id }};
                    textConfirmation=@js(__('divisions.modals.delete.confirmation_text'));
                    actionType='delete';
                    actionTitle=@js(__('divisions.modals.delete.title'));
                    actionButtonText=@js(__('forms.delete'));
                "
                class="button-primary-outline-red cursor-pointer inline-flex items-center leading-none"
            >
                {{ __('forms.delete') }}
            </button>
        @endcan

        <button
            type="button"
            id="save_button"
            class="button-outline-primary cursor-pointer inline-flex items-center gap-2 leading-none"
            wire:click="store"
        >
            @icon('archive', 'w-4 h-4')
            {{ __('forms.save') }}
        </button>

        <button
            type="button"
            id="save_and_send_button"
            class="button-primary cursor-pointer inline-flex items-center leading-none"
            wire:click="update"
        >
            {{ __('forms.save_and_send') }}
        </button>
    </div>
@endsection

