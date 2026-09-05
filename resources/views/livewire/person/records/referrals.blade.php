<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName">
    @assets
        <script src="{{ asset('js/print-sandboxed.js') }}"></script>
    @endassets
    <x-slot name="headerActions">
        <button
            wire:click.prevent="applyFilters"
            type="button"
            class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
        >
            @icon('search-outline', 'w-4 h-4')
            Пошук
        </button>
        <button
            wire:click.prevent="resetFilters"
            type="button"
            class="button-primary-outline px-5 py-2 text-sm whitespace-nowrap"
        >
            Скинути фільтри
        </button>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>Реєстр електронних направлень пацієнта</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <label class="label" for="filterStatus">Статус</label>
                    <select id="filterStatus" wire:model="filterStatus" class="input-select peer w-full">
                        <option value="">Усі</option>
                        <option value="draft">Чернетка</option>
                        <option value="new">Новий (заявка)</option>
                        <option value="active">Активний</option>
                        <option value="in_progress">В роботі</option>
                        <option value="completed">Виконаний</option>
                        <option value="recalled">Відкликаний</option>
                        <option value="entered-in-error">Внесено помилково</option>
                    </select>
                </div>
                <div class="form-group group">
                    <label class="label" for="filterStartedAtFrom">Початок з</label>
                    <input id="filterStartedAtFrom" type="date" class="input peer" wire:model="filterStartedAtFrom" />
                </div>
                <div class="form-group group">
                    <label class="label" for="filterStartedAtTo">Початок по</label>
                    <input id="filterStartedAtTo" type="date" class="input peer" wire:model="filterStartedAtTo" />
                </div>
            </div>

            <div class="form-row-3 mb-8">
                <div class="form-group group">
                    <label class="label" for="filterEndedAtFrom">Кінець з</label>
                    <input id="filterEndedAtFrom" type="date" class="input peer" wire:model="filterEndedAtFrom" />
                </div>
                <div class="form-group group">
                    <label class="label" for="filterEndedAtTo">Кінець по</label>
                    <input id="filterEndedAtTo" type="date" class="input peer" wire:model="filterEndedAtTo" />
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">Номер</th>
                            <th class="px-4 py-3 text-left font-medium">Статус</th>
                            <th class="px-4 py-3 text-left font-medium">Послуга / виріб</th>
                            <th class="px-4 py-3 text-left font-medium">Кількість</th>
                            <th class="px-4 py-3 text-left font-medium">Період</th>
                            <th class="px-4 py-3 text-left font-medium">Основа</th>
                            <th class="px-4 py-3 text-left font-medium">Дії</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($referrals as $referral)
                            <tr wire:key="sr-{{ $referral['kind'] }}-{{ $referral['id'] ?? $referral['uuid'] }}">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-gray-900 dark:text-white">
                                        {{ $referral['requestNumber'] ?? '—' }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-gray-400">
                                        {{ $referral['categoryLabel'] ?? '' }}
                                    </div>
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
                                            {{ $expandedUuid === $referral['uuid'] ? 'Сховати' : 'Деталі' }}
                                        </button>

                                        @if (!empty($referral['canSign']))
                                            <button
                                                type="button"
                                                class="text-xs text-green-600 hover:text-green-700 dark:text-green-400"
                                                wire:click="openSign('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                            >
                                                Підписати
                                            </button>
                                            @if (!empty($referral['encounterId']) && $personId)
                                                <a
                                                    href="{{ route('encounter.edit', [legalEntity(), 'person' => $personId, 'encounterId' => $referral['encounterId']]) }}"
                                                    class="text-link text-xs"
                                                >
                                                    Редагувати
                                                </a>
                                            @elseif (!empty($referral['carePlanId']))
                                                <a
                                                    href="{{ route('care-plans.show', [legalEntity(), $referral['carePlanId']]) }}"
                                                    class="text-link text-xs"
                                                >
                                                    Редагувати
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
                                                    Відкликати
                                                </button>
                                            @endif
                                            @if (!empty($referral['canCancel']))
                                                <button
                                                    type="button"
                                                    class="text-xs text-red-600 hover:text-red-500 dark:text-red-400"
                                                    wire:click="cancelReferral('{{ $referral['uuid'] }}', '{{ $referral['kind'] }}')"
                                                >
                                                    Внесено помилково
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
                                                Пам'ятка
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
                                    <td colspan="7" class="bg-gray-50 px-4 py-3 text-sm dark:bg-gray-900/30">
                                        <div class="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <div class="text-[10px] text-gray-400 uppercase">Пріоритет</div>
                                                <div class="font-medium">{{ $referral['priorityLabel'] ?? '—' }}</div>
                                            </div>
                                            <div>
                                                <div class="text-[10px] text-gray-400 uppercase">Програма</div>
                                                <div class="font-medium">{{ $referral['programName'] ?? '—' }}</div>
                                            </div>
                                            <div>
                                                <div class="text-[10px] text-gray-400 uppercase">eHealth ID</div>
                                                <div class="font-medium break-all">{{ $referral['uuid'] }}</div>
                                            </div>
                                            <div class="sm:col-span-3">
                                                <div class="text-[10px] text-gray-400 uppercase">Примітка</div>
                                                <div>{{ $referral['note'] !== '' ? $referral['note'] : '—' }}</div>
                                            </div>
                                            <div class="sm:col-span-3">
                                                <div class="text-[10px] text-gray-400 uppercase">
                                                    Інструкція пацієнту
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
                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                    Направлень за обраними фільтрами не знайдено.
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
