<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        {{-- eHealth search bar --}}
        <div class="flex items-center gap-2">
            <input
                type="text"
                wire:model.defer="searchRequestNumber"
                placeholder="Номер рецепту / запиту"
                class="input h-9 w-48 text-sm"
            />
            <select wire:model.defer="searchStatus" class="input-select h-9 w-36 text-sm">
                <option value="">Будь-який статус</option>
                <option value="NEW">Новий</option>
                <option value="draft">Чернетка</option>
                <option value="active">Активний</option>
                <option value="completed">Завершений</option>
                <option value="rejected">Відхилений</option>
                <option value="entered-in-error">Помилково введено</option>
            </select>
            <button
                wire:click.prevent="searchInEHealth"
                wire:loading.attr="disabled"
                type="button"
                class="button-primary flex items-center gap-2 px-4 py-2 text-sm shadow-sm"
            >
                <span wire:loading.remove wire:target="searchInEHealth">
                    @icon('search-outline', 'w-4 h-4')
                    Шукати в ЄСОЗ
                </span>
                <span wire:loading wire:target="searchInEHealth">
                    @icon('loader-outline', 'w-4 h-4 animate-spin')
                    Пошук...
                </span>
            </button>
            @if ($isSearchMode)
                <button
                    wire:click.prevent="resetSearch"
                    type="button"
                    class="button-primary-outline px-4 py-2 text-sm whitespace-nowrap"
                >
                    ← Локальні дані
                </button>
            @endif
        </div>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-4 w-full">

            {{-- eHealth search mode banner --}}
            @if ($isSearchMode)
                <div class="mb-4 flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-700 dark:bg-blue-900/20 dark:text-blue-300">
                    @icon('globe-outline', 'w-4 h-4 shrink-0')
                    <span>Результати пошуку в ЄСОЗ — натисніть «Зберегти до картки», щоб зберегти запис локально</span>
                </div>
            @endif

            {{-- Error banner --}}
            @if ($searchError)
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300">
                    {{ $searchError }}
                </div>
            @endif

            {{-- Flash messages --}}
            @if (session('success'))
                <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-700 dark:bg-green-900/20 dark:text-green-300">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300">
                    {{ session('error') }}
                </div>
            @endif

            {{-- Tabs --}}
            <div class="mb-6 flex border-b border-gray-200 dark:border-gray-700">
                <button
                    wire:click="switchTab('requests')"
                    type="button"
                    class="mr-1 rounded-t-lg border-b-2 px-5 py-2 text-sm font-medium transition-colors {{ $activeTab === 'requests' ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400' }}"
                >
                    @icon('document-text-outline', 'mr-1 inline w-4 h-4')
                    Е-рецепт запити ({{ count($medicationRequests) }})
                </button>
                <button
                    wire:click="switchTab('prescriptions')"
                    type="button"
                    class="rounded-t-lg border-b-2 px-5 py-2 text-sm font-medium transition-colors {{ $activeTab === 'prescriptions' ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400' }}"
                >
                    @icon('medical-outline', 'mr-1 inline w-4 h-4')
                    Рецепти (МР) ({{ count($prescriptions) }})
                </button>
            </div>

            {{-- ================================================================
                 TAB: eRx Requests (MedicationRequestRequests — our local drafts)
                 ================================================================ --}}
            @if ($activeTab === 'requests')
                @if (!$isSearchMode)
                    {{-- Local filters --}}
                    <div class="mb-6 flex flex-wrap items-end gap-3">
                        <div class="form-group group min-w-[10rem]">
                            <label class="label" for="filterStatus">Статус</label>
                            <select id="filterStatus" wire:model="filterStatus" class="input-select peer w-full">
                                <option value="">Всі</option>
                                <option value="NEW">Новий</option>
                                <option value="draft">Чернетка</option>
                                <option value="active">Активний</option>
                                <option value="completed">Завершений</option>
                                <option value="rejected">Відхилений</option>
                                <option value="entered-in-error">Помилково введено</option>
                            </select>
                        </div>
                        <div class="form-group group">
                            <label class="label" for="filterStartedAtFrom">Початок з</label>
                            <input id="filterStartedAtFrom" type="date" class="input peer" wire:model="filterStartedAtFrom" />
                        </div>
                        <div class="form-group group">
                            <label class="label" for="filterStartedAtTo">Початок до</label>
                            <input id="filterStartedAtTo" type="date" class="input peer" wire:model="filterStartedAtTo" />
                        </div>
                        <div class="form-group group">
                            <label class="label" for="filterEndedAtFrom">Закінчення з</label>
                            <input id="filterEndedAtFrom" type="date" class="input peer" wire:model="filterEndedAtFrom" />
                        </div>
                        <div class="form-group group">
                            <label class="label" for="filterEndedAtTo">Закінчення до</label>
                            <input id="filterEndedAtTo" type="date" class="input peer" wire:model="filterEndedAtTo" />
                        </div>
                        <button wire:click.prevent="applyFilters" type="button" class="button-primary px-4 py-2 text-sm">Фільтрувати</button>
                        <button wire:click.prevent="resetFilters" type="button" class="button-primary-outline px-4 py-2 text-sm">Скинути</button>
                    </div>
                @endif

                {{-- Table --}}
                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">Номер</th>
                                <th class="px-4 py-3 text-left font-medium">Статус</th>
                                <th class="px-4 py-3 text-left font-medium">Медикамент</th>
                                <th class="px-4 py-3 text-left font-medium">Кількість</th>
                                <th class="px-4 py-3 text-left font-medium">Період</th>
                                <th class="px-4 py-3 text-left font-medium">Програма</th>
                                <th class="px-4 py-3 text-left font-medium">Підстава</th>
                                @if ($isSearchMode)
                                    <th class="px-4 py-3 text-left font-medium">Дія</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @php $rows = $isSearchMode ? $eHealthResults : $medicationRequests @endphp
                            @forelse ($rows as $request)
                                <tr wire:key="mrr-{{ $request['id'] ?? $request['uuid'] ?? 'x' }}">
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-gray-900 dark:text-white">
                                            {{ $request['requestNumber'] ?? $request['request_number'] ?? '—' }}
                                        </div>
                                        <div class="mt-0.5 text-xs text-gray-400">{{ $request['categoryLabel'] ?? '' }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="badge {{ $request['statusBadge'] ?? 'badge-dark' }}">
                                            {{ $request['statusLabel'] ?? ($request['status'] ?? '—') }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-900 dark:text-gray-100">{{ $request['medicationName'] ?? '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $request['medicationQty'] ?? '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $request['periodLabel'] ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $request['programName'] ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $request['basisLabel'] ?? '—' }}</td>
                                    @if ($isSearchMode)
                                        <td class="px-4 py-3">
                                            <button
                                                wire:click="saveFromEHealth('{{ $request['id'] ?? $request['uuid'] ?? '' }}')"
                                                type="button"
                                                class="button-primary-outline px-3 py-1 text-xs"
                                            >
                                                Зберегти до картки
                                            </button>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                        {{ $isSearchMode ? 'Нічого не знайдено за параметрами пошуку.' : 'Рецепт-запити не знайдено у локальній базі даних.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- ================================================================
                 TAB: MedicationRequests (signed prescriptions from eHealth / cached)
                 ================================================================ --}}
            @if ($activeTab === 'prescriptions')
                @if (!$isSearchMode)
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                        Відображаються рецепти, які раніше були завантажені з ЄСОЗ.
                        Для пошуку нових — скористайтесь полем пошуку вгорі та натисніть «Шукати в ЄСОЗ».
                    </p>
                @endif

                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">Номер</th>
                                <th class="px-4 py-3 text-left font-medium">Статус</th>
                                <th class="px-4 py-3 text-left font-medium">Медикамент</th>
                                <th class="px-4 py-3 text-left font-medium">Кількість</th>
                                <th class="px-4 py-3 text-left font-medium">Період</th>
                                <th class="px-4 py-3 text-left font-medium">Програма</th>
                                @if ($isSearchMode)
                                    <th class="px-4 py-3 text-left font-medium">Дія</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @php $rows = $isSearchMode ? $eHealthResults : $prescriptions @endphp
                            @forelse ($rows as $prescription)
                                <tr wire:key="mr-{{ $prescription['id'] ?? $prescription['uuid'] ?? 'x' }}">
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-gray-900 dark:text-white">
                                            {{ $prescription['requestNumber'] ?? $prescription['request_number'] ?? '—' }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="badge {{ $prescription['statusBadge'] ?? 'badge-dark' }}">
                                            {{ $prescription['statusLabel'] ?? ($prescription['status'] ?? '—') }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-900 dark:text-gray-100">
                                        {{ $prescription['medicationName'] ?? data_get($prescription, 'medication_info.medication_name') ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $prescription['medicationQty'] ?? $prescription['medication_qty'] ?? '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $prescription['periodLabel'] ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $prescription['programName'] ?? data_get($prescription, 'medical_program.name') ?? '—' }}</td>
                                    @if ($isSearchMode)
                                        <td class="px-4 py-3">
                                            <button
                                                wire:click="saveFromEHealth('{{ $prescription['id'] ?? $prescription['uuid'] ?? '' }}')"
                                                type="button"
                                                class="button-primary-outline px-3 py-1 text-xs"
                                            >
                                                Зберегти до картки
                                            </button>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                        {{ $isSearchMode ? 'Нічого не знайдено за параметрами пошуку.' : 'Рецепти з ЄСОЗ ще не завантажені до картки пацієнта. Скористайтесь пошуком вгорі.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif

        </div>
    </div>
</x-layouts.patient>
