@php
    $dictionaries = $dictionaries ?? [];
    $resolvedKind = $activity->resolvedKind();
    $kindTranslationKey = 'care-plan.activity_kind.' . $resolvedKind;
    $translatedKind = \Illuminate\Support\Facades\Lang::has($kindTranslationKey) ? __($kindTranslationKey) : $resolvedKind;

    $activityStatus = is_array($activity->status) ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? '')) : $activity->status;
    $statusKey = 'care-plan.status.' . strtolower((string) $activityStatus);
    $activityStatusDisplay = \Illuminate\Support\Facades\Lang::has($statusKey)
        ? __($statusKey)
        : (is_array($activity->status) ? ($activity->status['text'] ?? ($activity->status['coding'][0]['display'] ?? $activityStatus)) : $activityStatus);

    $quantityValue = is_array($activity->quantity) ? ($activity->quantity['value'] ?? null) : $activity->quantity;
    $quantityUnitCode = $activity->quantityCode ?: (is_array($activity->quantity) ? ($activity->quantity['code'] ?? null) : null);
    $quantityUnitLabel = $quantityUnitCode
        ? ($dictionaries['device_unit'][$quantityUnitCode]
            ?? $dictionaries['MEDICATION_UNIT'][$quantityUnitCode]
            ?? $quantityUnitCode)
        : '';

    $remainingValue = $activity->remainingQuantity;
    $remainingUnitCode = $activity->remainingQuantityCode;
    $remainingUnitLabel = $remainingUnitCode
        ? ($dictionaries['device_unit'][$remainingUnitCode]
            ?? $dictionaries['MEDICATION_UNIT'][$remainingUnitCode]
            ?? $remainingUnitCode)
        : $quantityUnitLabel;

    $dailyAmountValue = $activity->dailyAmount;
    $dailyAmountUnit = $activity->dailyAmountCode
        ? ($dictionaries['MEDICATION_UNIT'][$activity->dailyAmountCode] ?? $activity->dailyAmountCode)
        : '';

    $programLabel = $activity->program
        ? ($dictionaries['medical_programs'][$activity->program]
            ?? $dictionaries['medical_programs_device'][$activity->program]
            ?? $dictionaries['medical_programs_medication'][$activity->program]
            ?? $activity->program)
        : null;

    $productLabel = $activityProductLabel ?? null;
    if ($productLabel === null && !empty($activity->productReference)) {
        $productLabel = $activity->productReference;
    }
    if ($productLabel === null && !empty($activity->productCodeableConcept)) {
        $productLabel = $dictionaries['device_definition_classification_type'][$activity->productCodeableConcept]
            ?? $activity->productCodeableConcept;
    }

    $authorName = $activity->author?->party?->fullName
        ?? $activity->author?->fullName
        ?? null;

    $reasonCodeLabel = $activity->reasonCode
        ? ($dictionaries['eHealth/ICD10_AM/condition_codes'][$activity->reasonCode]
            ?? $dictionaries['eHealth/ICPC2/condition_codes'][$activity->reasonCode]
            ?? $activity->reasonCode)
        : null;

    $reasonReferences = collect($activity->reasonReference ?? [])
        ->map(fn ($ref) => is_string($ref) ? $ref : ($ref['uuid'] ?? json_encode($ref)))
        ->filter()
        ->values()
        ->all();

    $goals = collect($activity->goal ?? [])
        ->map(fn ($goal) => is_string($goal) ? $goal : ($goal['code'] ?? ($goal['text'] ?? json_encode($goal))))
        ->filter()
        ->values()
        ->all();

    $statusReason = is_array($activity->statusReason)
        ? ($activity->statusReason['text'] ?? ($activity->statusReason['coding'][0]['code'] ?? null))
        : $activity->statusReason;

    $outcomeCodeable = $activity->outcomeCodeableConcept;
    $outcomeReference = $activity->outcomeReference;
@endphp

{{-- TV 3.10.3.2.4 activity detail display checklist (T052):
     author, product, reason_code, reason_reference, goal, quantity(+unit),
     remaining_quantity(+unit), scheduled_period; medication extras: daily_amount,
     description, program, status, status_reason; + outcome_* when present (3.10.3.2.5). --}}
