@php
    $linkedReferrals = collect($activeReferrals)->where('based_on_id', $activity->id);
@endphp

<div class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="mb-4 flex items-center justify-between">
        <h3 class="text-sm font-bold tracking-wider text-gray-500 uppercase dark:text-gray-400">
            {{
                ($activity->resolvedKind() ?? '') === 'device_request'
                ? 'Виписані електронні рецепти на МВ'
                : 'Виписані направлення'
            }}
        </h3>
        @if ($linkedReferrals->isNotEmpty())
            <span class="text-xs text-gray-400 dark:text-gray-500">{{ $linkedReferrals->count() }} шт.</span>
        @endif
    </div>

    @if ($linkedReferrals->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{
                ($activity->resolvedKind() ?? '') === 'device_request'
                ? 'Ще немає виписаних електронних рецептів на медичні вироби для цього призначення. Після успішного створення в ЕСОЗ тут з’явиться номер, статус і доступні дії.'
                : 'Ще немає виписаних направлень для цього призначення. Після успішного створення в ЕСОЗ тут з’явиться номер, статус і доступні дії.'
            }}
        </p>
    @else
        <div class="space-y-3">
            @foreach ($linkedReferrals as $referral)
                @php
                    $referralKind = $referral['kind'] ?? (isset($referral['service_id']) ? 'service_request' : 'device_request');
                    $status = \App\Enums\Person\ServiceRequestStatus::resolve($referral['status'] ?? null);
                    $statusLabel = $referral['status_label'] ?? \App\Enums\Person\ServiceRequestStatus::labelFor($referral['status'] ?? null);
                    $statusBadgeClass = \App\Enums\Person\ServiceRequestStatus::colorFor($referral['status'] ?? null);
                @endphp
                <div
                    class="rounded-lg border border-gray-100 bg-gray-50 p-4 text-sm dark:border-gray-600 dark:bg-gray-900/60"
                    wire:key="referral-{{ $referral['uuid'] }}"
                >
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 space-y-2">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="font-bold text-gray-900 dark:text-gray-100">
                                    № {{ $referral['request_number'] ?? $referral['requisition'] ?? $referral['uuid'] }}
                                </span>
                                <span class="badge {{ $statusBadgeClass }}"> {{ $statusLabel }} </span>
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-gray-600 dark:text-gray-300">
                                <span>Код: {{ $referral['product_code'] ?? '—' }}</span>
                                <span>Кількість: {{ $referral['quantity'] ?? '—' }}</span>
                                @if (!empty($referral['category_label']) || !empty($referral['category']))
                                    <span>Категорія: {{ $referral['category_label'] ?? $referral['category'] }}</span>
                                @endif
                                @if (!empty($referral['priority_label']) || !empty($referral['priority']))
                                    <span>Пріоритет: {{ $referral['priority_label'] ?? $referral['priority'] }}</span>
                                @endif
                            </div>
                            @if (!empty($referral['started_at']) && !empty($referral['ended_at']))
                                <div class="text-xs text-gray-400 dark:text-gray-500">
                                    Діє з {{ \Carbon\Carbon::parse($referral['started_at'])->format('d.m.Y') }} по {{ \Carbon\Carbon::parse($referral['ended_at'])->format('d.m.Y') }}
                                </div>
                            @endif
                            @if (!empty($referral['employee_name']))
                                <div class="text-xs text-gray-400 dark:text-gray-500">
                                    Виписав: {{ $referral['employee_name'] }}
                                </div>
                            @endif
                            @if (!empty($referral['note']))
                                <div class="text-xs text-gray-500 italic dark:text-gray-400">
                                    {{ $referral['note'] }}
                                </div>
                            @endif
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-3">
                            <button
                                type="button"
                                class="flex items-center gap-1 text-gray-500 transition-colors hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                                title="{{ __('care-plan.referral_sync_from_ehealth') }}"
                                wire:click="syncReferralFromEHealth('{{ $referral['uuid'] }}', '{{ $referralKind }}')"
                            >
                                @icon('refresh', 'w-4 h-4')
                                <span class="text-xs">{{ __('care-plan.referral_sync_from_ehealth') }}</span>
                            </button>

                            @if (in_array($status, [\App\Enums\Person\ServiceRequestStatus::DRAFT, \App\Enums\Person\ServiceRequestStatus::NEW], true))
                                @php
                                    $signAction = $referralKind === 'service_request' ? 'sign_servicerequest' : 'sign_devicerequest';
                                @endphp
                                <button
                                    type="button"
                                    class="flex items-center gap-1 text-green-500 transition-colors hover:text-green-600 dark:text-green-400 dark:hover:text-green-300"
                                    title="Підписати КЕП"
                                    wire:click="openSignatureModal('{{ $signAction }}', null, '{{ $referral['uuid'] }}')"
                                >
                                    @icon('key', 'w-4 h-4')
                                    <span class="text-xs">Підписати</span>
                                </button>
                            @endif

                            @if ($status === \App\Enums\Person\ServiceRequestStatus::ACTIVE)
                                <button
                                    type="button"
                                    class="flex items-center gap-1 text-blue-500 transition-colors hover:text-blue-600 dark:text-blue-400 dark:hover:text-blue-300"
                                    title="Друк пам'ятки"
                                    @click="
                                            $wire.loadReferralPrintoutForm('{{ $referral['uuid'] }}').then((html) => {
                                                if (! html) {
                                                    return;
                                                }
                                                window.printSandboxedHtml(html);
                                            });
                                        "
                                >
                                    @icon('printer', 'w-4 h-4')
                                    <span class="text-xs">Пам'ятка</span>
                                </button>
                                <button
                                    type="button"
                                    class="flex items-center gap-1 text-yellow-600 transition-colors hover:text-yellow-500 dark:text-yellow-400 dark:hover:text-yellow-300"
                                    title="Повторно надіслати SMS"
                                    wire:click="resendReferralSms('{{ $referral['uuid'] }}', '{{ $referralKind }}')"
                                >
                                    @icon('mail', 'w-4 h-4')
                                    <span class="text-xs">SMS</span>
                                </button>
                                @if ($referralKind === 'service_request')
                                    <button
                                        type="button"
                                        class="flex items-center gap-1 text-amber-600 transition-colors hover:text-amber-500 dark:text-amber-400 dark:hover:text-amber-300"
                                        title="Відкликати (за непотрібністю)"
                                        wire:click="recallReferral('{{ $referral['uuid'] }}', '{{ $referralKind }}')"
                                    >
                                        @icon('trash', 'w-4 h-4')
                                        <span class="text-xs">Відкликати</span>
                                    </button>
                                @endif
                                <button
                                    type="button"
                                    class="flex items-center gap-1 text-red-500 transition-colors hover:text-red-400 dark:text-red-400 dark:hover:text-red-300"
                                    title="Позначити внесеним помилково"
                                    wire:click="cancelReferral('{{ $referral['uuid'] }}', '{{ $referralKind }}')"
                                >
                                    @icon('trash', 'w-4 h-4')
                                    <span class="text-xs">Внесено помилково</span>
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
