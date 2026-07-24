<x-dialog-modal wire:model="showSignatureModal">
    <x-slot name="title">
        {{ __('care-plan.complete_care_plan') ?? 'Завершити план лікування' }}
    </x-slot>

    <x-slot name="content">
        <div class="space-y-4">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Ви впевнені, що хочете завершити цей план лікування? Для завершення всі його призначення повинні мати фінальний статус (завершені або скасовані).
            </p>
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
        </div>
    </x-slot>

    <x-slot name="footer">
        <x-secondary-button wire:click="$set('showSignatureModal', false)" wire:loading.attr="disabled">
            {{ __('forms.cancel') }}
        </x-secondary-button>

        <x-button class="ml-3 button-primary" wire:click="completePlan" wire:loading.attr="disabled">
            {{ __('forms.save') }}
        </x-button>
    </x-slot>
</x-dialog-modal>
