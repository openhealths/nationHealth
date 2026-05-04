@use('App\Enums\Status')

@php
    $action = 'show';
    // use livewire state for faster UI updates; normalize to string
    $statusRaw = $divisionForm->division['status'] ?? null;
    $statusStr = is_string($statusRaw) ? $statusRaw : ($statusRaw?->value ?? null);

    $divisionId = $divisionForm->division['id'] ?? null;

    // Model may be null if not preloaded; use for policy checks only
    $division = $divisionId ? \App\Models\Division::find($divisionId) : null;
    $divisionType = dictionary()->basics()->byName('DIVISION_TYPE')->where('code', $divisionForm->division['type'])->value('description');
    $uuid = $divisionForm->division['uuid'];
@endphp

@extends('livewire.division.template.division')

@section('title')
    {{ $divisionType }} "{{ $divisionForm->division["name"] }}"
@endsection

@section('additional-buttons')
    <div wire:key="division-actions-{{ $divisionForm->division['id'] }}-{{ $statusStr ?? 'unknown' }}" class="flex items-center gap-2">

        @can('update', $division)
            <a role="button" class="default-button cursor-pointer inline-flex items-center leading-none" href="{{ route('division.edit', [legalEntity(), $divisionForm->division['id']]) }}">
                {{ __('forms.edit') }}
            </a>
        @endcan


        @can('activate', $division)
            <button
                x-on:click.prevent="
                    divisionId={{ $divisionId }};
                    textConfirmation=@js(__('divisions.modals.activate.confirmation_text'));
                    actionType='activate';
                    actionTitle=@js(__('divisions.modals.activate.title'));
                    actionButtonText=@js(__('forms.activate'));
                "
                class="alternative-button cursor-pointer inline-flex items-center leading-none"
            >
                {{ __('forms.activate') }}
            </button>
        @endcan

        @can('deactivate', $division)
            <button
                x-on:click.prevent="
                    divisionId={{ $divisionId }};
                    textConfirmation=@js(__('divisions.modals.deactivate.confirmation_text'));
                    actionType='deactivate';
                    actionTitle=@js(__('divisions.modals.deactivate.title'));
                    actionButtonText=@js(__('forms.deactivate'));
                "
                class="alternative-button cursor-pointer inline-flex items-center leading-none"
            >
                {{ __('forms.deactivate') }}
            </button>
        @endcan

        @can('delete', $division)
            <button
                x-on:click.prevent="
                    divisionId={{ $divisionId }};
                    textConfirmation=@js(__('divisions.modals.delete.confirmation_text'));
                    actionType='delete';
                    actionTitle=@js(__('divisions.modals.delete.title'));
                    actionButtonText=@js(__('forms.delete'));
                "
                class="alternative-button cursor-pointer inline-flex items-center leading-none"
            >
                {{ __('forms.delete') }}
            </button>
        @endcan
    </div>
@endsection
