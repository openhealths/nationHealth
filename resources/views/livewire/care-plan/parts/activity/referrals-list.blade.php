@php
    $linkedReferrals = collect($activeReferrals)->where('based_on_id', $activity->id);
@endphp

@if($linkedReferrals->isNotEmpty())
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
        <h3 class="text-sm font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-4">Виписані Направлення</h3>
        <div class="space-y-2">
            @foreach($linkedReferrals as $referral)
                <div class="flex items-center justify-between text-sm bg-gray-50 dark:bg-gray-700/40 p-3 rounded-lg border border-gray-100 dark:border-gray-700">
                    <div class="flex flex-wrap items-center gap-4">
                        <span class="font-bold text-gray-900 dark:text-white">№ {{ $referral['request_number'] ?? $referral['uuid'] }}</span>
                        <span class="text-gray-500">Кількість: {{ $referral['quantity'] }}</span>
                        @if(!empty($referral['started_at']) && !empty($referral['ended_at']))
                            <span class="text-gray-400 text-xs">Діє з {{ \Carbon\Carbon::parse($referral['started_at'])->format('d.m.Y') }} по {{ \Carbon\Carbon::parse($referral['ended_at'])->format('d.m.Y') }}</span>
                        @endif
                        <span class="badge {{ strtolower($referral['status']) === 'active' ? 'badge-green' : (in_array(strtolower($referral['status']), ['draft', 'new']) ? 'badge-yellow' : 'badge-dark') }}">
                            {{ $referral['status'] }}
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        @if(in_array(strtolower($referral['status']), ['draft', 'new']))
                            @php
                                $signAction = isset($referral['service_id']) ? 'sign_servicerequest' : 'sign_devicerequest';
                            @endphp
                            <button type="button" class="text-green-500 hover:text-green-700 transition-colors flex items-center gap-1" title="Підписати КЕП" wire:click="$set('referralRequestIdToSign', '{{ $referral['uuid'] }}'); openSignatureModal('{{ $signAction }}')">
                                @icon('key', 'w-4 h-4')
                                <span class="text-xs">Підписати</span>
                            </button>
                        @endif
                        @if(strtolower($referral['status']) === 'active')
                            <button type="button" class="text-blue-500 hover:text-blue-700 transition-colors flex items-center gap-1" title="Друк пам'ятки"
                                    @click="
                                        $wire.loadReferralPrintoutForm('{{ $referral['uuid'] }}').then(() => {
                                            let printWindow = window.open('', '_blank');
                                            printWindow.document.body.innerHTML = $wire.printableContent;
                                            printWindow.focus();
                                            printWindow.print();
                                        });
                                    ">
                                @icon('printer', 'w-4 h-4')
                                <span class="text-xs">Пам'ятка</span>
                            </button>
                            <button type="button" class="text-yellow-600 hover:text-yellow-800 transition-colors flex items-center gap-1" title="Повторно надіслати SMS" wire:click="resendReferralSms('{{ $referral['uuid'] }}', '{{ isset($referral['service_id']) ? 'service_request' : 'device_request' }}')">
                                @icon('refresh', 'w-4 h-4')
                                <span class="text-xs">SMS</span>
                            </button>
                            <button type="button" class="text-red-500 hover:text-red-700 transition-colors flex items-center gap-1" title="Скасувати направлення" wire:click="cancelReferral('{{ $referral['uuid'] }}', '{{ isset($referral['service_id']) ? 'service_request' : 'device_request' }}')">
                                @icon('trash', 'w-4 h-4')
                                <span class="text-xs">Скасувати</span>
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