<div class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">{{ $translatedKind ?: '-' }}</h2>
            <p class="mt-1 text-sm text-gray-500">
                @if ($activity->uuid)
                    ID:
                    <span class="font-mono">{{ $activity->uuid }}</span>
                @else
                    ID:
                    <span class="font-mono">{{ $activity->id }} (Чернетка)</span>
                @endif
            </p>
        </div>
        <span class="badge {{ in_array(strtoupper((string) $activityStatus), ['NEW', 'DRAFT']) ? 'badge-yellow' : 'badge-green' }}">
            {{ $activityStatusDisplay }}
        </span>
    </div>

    <div class="grid grid-cols-1 gap-6 text-sm md:grid-cols-2 lg:grid-cols-3">
        <div>
            <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                {{ __('care-plan.author') }}
            </div>
            <div class="text-gray-900 dark:text-white">{{ $authorName ?: '—' }}</div>
        </div>

        <div>
            <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                {{ __('care-plan.status_label') }}
            </div>
            <div class="text-gray-900 dark:text-white">{{ $activityStatusDisplay ?: '—' }}</div>
        </div>

        @if ($productLabel)
            <div class="md:col-span-2 lg:col-span-3">
                <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                    {{ __('care-plan.assignment') }}
                </div>
                <div class="text-gray-900 dark:text-white">{{ $productLabel }}</div>
                @if (!empty($activity->productReference) && $productLabel !== $activity->productReference)
                    <div class="mt-1 font-mono text-xs text-gray-400">{{ $activity->productReference }}</div>
                @endif
            </div>
        @endif

        <div>
            <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                {{ __('care-plan.grounds_for_prescription') }}
            </div>
            <div class="text-gray-900 dark:text-white">{{ $reasonCodeLabel ?: '—' }}</div>
        </div>

        <div class="md:col-span-2">
            <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                {{ __('care-plan.justification_of_grounds') }}
            </div>
            <div class="text-gray-900 dark:text-white">
                @if ($reasonReferences !== [])
                    <ul class="list-inside list-disc space-y-0.5">
                        @foreach ($reasonReferences as $ref)
                            <li class="font-mono text-xs">{{ $ref }}</li>
                        @endforeach
                    </ul>
                @else
                    —
                @endif
            </div>
        </div>

        <div class="md:col-span-2 lg:col-span-3">
            <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                {{ __('care-plan.expected_result') }}
            </div>
            <div class="text-gray-900 dark:text-white">
                @if ($goals !== [])
                    {{ implode(', ', $goals) }}
                @else
                    —
                @endif
            </div>
        </div>

        @if ($programLabel)
            <div>
                <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                    {{ __('care-plan.program') }}
                </div>
                <div class="text-gray-900 dark:text-white">{{ $programLabel }}</div>
            </div>
        @endif

        <div>
            <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                {{ __('care-plan.quantity') }}
            </div>
            <div class="text-gray-900 dark:text-white">
                {{ $quantityValue ?? '—' }}
                @if ($quantityUnitLabel)
                    {{ $quantityUnitLabel }}
                @endif
            </div>
        </div>

        <div>
            <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                {{ __('care-plan.remaining_quantity') }}
            </div>
            <div class="text-gray-900 dark:text-white">
                {{ $remainingValue ?? '—' }}
                @if ($remainingValue !== null && $remainingUnitLabel)
                    {{ $remainingUnitLabel }}
                @endif
            </div>
        </div>

        @if (str_contains($resolvedKind, 'medication'))
            <div>
                <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                    {{ __('care-plan.daily_amount') }}
                </div>
                <div class="text-gray-900 dark:text-white">
                    {{ $dailyAmountValue ?? '—' }}
                    @if ($dailyAmountValue !== null && $dailyAmountUnit)
                        {{ $dailyAmountUnit }}
                    @endif
                </div>
            </div>
        @endif

        <div>
            <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                {{ __('forms.start_date') }}
            </div>
            <div class="text-gray-900 dark:text-white">
                {{ $activity->scheduledPeriodStart?->format('d.m.Y') ?: '—' }}
            </div>
        </div>
        <div>
            <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                {{ __('forms.end_date') }}
            </div>
            <div class="text-gray-900 dark:text-white">
                {{ $activity->scheduledPeriodEnd?->format('d.m.Y') ?: '—' }}
            </div>
        </div>

        @if ($statusReason)
            <div class="md:col-span-2 lg:col-span-3">
                <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                    {{ __('care-plan.status_reason') }}
                </div>
                <div class="text-gray-900 dark:text-white">{{ $statusReason }}</div>
            </div>
        @endif

        @if ($outcomeCodeable || $outcomeReference)
            <div>
                <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                    {{ __('care-plan.outcome_dictionary') }}
                </div>
                <div class="text-gray-900 dark:text-white">{{ $outcomeCodeable ?: '—' }}</div>
            </div>
            <div class="md:col-span-2">
                <div class="mb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                    {{ __('care-plan.activity_outcomes') }}
                </div>
                <div class="font-mono text-xs text-gray-900 dark:text-white">{{ $outcomeReference ?: '—' }}</div>
            </div>
        @endif
    </div>

    @if ($activity->description)
        <div class="mt-6">
            <div class="mb-2 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                {{ __('care-plan.description') }}
            </div>
            <div class="text-sm whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $activity->description }}</div>
        </div>
    @endif
</div>
