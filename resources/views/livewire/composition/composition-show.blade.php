{{--
    Detail of a medical conclusion, as returned by getComposition.

    Extensions arrive as a flat list of `{valueCode, value<Type>}` objects rather than a
    keyed map, so they are folded into one lookup before being read.

    @var array|null $compositionDetail
--}}
@php
    $detail = $compositionDetail ?? [];
    $status = \App\Enums\Person\CompositionStatus::fromEHealth(data_get($detail, 'status'));
    $type = \App\Enums\Person\CompositionType::tryFrom((string) data_get($detail, 'type.coding.0.code'));
    $category = \App\Enums\Person\CompositionCategory::tryFrom((string) data_get($detail, 'category.coding.0.code'));

    $extensions = collect(data_get($detail, 'extension', []))
        ->filter(static fn ($extension) => is_array($extension) && isset($extension['valueCode']))
        ->mapWithKeys(static function (array $extension) {
            $value = $extension['valueUuid']
                ?? $extension['valueDate']
                ?? $extension['valueString']
                ?? $extension['valueBoolean']
                ?? null;

            return [$extension['valueCode'] => $value];
        });

    $formatDate = static fn (?string $value) => $value
        ? \Carbon\CarbonImmutable::parse($value)->format(config('app.date_format'))
        : '-';
@endphp

<x-dialog-modal maxWidth="3xl" id="modal-composition-detail" wire:model.live="showDetailModal">
    <x-slot name="title">
        <div class="flex items-center justify-between gap-4">
            <span>{{ __('compositions.detail.title') }}</span>
            @if ($status)
                <span @class([$status->color()])>{{ $status->label() }}</span>
            @endif
        </div>
    </x-slot>

    <x-slot name="content">
        <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            <div class="min-w-0">
                <div class="record-inner-label text-[10px] uppercase">{{ __('compositions.detail.number') }}</div>
                <div class="record-inner-value text-[14px] font-semibold break-words">
                    {{ data_get($detail, 'title') ?: '-' }}
                </div>
            </div>

            <div class="min-w-0">
                <div class="record-inner-label text-[10px] uppercase">{{ __('compositions.detail.date') }}</div>
                <div class="record-inner-value text-[14px] font-semibold break-words">
                    {{ $formatDate(data_get($detail, 'date')) }}
                </div>
            </div>

            <div class="min-w-0">
                <div class="record-inner-label text-[10px] uppercase">{{ __('compositions.detail.type') }}</div>
                <div class="record-inner-value text-[14px] font-semibold break-words">{{ $type?->label() ?? '-' }}</div>
            </div>

            <div class="min-w-0">
                <div class="record-inner-label text-[10px] uppercase">{{ __('compositions.detail.category') }}</div>
                <div class="record-inner-value text-[14px] font-semibold break-words">
                    {{ $category?->label() ?? '-' }}
                </div>
            </div>

            <div class="min-w-0">
                <div class="record-inner-label text-[10px] uppercase">{{ __('compositions.detail.period_start') }}</div>
                <div class="record-inner-value text-[14px] font-semibold break-words">
                    {{ $formatDate(data_get($detail, 'event.0.period.start') ?? data_get($detail, 'event.period.start')) }}
                </div>
            </div>

            <div class="min-w-0">
                <div class="record-inner-label text-[10px] uppercase">{{ __('compositions.detail.period_end') }}</div>
                <div class="record-inner-value text-[14px] font-semibold break-words">
                    {{ $formatDate(data_get($detail, 'event.0.period.end') ?? data_get($detail, 'event.period.end')) }}
                </div>
            </div>

            <div class="min-w-0">
                <div class="record-inner-label text-[10px] uppercase">{{ __('compositions.detail.author') }}</div>
                <div class="record-inner-id-value">{{ data_get($detail, 'author.value') ?: '-' }}</div>
            </div>

            <div class="min-w-0">
                <div class="record-inner-label text-[10px] uppercase">{{ __('compositions.detail.custodian') }}</div>
                <div class="record-inner-id-value">{{ data_get($detail, 'custodian.value') ?: '-' }}</div>
            </div>

            <div class="min-w-0">
                <div class="record-inner-label text-[10px] uppercase">{{ __('compositions.detail.subject') }}</div>
                <div class="record-inner-id-value">{{ data_get($detail, 'subject.value') ?: '-' }}</div>
            </div>

            <div class="min-w-0">
                <div class="record-inner-label text-[10px] uppercase">{{ __('compositions.detail.focus') }}</div>
                <div class="record-inner-id-value">{{ data_get($detail, 'section.focus.value') ?: '-' }}</div>
            </div>

            <div class="min-w-0">
                <div class="record-inner-label text-[10px] uppercase">{{ __('compositions.detail.encounter') }}</div>
                <div class="record-inner-id-value">{{ data_get($detail, 'encounter.value') ?: '-' }}</div>
            </div>

            @if ($extensions->has('NEWBORN_BIRTH_DATE'))
                <div class="min-w-0">
                    <div class="record-inner-label text-[10px] uppercase">
                        {{ __('compositions.detail.newborn_birth_date') }}
                    </div>
                    <div class="record-inner-value text-[14px] font-semibold break-words">
                        {{ $formatDate($extensions->get('NEWBORN_BIRTH_DATE')) }}
                    </div>
                </div>
            @endif

            @if ($extensions->has('NEWBORN_SEX'))
                <div class="min-w-0">
                    <div class="record-inner-label text-[10px] uppercase">
                        {{ __('compositions.detail.newborn_sex') }}
                    </div>
                    <div class="record-inner-value text-[14px] font-semibold break-words">
                        {{ $extensions->get('NEWBORN_SEX') }}
                    </div>
                </div>
            @endif

            @foreach (['IS_ACCIDENT', 'IS_INTOXICATED', 'IS_FOREIGN_TREATMENT', 'IS_FORCE_RENEW'] as $flag)
                @if ($extensions->get($flag))
                    <div class="min-w-0">
                        <div class="record-inner-label text-[10px] uppercase">
                            {{ __('compositions.detail.flags.' . \Illuminate\Support\Str::lower($flag)) }}
                        </div>
                        <div class="record-inner-value text-[14px] font-semibold break-words">
                            {{ __('forms.yes') }}
                        </div>
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
                            ({{ $formatDate($extensions->get('TREATMENT_VIOLATION_DATE')) }})
                        @endif
                    </div>
                </div>
            @endif

            @if (data_get($detail, 'relatesTo'))
                <div class="min-w-0 md:col-span-2">
                    <div class="record-inner-label text-[10px] uppercase">
                        {{ __('compositions.detail.relates_to') }}
                    </div>
                    <div class="record-inner-value text-[14px] font-semibold break-words">
                        {{ data_get($detail, 'relatesTo.code') }}:
                        <span class="record-inner-id-value">{{ data_get($detail, 'relatesTo.targetIdentifier.value') ?: '-' }}</span>
                    </div>
                </div>
            @endif
        </div>
    </x-slot>

    <x-slot name="footer">
        <button
            type="button"
            wire:click="loadPrintForm('{{ $viewingCompositionUuid }}')"
            id="btn-detail-print"
            class="button-primary px-5 py-2 text-sm"
        >
            {{ __('compositions.actions.print') }}
        </button>
        <button type="button" wire:click="closeDetailModal" class="button-primary-outline ml-2 px-5 py-2 text-sm">
            {{ __('forms.close') }}
        </button>
    </x-slot>
</x-dialog-modal>
