<x-layouts.patient :showLegacyMessages="false" :personId="$personId" :patientFullName="$patientFullName">
    @assets
        <script src="{{ asset('js/print-sandboxed.js') }}"></script>
    @endassets
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />

    <x-slot name="headerActions">
        @can('device_request:read')
            <a
                class="button-primary"
                href="{{ route('device-requests.index', ['legalEntity' => legalEntity(), 'person' => $personId]) }}"
            >Е-запити на медичні вироби</a>
        @endcan
        <button
            wire:click.prevent="applyFilters"
            type="button"
            class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
        >
            @icon('search-outline', 'w-4 h-4')
            {{ __('Пошук') }}
        </button>
        <button
            wire:click.prevent="resetFilters"
            type="button"
            class="button-primary-outline px-5 py-2 text-sm whitespace-nowrap"
        >
            {{ __('Скинути фільтри') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('Реєстр електронних направлень пацієнта') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <label class="label" for="filterStatus">{{ __('Статус') }}</label>
                    <select id="filterStatus" wire:model="filterStatus" class="input-select peer w-full">
                        <option value="">{{ __('Усі') }}</option>
                        <option value="draft">{{ __('Чернетка') }}</option>
                        <option value="new">{{ __('Новий (заявка)') }}</option>
                        <option value="active">{{ __('Активний') }}</option>
                        <option value="in_progress">{{ __('В роботі') }}</option>
                        <option value="completed">{{ __('Виконаний') }}</option>
                        <option value="recalled">{{ __('Відкликаний') }}</option>
                        <option value="entered-in-error">{{ __('Внесено помилково') }}</option>
                    </select>
                </div>
                <div class="form-group group">
                    <label class="label" for="filterStartedAtFrom">{{ __('Початок з') }}</label>
                    <input id="filterStartedAtFrom" type="date" class="input peer" wire:model="filterStartedAtFrom" />
                </div>
                <div class="form-group group">
                    <label class="label" for="filterStartedAtTo">{{ __('Початок по') }}</label>
                    <input id="filterStartedAtTo" type="date" class="input peer" wire:model="filterStartedAtTo" />
                </div>
            </div>

            <div class="form-row-3 mb-8">
                <div class="form-group group">
                    <label class="label" for="filterEndedAtFrom">{{ __('Кінець з') }}</label>
                    <input id="filterEndedAtFrom" type="date" class="input peer" wire:model="filterEndedAtFrom" />
                </div>
                <div class="form-group group">
                    <label class="label" for="filterEndedAtTo">{{ __('Кінець по') }}</label>
                    <input id="filterEndedAtTo" type="date" class="input peer" wire:model="filterEndedAtTo" />
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">{{ __('Номер') }}</th>
                            <th class="px-4 py-3 text-left font-medium">UUID</th>
                            <th class="px-4 py-3 text-left font-medium">{{ __('Статус') }}</th>
                            <th class="px-4 py-3 text-left font-medium">{{ __('Послуга / виріб') }}</th>
                            <th class="px-4 py-3 text-left font-medium">{{ __('Кількість') }}</th>
                            <th class="px-4 py-3 text-left font-medium">{{ __('Період') }}</th>
                            <th class="px-4 py-3 text-left font-medium">{{ __('Основа') }}</th>
                            <th class="px-4 py-3 text-left font-medium">{{ __('Дії') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($referrals as $referral)
                            <tr wire:key="sr-{{ $referral['kind'] }}-{{ $referral['id'] ?? $referral['uuid'] }}">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-gray-900 dark:text-white">
                                        {{ filled($referral['requestNumber'] ?? null) ? $referral['requestNumber'] : '—' }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-gray-400">
                                        {{ $referral['categoryLabel'] ?? '' }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs break-all text-gray-600 dark:text-gray-300">
                                    {{ $referral['uuid'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="badge {{ $referral['statusBadge'] ?? 'badge-dark' }}">
                                        {{ $referral['statusLabel'] ?? ($referral['status'] ?? '—') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-900 dark:text-gray-100">
                                    {{ $referral['itemName'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $referral['quantity'] ?? '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $referral['periodLabel'] ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if (!empty($referral['carePlanId']) && !empty($referral['activityId']))
                                        <a
                                            href="{{ route('care-plans.activities.show', [legalEntity(), $referral['carePlanId'], $referral['activityId']]) }}"
                                            class="text-link"
                                        >
                                            {{ $referral['basisLabel'] }}
                                        </a>
                                    @elseif (!empty($referral['carePlanId']))
                                        <a
                                            href="{{ route('care-plans.show', [legalEntity(), $referral['carePlanId']]) }}"
                                            class="text-link"
                                        >
                                            {{ $referral['basisLabel'] }}
                                        </a>
                                    @elseif (!empty($referral['encounterId']) && $personId)
                                        <a
                                            href="{{ route('encounter.edit', [legalEntity(), 'person' => $personId, 'encounterId' => $referral['encounterId']]) }}"
                                            class="text-link"
                                        >
                                            {{ $referral['basisLabel'] }}
                                        </a>
                                    @else
                                        {{ $referral['basisLabel'] ?? '—' }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            class="text-xs text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200"
                                            wire:click="toggleDetails('{{ $referral['uuid'] }}')"
                                        >
                                            {{ $expandedUuid === $referral['uuid'] ? __('Сховати') : __('Деталі') }}
                                        </button>

                                        @if (!empty($referral['canSign']))
                                            <button
                                                type="button"
                                                class="text-xs text-green-600 hover:text-green-700 dark:text-green-400"
                                                wire:click="openSign('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                            >
                                                {{ __('Підписати') }}
                                            </button>
                                            @if (!empty($referral['encounterId']) && $personId)
                                                <a
                                                    href="{{ route('encounter.edit', [legalEntity(), 'person' => $personId, 'encounterId' => $referral['encounterId']]) }}"
                                                    class="text-link text-xs"
                                                >
                                                    {{ __('Редагувати') }}
                                                </a>
                                            @elseif (!empty($referral['carePlanId']))
                                                <a
                                                    href="{{ route('care-plans.show', [legalEntity(), $referral['carePlanId']]) }}"
                                                    class="text-link text-xs"
                                                >
                                                    {{ __('Редагувати') }}
                                                </a>
                                            @endif
                                        @endif

                                        @if (!empty($referral['canOperate']))
                                            @if (!empty($referral['canRecall']))
                                                <button
                                                    type="button"
                                                    class="text-xs text-amber-600 hover:text-amber-500 dark:text-amber-400"
                                                    wire:click="recallReferral('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                                >
                                                    {{ __('Відкликати') }}
                                                </button>
                                            @endif
                                            @if (!empty($referral['canCancel']))
                                                <button
                                                    type="button"
                                                    class="text-xs text-red-600 hover:text-red-500 dark:text-red-400"
                                                    wire:click="cancelReferral('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                                >
                                                    {{ __('Внесено помилково') }}
                                                </button>
                                            @endif
                                            <button
                                                type="button"
                                                class="text-xs text-blue-600 hover:text-blue-700 dark:text-blue-400"
                                                @click="
                                                    $wire.loadReferralPrintoutForm('{{ $referral['uuid'] }}').then((html) => {
                                                        if (! html) {
                                                            return;
                                                        }
                                                        window.printSandboxedHtml(html);
                                                    });
                                                "
                                            >
                                                {{ __('Пам\'ятка') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="text-xs text-yellow-600 hover:text-yellow-700 dark:text-yellow-400"
                                                wire:click="resendSms('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                            >
                                                SMS
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @if ($expandedUuid === $referral['uuid'])
                                <tr wire:key="sr-details-{{ $referral['uuid'] }}">
                                    <td colspan="8" class="bg-gray-50 px-4 py-3 text-sm dark:bg-gray-900/30">
                                        <div class="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <div class="text-[10px] text-gray-400 uppercase">
                                                    {{ __('Пріоритет') }}
                                                </div>
                                                <div class="font-medium">{{ $referral['priorityLabel'] ?? '—' }}</div>
                                            </div>
                                            <div>
                                                <div class="text-[10px] text-gray-400 uppercase">
                                                    {{ __('Програма') }}
                                                </div>
                                                <div class="font-medium">{{ $referral['programName'] ?? '—' }}</div>
                                            </div>
                                            <div>
                                                <div class="text-[10px] text-gray-400 uppercase">eHealth ID</div>
                                                <div class="font-medium break-all">{{ $referral['uuid'] }}</div>
                                            </div>
                                            <div class="sm:col-span-3">
                                                <div class="text-[10px] text-gray-400 uppercase">
                                                    {{ __('Примітка') }}
                                                </div>
                                                <div>{{ $referral['note'] !== '' ? $referral['note'] : '—' }}</div>
                                            </div>
                                            <div class="sm:col-span-3">
                                                <div class="text-[10px] text-gray-400 uppercase">
                                                    {{ __('Інструкція пацієнту') }}
                                                </div>
                                                <div>
                                                    {{ $referral['patientInstruction'] !== '' ? $referral['patientInstruction'] : '—' }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                    {{ __('Направлень за обраними фільтрами не знайдено.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-signature-modal
        method="sign"
        :only-actions="['sign_referral', 'sign_devicerequest', 'recall_referral', 'cancel_referral']"
    />
</x-layouts.patient>
