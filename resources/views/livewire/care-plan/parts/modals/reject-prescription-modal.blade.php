@extends('components.signature-modal', ['method' => $method ?? 'signRejectPrescription'])

@section('title', 'Відхилити електронний рецепт')

@section('custom-fields')
    <div>
        <label for="statusReason" class="default-label">Причина відхилення *</label>
        <select class="input-modal" wire:model="statusReason" name="statusReason" id="statusReason">
            <option value="" selected>{{__('forms.select')}}</option>
            @foreach($this->statusReasons as $code => $description)
                <option value="{{ $code }}" wire:key="reason-{{ $code }}">
                    {{ $description }}
                </option>
            @endforeach
        </select>
        @error('statusReason') <p class="text-error">{{ $message }}</p> @enderror
    </div>
@endsection
