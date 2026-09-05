@extends('components.signature-modal', ['method' => 'sign'])

@section('title', __('care-plan.cancel_care_plan') ?? 'Скасувати план лікування')

@section('custom-fields')
    <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-600 dark:bg-amber-950/40 dark:text-amber-100">
        {{ __('care-plan.cancel_care_plan_irreversible_warning') }}
    </div>

    <div>
        <label for="statusReason" class="default-label">{{ __('care-plan.status_reason') }} *</label>
        <select class="input-modal" wire:model="statusReason" name="statusReason" id="statusReason">
            <option value="" selected>{{ __('forms.select') }}</option>
            @foreach ($this->statusReasons as $code => $description)
                <option value="{{ $code }}" wire:key="reason-{{ $code }}">{{ $description }}</option>
            @endforeach
        </select>
        @error('statusReason')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>
@endsection
