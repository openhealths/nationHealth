@use('App\Enums\Composition\CompositionType')
@use('App\Enums\Composition\CompositionStatus')

@forelse ($focusSearchResults as $result)
    <div class="record-inner-card" wire:key="composition-focus-{{ data_get($result, 'identifier.value') }}">
        <div class="record-inner-body p-4 space-y-2">
            <div class="font-semibold">{{ data_get($result, 'title') ?: data_get($result, 'identifier.value') }}</div>
            <div>{{ CompositionType::tryFrom(data_get($result, 'type.coding.0.code', ''))?->label() ?? data_get($result, 'type.coding.0.code') }}</div>
            <div>{{ CompositionStatus::fromEHealth(data_get($result, 'status'))->label() }}</div>
            <div>{{ __('compositions.columns.subject') }}: {{ data_get($result, 'subject.value') }}</div>
            <div>{{ __('compositions.columns.focus') }}: {{ data_get($result, 'section.focus.value') }}</div>
            <div>{{ __('compositions.columns.category') }}: {{ data_get($result, 'category.coding.0.code') }}</div>
            <div>{{ __('compositions.columns.date') }}: {{ data_get($result, 'date') ?: '-' }}</div>
            <button type="button" class="button-minor" wire:click="viewFocusResult('{{ data_get($result, 'identifier.value') }}')">
                {{ __('forms.view') }}
            </button>
        </div>
    </div>
@empty
    <x-nothing-found />
@endforelse
