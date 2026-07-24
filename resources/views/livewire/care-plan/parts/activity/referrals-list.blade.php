@php
    $linkedReferrals = collect($activeReferrals)->where('based_on_id', $activity->id);
@endphp

<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-sm font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Виписані направлення</h3>
        @if($linkedReferrals->isNotEmpty())
            <span class="text-xs text-gray-400 dark:text-gray-500">{{ $linkedReferrals->count() }} шт.</span>
        @endif
    </div>

    @if($linkedReferrals->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Ще немає виписаних направлень для цього призначення. Після успішного створення в ЕСОЗ тут з’явиться номер, статус і доступні дії.
        </p>
    @else
        <div class="space-y-3">
            @foreach($linkedReferrals as $referral)
                @php
                    $referralKind = $referral['kind'] ?? (isset($referral['service_id']) ? 'service_request' : 'device_request');
                    $statusCode = strtolower((string) ($referral['status'] ?? ''));
                    $statusLabel = $referral['status_label'] ?? ($referral['status'] ?? '—');
                    $statusBadgeClass = match (true) {
                        $statusCode === 'active' => 'badge-green',
                        in_array($statusCode, ['draft', 'new'], true) => 'badge-yellow',
                        in_array($statusCode, ['entered-in-error', 'revoked'], true) => 'badge-dark',
                        default => 'badge-dark',
                    };
                @endphp
                <div class="text-sm bg-gray-50 dark:bg-gray-900/60 p-4 rounded-lg border border-gray-100 dark:border-gray-600">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="space-y-2 min-w-0">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="font-bold text-gray-900 dark:text-gray-100">
                                    № {{ $referral['request_number'] ?? $referral['uuid'] }}
                                </span>
                                <span class="badge {{ $statusBadgeClass }}">
                                    {{ $statusLabel }}
                                </span>
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-gray-600 dark:text-gray-300">
                                <span>Код: {{ $referral['product_code'] ?? '—' }}</span>
                                <span>Кількість: {{ $referral['quantity'] ?? '—' }}</span>
                                @if(!empty($referral['category_label']) || !empty($referral['category']))
                                    <span>Категорія: {{ $referral['category_label'] ?? $referral['category'] }}</span>
                                @endif
                                @if(!empty($referral['priority_label']) || !empty($referral['priority']))
                                    <span>Пріоритет: {{ $referral['priority_label'] ?? $referral['priority'] }}</span>
                                @endif
                            </div>
                            @if(!empty($referral['started_at']) && !empty($referral['ended_at']))
                                <div class="text-xs text-gray-400 dark:text-gray-500">
                                    Діє з {{ \Carbon\Carbon::parse($referral['started_at'])->format('d.m.Y') }}
                                    по {{ \Carbon\Carbon::parse($referral['ended_at'])->format('d.m.Y') }}
                                </div>
                            @endif
                            @if(!empty($referral['employee_name']))
                                <div class="text-xs text-gray-400 dark:text-gray-500">Виписав: {{ $referral['employee_name'] }}</div>
                            @endif
                            @if(!empty($referral['note']))
                                <div class="text-xs text-gray-500 dark:text-gray-400 italic">{{ $referral['note'] }}</div>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-3 shrink-0">
                            <button type="button"
                                    class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors flex items-center gap-1"
                                    title="Оновити з ЕСОЗ"
                                    wire:click="syncReferralFromEHealth('{{ $referral['uuid'] }}', '{{ $referralKind }}')">
                                @icon('refresh', 'w-4 h-4')
                                <span class="text-xs">Оновити</span>
                            </button>

                            @if(in_array($statusCode, ['draft', 'new'], true))
                                @php
                                    $signAction = $referralKind === 'service_request' ? 'sign_servicerequest' : 'sign_devicerequest';
                                @endphp
                                <button type="button" class="text-green-500 hover:text-green-600 dark:text-green-400 dark:hover:text-green-300 transition-colors flex items-center gap-1" title="Підписати КЕП" wire:click="$set('referralRequestIdToSign', '{{ $referral['uuid'] }}'); openSignatureModal('{{ $signAction }}')">
                                    @icon('key', 'w-4 h-4')
                                    <span class="text-xs">Підписати</span>
                                </button>
                            @endif

                            @if($statusCode === 'active')
                                <button type="button" class="text-blue-500 hover:text-blue-600 dark:text-blue-400 dark:hover:text-blue-300 transition-colors flex items-center gap-1" title="Друк пам'ятки"
                                        @click="
                                            $wire.loadReferralPrintoutForm('{{ $referral['uuid'] }}').then((html) => {
                                                if (!html) {
                                                    return;
                                                }
                                                const printWindow = window.open('', '_blank');
                                                printWindow.document.open();
                                                printWindow.document.write('<!DOCTYPE html><html><head><meta charset=&quot;utf-8&quot;><title>Пам\'ятка</title></head><body>' + html + '</body></html>');
                                                printWindow.document.close();
                                                printWindow.onload = () => {
                                                    printWindow.focus();
                                                    printWindow.print();
                                                };
                                            });
                                        ">
                                    @icon('printer', 'w-4 h-4')
                                    <span class="text-xs">Пам'ятка</span>
                                </button>
                                <button type="button" class="text-yellow-600 hover:text-yellow-500 dark:text-yellow-400 dark:hover:text-yellow-300 transition-colors flex items-center gap-1" title="Повторно надіслати SMS" wire:click="resendReferralSms('{{ $referral['uuid'] }}', '{{ $referralKind }}')">
                                    @icon('refresh', 'w-4 h-4')
                                    <span class="text-xs">SMS</span>
                                </button>
                                <button type="button" class="text-red-500 hover:text-red-400 dark:text-red-400 dark:hover:text-red-300 transition-colors flex items-center gap-1" title="Скасувати направлення" wire:click="cancelReferral('{{ $referral['uuid'] }}', '{{ $referralKind }}')">
                                    @icon('trash', 'w-4 h-4')
                                    <span class="text-xs">Скасувати</span>
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
