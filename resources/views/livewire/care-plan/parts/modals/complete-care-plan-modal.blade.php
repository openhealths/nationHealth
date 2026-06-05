@extends('components.signature-modal', ['method' => 'sign'])

@section('title', __('care-plan.complete_care_plan') ?? 'Завершити план лікування')

@section('custom-fields')
    <div>
        <label for="statusReason" class="default-label">{{ __('care-plan.status_reason') }} *</label>
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
