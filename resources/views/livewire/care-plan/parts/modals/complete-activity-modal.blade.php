@extends('components.signature-modal', ['method' => 'sign'])

@section('title', __('care-plan.complete_activity') ?? 'Завершити призначення')

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

    <div>
        <label for="outcomeCode" class="default-label">{{ __('care-plan.outcome_dictionary') }} *</label>
        <select class="input-modal" wire:model="outcomeCode" name="outcomeCode" id="outcomeCode">
            <option value="" selected>{{ __('forms.select') }}</option>
            @foreach($this->dictionaries['care_plan_activity_outcomes'] ?? [] as $code => $description)
                <option value="{{ $code }}" wire:key="outcome-{{ $code }}">
                    {{ $description }}
                </option>
            @endforeach
        </select>
        @error('outcomeCode') <p class="text-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="default-label">{{ __('care-plan.referral_outcome') }}</label>
        <div class="flex gap-2">
            <div class="flex-1">
                <select class="input-modal" wire:model="selectedOutcomeReference" id="selectedOutcomeReference">
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @if(!empty($this->availableConditions))
                        <optgroup label="Діагнози/Стани">
                            @foreach($this->availableConditions as $item)
                                <option value="{{ $item['uuid'] }}" wire:key="ref-condition-{{ $item['uuid'] }}">
                                    {{ $item['name'] }} ({{ $item['date'] }})
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if(!empty($this->availableObservations))
                        <optgroup label="Спостереження">
                            @foreach($this->availableObservations as $item)
                                <option value="{{ $item['uuid'] }}" wire:key="ref-observation-{{ $item['uuid'] }}">
                                    {{ $item['name'] }} ({{ $item['date'] }})
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if(!empty($this->availableReports))
                        <optgroup label="Діагностичні звіти">
                            @foreach($this->availableReports as $item)
                                <option value="{{ $item['uuid'] }}" wire:key="ref-report-{{ $item['uuid'] }}">
                                    {{ $item['name'] }} ({{ $item['date'] }})
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
            </div>
            <button type="button" class="button-primary shrink-0" wire:click="addOutcomeReference">
                Додати
            </button>
        </div>
        
        @if(!empty($this->outcomeReferences))
            <div class="mt-4 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Тип</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Назва</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Дата</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
                        @foreach($this->selectedOutcomeReferencesDetails as $ref)
                            <tr wire:key="selected-ref-{{ $ref['uuid'] }}">
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $ref['type'] }}</td>
                                <td class="px-4 py-2 text-sm text-gray-800 dark:text-gray-200">{{ $ref['name'] }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $ref['date'] }}</td>
                                <td class="px-4 py-2 text-right">
                                    <button type="button" class="text-red-500 hover:text-red-700" wire:click="removeOutcomeReference('{{ $ref['uuid'] }}')">
                                        @icon('delete', 'w-4 h-4')
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
