<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName">
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
                <p>Реєстр е-рецептів пацієнта</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <label class="label" for="filterStatus">Статус</label>
                    <select id="filterStatus" wire:model="filterStatus" class="input-select peer w-full">
                        <option value="">Усі</option>
                        <option value="NEW">Новий (заявка)</option>
                        <option value="draft">Чернетка</option>
                        <option value="active">Активний</option>
                        <option value="completed">Виконаний</option>
                        <option value="rejected">Відхилений</option>
                        <option value="entered-in-error">Внесено помилково</option>
                    </select>
                </div>
                <div class="form-group group">
                    <label class="label" for="filterStartedAtFrom">Початок курсу з</label>
                    <input id="filterStartedAtFrom" type="date" class="input peer" wire:model="filterStartedAtFrom" />
                </div>
                <div class="form-group group">
                    <label class="label" for="filterStartedAtTo">Початок курсу по</label>
                    <input id="filterStartedAtTo" type="date" class="input peer" wire:model="filterStartedAtTo" />
                </div>
            </div>

            <div class="form-row-3 mb-8">
                <div class="form-group group">
                    <label class="label" for="filterEndedAtFrom">Кінець курсу з</label>
                    <input id="filterEndedAtFrom" type="date" class="input peer" wire:model="filterEndedAtFrom" />
                </div>
                <div class="form-group group">
                    <label class="label" for="filterEndedAtTo">Кінець курсу по</label>
                    <input id="filterEndedAtTo" type="date" class="input peer" wire:model="filterEndedAtTo" />
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">Номер</th>
                            <th class="px-4 py-3 text-left font-medium">Статус</th>
                            <th class="px-4 py-3 text-left font-medium">Лікарський засіб</th>
                            <th class="px-4 py-3 text-left font-medium">Кількість</th>
                            <th class="px-4 py-3 text-left font-medium">Період</th>
                            <th class="px-4 py-3 text-left font-medium">Програма</th>
                            <th class="px-4 py-3 text-left font-medium">Основа</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($medicationRequests as $request)
                            <tr wire:key="mr-{{ $request['id'] ?? $request['uuid'] }}">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-gray-900 dark:text-white">
                                        {{ $request['requestNumber'] ?? '—' }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-gray-400">
                                        {{ $request['categoryLabel'] ?? '' }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="badge {{ $request['statusBadge'] ?? 'badge-dark' }}">
                                        {{ $request['statusLabel'] ?? ($request['status'] ?? '—') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-900 dark:text-gray-100">
                                    {{ $request['medicationName'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $request['medicationQty'] ?? '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $request['periodLabel'] ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $request['programName'] ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if (!empty($request['carePlanId']) && !empty($request['activityId']))
                                        <a
                                            href="{{ route('care-plans.activities.show', [legalEntity(), $request['carePlanId'], $request['activityId']]) }}"
                                            class="text-link"
                                        >
                                            {{ $request['basisLabel'] }}
                                        </a>
                                    @elseif (!empty($request['carePlanId']))
                                        <a
                                            href="{{ route('care-plans.show', [legalEntity(), $request['carePlanId']]) }}"
                                            class="text-link"
                                        >
                                            {{ $request['basisLabel'] }}
                                        </a>
                                    @elseif (!empty($request['encounterId']) && $personId)
                                        <a
                                            href="{{ route('encounter.edit', [legalEntity(), 'person' => $personId, 'encounterId' => $request['encounterId']]) }}"
                                            class="text-link"
                                        >
                                            {{ $request['basisLabel'] }}
                                        </a>
                                    @else
                                        {{ $request['basisLabel'] ?? '—' }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                    Рецептів за обраними фільтрами не знайдено.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.patient>
