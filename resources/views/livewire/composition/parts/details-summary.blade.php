{{--
    Read-only summary of a conclusion as returned by getComposition.

    Shared by the creation wizard and the registry detail modal so that what the author
    checks before signing is exactly what is shown afterwards.

    @var array|null $detail
--}}
@php
    $detail ??= [];
    $status = \App\Enums\Person\CompositionStatus::fromEHealth(data_get($detail, 'status'));
    $type = \App\Enums\Person\CompositionType::tryFrom((string) data_get($detail, 'type.coding.0.code'));
    $category = \App\Enums\Person\CompositionCategory::tryFrom((string) data_get($detail, 'category.coding.0.code'));

    $extensions = collect(data_get($detail, 'extension', []))
        ->filter(static fn ($extension) => is_array($extension) && isset($extension['valueCode']))
        ->mapWithKeys(static fn (array $extension) => [
            $extension['valueCode'] => $extension['valueUuid']
                ?? $extension['valueDate']
                ?? $extension['valueString']
                ?? $extension['valueBoolean']
                ?? null,
        ]);

    $asDate = static fn (?string $value) => $value
        ? \Carbon\CarbonImmutable::parse($value)->format(config('app.date_format'))
        : '-';

    $rows = [
        __('compositions.detail.number') => data_get($detail, 'title') ?: '-',
        __('compositions.detail.type') => $type?->label() ?? '-',
        __('compositions.detail.category') => $category?->label() ?? '-',
        __('compositions.detail.date') => $asDate(data_get($detail, 'date')),
        __('compositions.detail.period_start') => $asDate(data_get($detail, 'event.0.period.start')),
        __('compositions.detail.period_end') => $asDate(data_get($detail, 'event.0.period.end')),
    ];
@endphp

<div class="record-inner-card">
    <div class="record-inner-header">
        <div class="record-inner-column flex-1">
            <div class="record-inner-label">{{ __('compositions.detail.title') }}</div>
            <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                {{ data_get($detail, 'title') ?: '-' }}
            </div>
        </div>

        @if ($status)
            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                <div class="record-inner-label">{{ __('compositions.detail.status') }}</div>
                <div><span @class([$status->color()])>{{ $status->label() }}</span></div>
            </div>
        @endif
    </div>

    <div class="record-inner-body">
        <div class="record-inner-grid-container">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-3">
                @foreach ($rows as $label => $value)
                    <div class="min-w-0">
                        <div class="record-inner-label text-[10px] uppercase">{{ $label }}</div>
                        <div class="record-inner-value text-[14px] font-semibold break-words">{{ $value }}</div>
                    </div>
                @endforeach

                @foreach (['IS_ACCIDENT', 'IS_INTOXICATED', 'IS_FOREIGN_TREATMENT', 'IS_FORCE_RENEW'] as $flag)
                    @if ($extensions->get($flag))
                        <div class="min-w-0">
                            <div class="record-inner-label text-[10px] uppercase">
                                {{ __('compositions.detail.flags.' . strtolower($flag)) }}
                            </div>
                            <div class="record-inner-value text-[14px] font-semibold">{{ __('forms.yes') }}</div>
                        </div>
                    @endif
                @endforeach

                @if ($extensions->has('TREATMENT_VIOLATION'))
                    <div class="min-w-0">
                        <div class="record-inner-label text-[10px] uppercase">
                            {{ __('compositions.detail.treatment_violation') }}
                        </div>
                        <div class="record-inner-value text-[14px] font-semibold break-words">
                            {{ $extensions->get('TREATMENT_VIOLATION') }}
                            @if ($extensions->has('TREATMENT_VIOLATION_DATE'))
                                ({{ $asDate($extensions->get('TREATMENT_VIOLATION_DATE')) }})
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="record-inner-id-col">
            <div class="min-w-0">
                <div class="record-inner-label text-[10px] uppercase">ID ЕСОЗ</div>
                <div class="record-inner-id-value">{{ data_get($detail, 'identifier.value') ?: '-' }}</div>
            </div>
            <div class="min-w-0">
                <div class="record-inner-label text-[10px] uppercase">{{ __('compositions.detail.encounter') }}</div>
                <div class="record-inner-id-value">{{ data_get($detail, 'encounter.value') ?: '-' }}</div>
            </div>
        </div>
    </div>
</div>
