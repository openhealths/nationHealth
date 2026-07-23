@php
    $linkedPrescriptions = collect($activePrescriptions)->where('based_on_id', $activity->id);
@endphp

@if($linkedPrescriptions->isNotEmpty())
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
        <h3 class="text-sm font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-4">Виписані Е-Рецепти</h3>
        <div class="space-y-2">
            @foreach($linkedPrescriptions as $prescription)
                <div class="flex items-center justify-between text-sm bg-gray-50 dark:bg-gray-700/40 p-3 rounded-lg border border-gray-100 dark:border-gray-700">
                    <div class="flex flex-wrap items-center gap-4">
                        <span class="font-bold text-gray-900 dark:text-white">№ {{ $prescription['request_number'] ?? $prescription['uuid'] }}</span>
                        <span class="text-gray-500">Кількість: {{ $prescription['medication_qty'] }}</span>
                        @if(!empty($prescription['started_at']) && !empty($prescription['ended_at']))
                            <span class="text-gray-400 text-xs">Діє з {{ \Carbon\Carbon::parse($prescription['started_at'])->format('d.m.Y') }} по {{ \Carbon\Carbon::parse($prescription['ended_at'])->format('d.m.Y') }}</span>
                        @endif
                        <span class="badge {{ strtolower($prescription['status']) === 'active' ? 'badge-green' : (strtolower($prescription['status']) === 'new' ? 'badge-yellow' : 'badge-dark') }}">
                            {{ $prescription['status'] }}
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        @if(strtolower($prescription['status']) === 'new')
                            <button type="button" class="text-green-500 hover:text-green-700 transition-colors flex items-center gap-1" title="Підписати КЕП" wire:click="$set('ePrescriptionRequestIdToSign', '{{ $prescription['uuid'] }}'); openSignatureModal('sign_eprescription')">
                                @icon('key', 'w-4 h-4')
                                <span class="text-xs">Підписати</span>
                            </button>
                        @endif
                        @if(strtolower($prescription['status']) === 'active')
                            <button type="button" class="text-blue-500 hover:text-blue-700 transition-colors flex items-center gap-1" title="Друк пам'ятки"
                                    @click="
                                        $wire.loadPrintoutForm('{{ $prescription['uuid'] }}').then(() => {
                                            let printWindow = window.open('', '_blank');
                                            printWindow.document.body.innerHTML = $wire.printableContent;
                                            printWindow.focus();
                                            printWindow.print();
                                        });
                                    ">
                                @icon('printer', 'w-4 h-4')
                                <span class="text-xs">Пам'ятка</span>
                            </button>
                            <button type="button" class="text-yellow-600 hover:text-yellow-800 transition-colors flex items-center gap-1" title="Повторно надіслати SMS" wire:click="resendPrescriptionSms('{{ $prescription['uuid'] }}')">
                                @icon('refresh', 'w-4 h-4')
                                <span class="text-xs">SMS</span>
                            </button>
                            <button type="button" class="text-red-500 hover:text-red-700 transition-colors flex items-center gap-1" title="Скасувати рецепт" wire:click="cancelPrescription('{{ $prescription['uuid'] }}')">
                                @icon('trash', 'w-4 h-4')
                                <span class="text-xs">Скасувати</span>
                            </button>
                        @endif
                        @if(in_array(strtolower($prescription['status']), ['new', 'active']))
                            <button type="button" class="text-orange-500 hover:text-orange-700 transition-colors flex items-center gap-1" title="Відхилити рецепт" wire:click="rejectPrescription('{{ $prescription['uuid'] }}')" wire:confirm="Ви дійсно бажаєте відхилити цей рецепт?">
                                @icon('x-circle', 'w-4 h-4')
                                <span class="text-xs">Відхилити</span>
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
