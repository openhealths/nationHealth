@php
    $dictionaries = $dictionaries ?? [];
    $resolvedKind = $activity->resolvedKind();
    $kindTranslationKey = 'care-plan.activity_kind.' . $resolvedKind;
    $translatedKind = \Illuminate\Support\Facades\Lang::has($kindTranslationKey) ? __($kindTranslationKey) : $resolvedKind;

    $activityStatus = is_array($activity->status) ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? '')) : $activity->status;
    $statusKey = 'care-plan.status.' . strtolower($activityStatus);
    $activityStatusDisplay = \Illuminate\Support\Facades\Lang::has($statusKey)
        ? __($statusKey)
        : (is_array($activity->status) ? ($activity->status['text'] ?? ($activity->status['coding'][0]['display'] ?? $activityStatus)) : $activityStatus);

    $quantityValue = is_array($activity->quantity) ? ($activity->quantity['value'] ?? null) : $activity->quantity;
    $quantityUnitCode = $activity->quantity_code ?: (is_array($activity->quantity) ? ($activity->quantity['code'] ?? null) : null);
    $quantityUnitLabel = $quantityUnitCode
        ? ($dictionaries['device_unit'][$quantityUnitCode] ?? $quantityUnitCode)
        : '';

    $programLabel = $activity->program
        ? ($dictionaries['medical_programs'][$activity->program]
            ?? $dictionaries['medical_programs_device'][$activity->program]
            ?? $dictionaries['medical_programs_medication'][$activity->program]
            ?? $activity->program)
        : null;

    $productLabel = $activityProductLabel ?? null;
    if ($productLabel === null && !empty($activity->product_reference)) {
        $productLabel = $activity->product_reference;
    }
    if ($productLabel === null && !empty($activity->product_codeable_concept)) {
        $productLabel = $dictionaries['device_definition_classification_type'][$activity->product_codeable_concept]
            ?? $activity->product_codeable_concept;
    }
@endphp

<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">{{ $translatedKind ?: '-' }}</h2>
            <p class="text-sm text-gray-500 mt-1">
                @if($activity->uuid)
                    ID: <span class="font-mono">{{ $activity->uuid }}</span>
                @else
                    ID: <span class="font-mono">{{ $activity->id }} (Чернетка)</span>
                @endif
            </p>
        </div>
        <span class="badge {{ in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']) ? 'badge-yellow' : 'badge-green' }}">
            {{ $activityStatusDisplay }}
        </span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 text-sm">
        @if($productLabel)
            <div class="md:col-span-2 lg:col-span-3">
                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">{{ __('care-plan.medical_device') }}</div>
                <div class="text-gray-900 dark:text-white">{{ $productLabel }}</div>
                @if(!empty($activity->product_reference) && $productLabel !== $activity->product_reference)
                    <div class="text-xs text-gray-400 font-mono mt-1">{{ $activity->product_reference }}</div>
                @endif
            </div>
        @endif

        @if($programLabel)
            <div>
                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">{{ __('care-plan.program') }}</div>
                <div class="text-gray-900 dark:text-white">{{ $programLabel }}</div>
            </div>
        @endif

        <div>
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">{{ __('care-plan.quantity') }}</div>
            <div class="text-gray-900 dark:text-white">
                {{ $quantityValue ?? '-' }}
                @if($quantityUnitLabel)
                    {{ $quantityUnitLabel }}
                @endif
            </div>
        </div>
        <div>
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">{{ __('forms.start_date') }}</div>
            <div class="text-gray-900 dark:text-white">{{ $activity->scheduled_period_start?->format('d.m.Y') ?: '-' }}</div>
        </div>
        <div>
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">{{ __('forms.end_date') }}</div>
            <div class="text-gray-900 dark:text-white">{{ $activity->scheduled_period_end?->format('d.m.Y') ?: '-' }}</div>
        </div>
    </div>

    @if($activity->description)
        <div class="mt-6">
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Опис</div>
            <div class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line">{{ $activity->description }}</div>
        </div>
    @endif
</div>
