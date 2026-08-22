<div class="p-6 bg-white rounded-lg shadow space-y-6" x-data="{ timer: 0 }">
    <div class="border-b pb-4 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Робоче місце фармацевта (ЕСОЗ 3.5)</h1>
            <p class="text-sm text-gray-500">Погашення та облік електронних рецептів (ЛЗ) та е-запитів на медичні вироби</p>
        </div>
        <div class="flex space-x-2">
            <button wire:click="$set('searchType', 'medication')" class="px-4 py-2 text-sm font-semibold rounded {{ $searchType === 'medication' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">
                Е-Рецепти на ЛЗ (3.5.1)
            </button>
            <button wire:click="$set('searchType', 'device')" class="px-4 py-2 text-sm font-semibold rounded {{ $searchType === 'device' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">
                Медичні вироби (3.5.2)
            </button>
        </div>
    </div>

    {{-- Пошук --}}
    <div class="flex gap-4">
        <input type="text" wire:model="searchQuery" wire:keydown.enter="search" placeholder="Введіть 16-значний номер рецепта або запиту..." class="flex-1 border rounded px-4 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
        <button wire:click="search" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded font-medium">Знайти</button>
    </div>

    {{-- Помилки кваліфікації / блокування --}}
    @if ($qualificationError)
        <div class="p-4 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm">
            <p class="font-bold">Помилка відпуску:</p>
            <p>{{ $qualificationError }}</p>
        </div>
    @endif

    @if ($activeRequest && !$requestValidation['can_dispense'])
        <div class="p-4 bg-yellow-50 border-l-4 border-yellow-500 text-yellow-800 text-sm">
            <p class="font-bold">Неможливо погасити даний документ:</p>
            <p>{{ $requestValidation['reason'] }}</p>
        </div>
    @endif

    {{-- Карточка рецепта/запиту --}}
    @if ($activeRequest)
        <div class="bg-gray-50 p-4 rounded border space-y-4">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="text-gray-500 block">Номер:</span>
                    <span class="font-semibold">{{ $activeRequest['requisition_number'] ?? $activeRequest['requisition'] ?? $activeRequest['id'] }}</span>
                </div>
                <div>
                    <span class="text-gray-500 block">Статус:</span>
                    <span class="inline-block px-2 py-0.5 rounded text-xs font-bold bg-green-100 text-green-800">{{ $activeRequest['status'] }}</span>
                </div>
                <div>
                    <span class="text-gray-500 block">Пацієнт:</span>
                    <span class="font-medium">{{ $activeRequest['person']['short_name'] ?? $activeRequest['identity']['last_name'] ?? 'Пацієнт' }} (вік: {{ $activeRequest['person']['age'] ?? $activeRequest['identity']['age'] ?? '-' }})</span>
                </div>
                <div>
                    <span class="text-gray-500 block">Діє до:</span>
                    <span>{{ $activeRequest['dispensed_valid_to'] ?? $activeRequest['dispense_valid_to'] ?? '-' }}</span>
                </div>
            </div>

            {{-- Сигнатура (для ЛЗ) --}}
            @if(isset($activeRequest['dosage_instruction']['text']))
                <div class="bg-white p-3 rounded border text-sm">
                    <span class="text-gray-500 font-medium block">Сигнатура:</span>
                    <p class="text-gray-800">{{ $activeRequest['dosage_instruction']['text'] }}</p>
                </div>
            @endif

            {{-- Кнопки дій: Резервування / Відхилення --}}
            <div class="flex gap-2 pt-2 border-t">
                <button wire:click="openReservationModal" class="px-3 py-1.5 bg-yellow-500 hover:bg-yellow-600 text-white rounded text-sm font-medium">
                    Зарезервувати рецепт (3.5.1.6)
                </button>
                <button wire:click="$set('showRejectModal', true)" class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded text-sm font-medium">
                    Відхилити рецепт (3.5.1.5)
                </button>
            </div>
        </div>
    @endif

    {{-- Таблиця учасників / торгових назв (Participants) --}}
    @if (!empty($qualifiedParticipants))
        <div class="space-y-2">
            <h3 class="text-lg font-bold text-gray-800">Доступні торговельні найменування для відпуску</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-4 py-2 text-left">Торгова назва</th>
                            <th class="px-4 py-2 text-left">Виробник</th>
                            <th class="px-4 py-2 text-left">Реєстр відшкодування</th>
                            <th class="px-4 py-2 text-right">Сума відшкодування (грн)</th>
                            <th class="px-4 py-2 text-center">Дія</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($qualifiedParticipants as $idx => $item)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 font-medium">{{ $item['medication_name'] ?? $item['device_names']['name'] ?? '-' }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $item['manufacturer']['name'] ?? '-' }} ({{ $item['manufacturer']['country'] ?? '-' }})</td>
                                <td class="px-4 py-2">{{ $item['registry_number'] ?? 'не визначено' }}</td>
                                <td class="px-4 py-2 text-right font-semibold text-blue-600">{{ number_format($item['reimbursement_amount'] ?? $item['reimbursement']['reimbursement_amount'] ?? 0, 2) }}</td>
                                <td class="px-4 py-2 text-center">
                                    <button wire:click="selectParticipant({{ $idx }}, {{ $item['consumer_price'] ?? 100 }})" class="px-3 py-1 bg-green-600 hover:bg-green-700 text-white rounded text-xs font-semibold">
                                        Обрати
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Модальне вікно резервування з обов’язковим нормативним текстом --}}
    @if ($showReservationModal)
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
            <div class="bg-white rounded-lg p-6 max-w-lg w-full space-y-4">
                <h3 class="text-lg font-bold text-gray-900">Резервування рецепта</h3>
                <div class="p-3 bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 text-sm">
                    {{ $reservationWarningText }}
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Причина резервування:</label>
                    <select wire:model="blockReasonCode" class="w-full border rounded p-2 text-sm mt-1">
                        @foreach($blockReasons as $code => $name)
                            <option value="{{ $code }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex justify-end gap-2 pt-4">
                    <button wire:click="$set('showReservationModal', false)" class="px-4 py-2 bg-gray-200 rounded text-sm font-medium">Скасувати</button>
                    <button wire:click="confirmReservation" class="px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded text-sm font-medium">Підтвердити резервування</button>
                </div>
            </div>
        </div>
    @endif
</div>
