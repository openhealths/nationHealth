@php
    $linkedPrescriptions = collect($activePrescriptions)->filter(function ($item) use ($activity) {
        return (int) ($item['based_on_id'] ?? $item['basedOnId'] ?? 0) === (int) $activity->id;
    });
@endphp

<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-sm font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Виписані Е-Рецепти</h3>
        <div class="flex items-center gap-4">
            @if($linkedPrescriptions->isNotEmpty())
                <span class="text-xs text-gray-400 dark:text-gray-500">{{ $linkedPrescriptions->count() }} шт.</span>
            @endif
            <button type="button" wire:click="syncEPrescriptions" wire:loading.attr="disabled" class="text-xs font-semibold text-teal-600 hover:text-teal-700 dark:text-teal-400 dark:hover:text-teal-300 transition flex items-center gap-1" title="Оновити статуси з ЕСОЗ">
                @icon('refresh', 'w-3.5 h-3.5')
                <span>Синхронізувати з ЕСОЗ</span>
            </button>
        </div>
    </div>

    @if($linkedPrescriptions->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Ще немає виписаних електронних рецептів для цього призначення. Після успішного створення в ЕСОЗ тут з’явиться номер, статус і доступні дії.
        </p>
    @else
        <div class="space-y-3">
            @foreach($linkedPrescriptions as $prescription)
                @php
                    $status = strtolower((string) ($prescription['status'] ?? ''));
                    $uuid = $prescription['uuid'] ?? '';
                    $requestNumber = $prescription['request_number'] ?? $prescription['requestNumber'] ?? $uuid;
                    $medicationQty = $prescription['medication_qty'] ?? $prescription['medicationQty'] ?? '—';
                    $startedAt = $prescription['started_at'] ?? $prescription['startedAt'] ?? null;
                    $endedAt = $prescription['ended_at'] ?? $prescription['endedAt'] ?? null;
                @endphp
                <div class="flex items-center justify-between text-sm bg-gray-50 dark:bg-gray-700/40 p-3 rounded-lg border border-gray-100 dark:border-gray-700">
                    <div class="flex flex-wrap items-center gap-4">
                        <span class="font-bold text-gray-900 dark:text-white">№ {{ $requestNumber }}</span>
                        <span class="text-gray-500">Кількість: {{ $medicationQty }}</span>
                        @if(!empty($startedAt) && !empty($endedAt))
                            <span class="text-gray-400 text-xs">Діє з {{ \Carbon\Carbon::parse($startedAt)->format('d.m.Y') }} по {{ \Carbon\Carbon::parse($endedAt)->format('d.m.Y') }}</span>
                        @endif
                        <span class="badge {{ match($status) {
                            'active', 'completed', 'signed' => 'badge-green',
                            'new', 'draft' => 'badge-yellow',
                            'pending', 'processing' => 'badge-blue',
                            'rejected', 'expired' => 'badge-red',
                            default => 'badge-dark'
                        } }}">
                            {{ match($status) {
                                'new' => 'Новий',
                                'draft' => 'Чернетка',
                                'signed' => 'Підписаний',
                                'active' => 'Активний',
                                'completed' => 'Виконаний',
                                'rejected' => 'Відхилений',
                                'expired' => 'Протермінований',
                                'pending', 'processing' => 'В обробці',
                                default => ucfirst((string) ($prescription['status'] ?? '')),
                            } }}
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        @if(in_array($status, ['new', 'draft'], true))
                            <button type="button" class="text-green-500 hover:text-green-700 transition-colors flex items-center gap-1" title="Підписати КЕП" wire:click="openSignatureModal('sign_eprescription', null, '{{ $uuid }}')">
                                @icon('key', 'w-4 h-4')
                                <span class="text-xs">Підписати</span>
                            </button>
                        @endif
                        @if($status === 'active')
                            <button type="button" class="text-blue-500 hover:text-blue-700 transition-colors flex items-center gap-1" title="Друк пам'ятки"
                                    @click="
                                        $wire.loadPrintoutForm('{{ $uuid }}').then((content) => {
                                            let printWindow = window.open('', '_blank');
                                            if (printWindow) {
                                                printWindow.document.open();
                                                printWindow.document.write(content || $wire.printableContent || '<h3>Дані для друку відсутні</h3>');
                                                printWindow.document.close();
                                                setTimeout(() => { printWindow.focus(); printWindow.print(); }, 250);
                                            }
                                        });
                                    ">
                                @icon('printer', 'w-4 h-4')
                                <span class="text-xs">Пам'ятка</span>
                            </button>
                            <button type="button" class="text-yellow-600 hover:text-yellow-800 transition-colors flex items-center gap-1" title="Повторно надіслати SMS" wire:click="resendPrescriptionSms('{{ $uuid }}')">
                                @icon('refresh', 'w-4 h-4')
                                <span class="text-xs">SMS</span>
                            </button>
                            <button type="button" class="text-indigo-600 hover:text-indigo-800 transition-colors flex items-center gap-1" title="Історія погашення в аптеках" wire:click="checkDispenseHistory('{{ $uuid }}')">
                                @icon('file-text', 'w-4 h-4')
                                <span class="text-xs">Погашення</span>
                            </button>
                        @endif
                        @if(in_array($status, ['new', 'draft', 'active'], true))
                            <button type="button" class="text-orange-500 hover:text-orange-700 transition-colors flex items-center gap-1" title="Відхилити рецепт" wire:click="rejectPrescription('{{ $uuid }}')">
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
