@php
    $linkedPrescriptions = collect($activePrescriptions)->filter(function ($item) use ($activity) {
        return (int) ($item['based_on_id'] ?? $item['basedOnId'] ?? 0) === (int) $activity->id;
    });
@endphp

<div class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="mb-4 flex items-center justify-between">
        <h3 class="text-sm font-bold tracking-wider text-gray-500 uppercase dark:text-gray-400">Виписані Е-Рецепти</h3>
        <div class="flex items-center gap-4">
            @if ($linkedPrescriptions->isNotEmpty())
                <span class="text-xs text-gray-400 dark:text-gray-500">{{ $linkedPrescriptions->count() }} шт.</span>
            @endif
            <button
                type="button"
                wire:click="syncEPrescriptions"
                wire:loading.attr="disabled"
                class="flex items-center gap-1 text-xs font-semibold text-teal-600 transition hover:text-teal-700 dark:text-teal-400 dark:hover:text-teal-300"
                title="Оновити статуси з ЕСОЗ"
            >
                @icon('refresh', 'w-3.5 h-3.5')
                <span>Синхронізувати з ЕСОЗ</span>
            </button>
        </div>
    </div>

    @if ($linkedPrescriptions->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Ще немає виписаних електронних рецептів для цього призначення. Після успішного створення в ЕСОЗ тут
            з’явиться номер, статус і доступні дії.
        </p>
    @else
        <div class="space-y-3">
            @foreach ($linkedPrescriptions as $prescription)
                @php
                    $rawStatus = (string) ($prescription['status'] ?? '');
                    $status = \App\Enums\Person\MedicationRequestStatus::resolve($rawStatus);
                    $uuid = $prescription['uuid'] ?? '';
                    $requestNumber = $prescription['request_number'] ?? $prescription['requestNumber'] ?? $uuid;
                    $medicationQty = $prescription['medication_qty'] ?? $prescription['medicationQty'] ?? '—';
                    $startedAt = $prescription['started_at'] ?? $prescription['startedAt'] ?? null;
                    $endedAt = $prescription['ended_at'] ?? $prescription['endedAt'] ?? null;
                @endphp
                <div class="flex items-center justify-between rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-700/40">
                    <div class="flex flex-wrap items-center gap-4">
                        <span class="font-bold text-gray-900 dark:text-white">№ {{ $requestNumber }}</span>
                        <span class="text-gray-500">Кількість: {{ $medicationQty }}</span>
                        @if (!empty($startedAt) && !empty($endedAt))
                            <span class="text-xs text-gray-400">Діє з {{ \Carbon\Carbon::parse($startedAt)->format('d.m.Y') }} по {{ \Carbon\Carbon::parse($endedAt)->format('d.m.Y') }}</span>
                        @endif
                        <span class="badge {{ \App\Enums\Person\MedicationRequestStatus::colorFor($rawStatus) }}">
                            {{ \App\Enums\Person\MedicationRequestStatus::labelFor($rawStatus) }}
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        @if ($status?->isUnsigned())
                            <button
                                type="button"
                                class="flex items-center gap-1 text-green-500 transition-colors hover:text-green-700"
                                title="Підписати КЕП"
                                wire:click="openSignatureModal('sign_eprescription', null, '{{ $uuid }}')"
                            >
                                @icon('key', 'w-4 h-4')
                                <span class="text-xs">Підписати</span>
                            </button>
                        @endif
                        @if ($status === \App\Enums\Person\MedicationRequestStatus::ACTIVE)
                            <button
                                type="button"
                                class="flex items-center gap-1 text-blue-500 transition-colors hover:text-blue-700"
                                title="Друк пам'ятки"
                                @click="
                                        $wire.loadPrintoutForm('{{ $uuid }}').then((content) => {
                                            window.printSandboxedHtml(content || $wire.printableContent || '<h3>Дані для друку відсутні</h3>');
                                        });
                                    "
                            >
                                @icon('printer', 'w-4 h-4')
                                <span class="text-xs">Пам'ятка</span>
                            </button>
                            <button
                                type="button"
                                class="flex items-center gap-1 text-yellow-600 transition-colors hover:text-yellow-800"
                                title="Повторно надіслати SMS"
                                wire:click="resendPrescriptionSms('{{ $uuid }}')"
                            >
                                @icon('refresh', 'w-4 h-4')
                                <span class="text-xs">SMS</span>
                            </button>
                            <button
                                type="button"
                                class="flex items-center gap-1 text-indigo-600 transition-colors hover:text-indigo-800"
                                title="Історія погашення в аптеках"
                                wire:click="checkDispenseHistory('{{ $uuid }}')"
                            >
                                @icon('file-text', 'w-4 h-4')
                                <span class="text-xs">Історія</span>
                            </button>
                        @endif
                        @if ($status?->isUnsigned() || $status === \App\Enums\Person\MedicationRequestStatus::ACTIVE)
                            <button
                                type="button"
                                class="flex items-center gap-1 text-orange-500 transition-colors hover:text-orange-700"
                                title="Відхилити рецепт"
                                wire:click="rejectPrescription('{{ $uuid }}')"
                            >
                                @icon('x-circle', 'w-4 h-4')
                                <span class="text-xs">Відхилити</span>
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
