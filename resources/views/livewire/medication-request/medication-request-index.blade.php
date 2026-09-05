<div>
    <livewire:components.x-message :listen-async="true" :key="'pharmacy-erx-flash'" />
    <x-forms.loading />

    <x-header-navigation class="items-start">
        <x-slot name="title">Електронні рецепти</x-slot>

        <x-slot name="navigation">
            <div class="-my-4 flex flex-col">
                <form wire:submit.prevent="search">
                    <div class="flex flex-col gap-2">
                        <label for="requestNumber" class="text-sm font-medium text-gray-900 dark:text-white">
                            Пошук за номером електронного рецепта
                        </label>
                        <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                            <div class="form-group group w-full sm:w-96">
                                <input
                                    type="text"
                                    id="requestNumber"
                                    placeholder=" "
                                    class="input peer"
                                    wire:model="requestNumber"
                                    x-data
                                    x-on:input="
                                        $event.target.value = $event.target.value
                                            .replace(/[^A-Za-z0-9]/g, '')
                                            .replace(/(.{4})(?! $)/g, '$1-')
                                            .toUpperCase()
                                            .slice(0, 19)
                                    "
                                    autocomplete="off"
                                />
                                <label for="requestNumber" class="label"> XXXX-XXXX-XXXX-XXXX </label>
                            </div>

                            <button
                                type="submit"
                                class="button-primary flex items-center justify-center gap-2 self-stretch whitespace-nowrap sm:self-auto"
                            >
                                @icon('search', 'w-4 h-4')
                                <span>Знайти рецепт</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </x-slot>
    </x-header-navigation>

    <div class="shift-content mt-8 flow-root pl-3.5">
        <div class="max-w-screen-xl space-y-6">
            @if ($errorMessage)
                <div
                    class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-gray-800 dark:text-red-400"
                    role="alert"
                >
                    {{ $errorMessage }}
                </div>
            @endif

            @if ($hasSearched && empty($errorMessage) && !empty($searchResults))
                <div class="space-y-4">
                    @foreach ($searchResults as $result)
                        @php
                            $resultId = (string) ($result['id'] ?? $result['uuid'] ?? '');
                            $isSelected = $selectedRequestId === $resultId;
                            $rawStatus = (string) ($result['status'] ?? '');
                            $status = strtoupper($rawStatus);
                            $number = $result['request_number'] ?? $requestNumber;
                            $medicationName = data_get($result, 'medication.name')
                                ?? data_get($result, 'medication_name')
                                ?? 'Лікарський засіб';
                            $programName = data_get($result, 'medical_program.name')
                                ?? data_get($result, 'medical_program_name')
                                ?? '—';
                            $statusLabels = [
                                'ACTIVE' => 'Активний',
                                'COMPLETED' => 'Погашено',
                                'USED' => 'Погашено',
                                'NEW' => 'Новий',
                                'DRAFT' => 'Чернетка',
                                'REJECTED' => 'Відхилено',
                                'CANCELLED' => 'Скасовано',
                                'CANCELED' => 'Скасовано',
                                'EXPIRED' => 'Протерміновано',
                                'ENTERED_IN_ERROR' => 'Введено помилково',
                                'PROCESSED' => 'В обробці',
                                'IN_PROGRESS' => 'В процесі',
                                'STOPPED' => 'Зупинено',
                                'SUSPENDED' => 'Призупинено',
                            ];
                            $statusBadgeClasses = [
                                'ACTIVE' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
                                'COMPLETED' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
                                'USED' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
                                'NEW' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
                                'DRAFT' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
                                'REJECTED' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
                                'CANCELLED' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                                'CANCELED' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                                'EXPIRED' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                                'ENTERED_IN_ERROR' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
                                'PROCESSED' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
                            ];
                            $statusLabel = $statusLabels[$status] ?? ($rawStatus !== '' ? $rawStatus : '—');
                            $badgeClass = $statusBadgeClasses[$status] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
                        @endphp
                        <div
                            wire:key="pharmacy-erx-{{ $resultId }}"
                            class="rounded-xl border p-5 shadow-sm {{ $isSelected ? 'border-blue-300 bg-blue-50 dark:border-blue-700 dark:bg-gray-800' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800' }}"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-lg font-bold text-gray-900 dark:text-white">№ {{ $number }}</p>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $medicationName }}</p>
                                    <p class="text-xs text-gray-500">Програма: {{ $programName }}</p>
                                </div>
                                <span class="badge {{ $badgeClass }}">
                                    {{ $statusLabel }}
                                </span>
                            </div>

                            @if (!$isSelected && $status === 'ACTIVE')
                                <button
                                    type="button"
                                    class="button-primary mt-4"
                                    wire:click="selectRequest('{{ $resultId }}')"
                                >
                                    Обрати для погашення
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @elseif ($hasSearched && empty($errorMessage))
                <p class="text-sm text-gray-500">Рецептів за цим номером не знайдено.</p>
            @else
                <p class="text-sm text-gray-500">
                    Введіть 16-значний номер рецепта з СМС або пам’ятки пацієнта. Погашення доступне лише для аптеки.
                </p>
            @endif

            @if ($this->selectedRequest())
                @php
                    $selRawStatus = (string) ($this->selectedRequest()['status'] ?? '');
                    $selStatus = strtoupper($selRawStatus);
                @endphp
                @if ($selStatus === 'COMPLETED' || $selStatus === 'USED')
                    <div class="rounded-xl border border-green-200 bg-green-50 p-6 shadow-sm dark:border-green-800 dark:bg-gray-800">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">
                                ✓
                            </span>
                            <div>
                                <h3 class="text-base font-semibold text-green-900 dark:text-green-200">Електронний рецепт успішно погашено в ЕСОЗ</h3>
                                <p class="text-sm text-green-700 dark:text-green-400">Статус рецепта в системі: Погашено (COMPLETED).</p>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Погашення рецепта</h3>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium">Код погашення з СМС *</label>
                                <input type="text" class="input peer w-full" wire:model="code" autocomplete="off" />
                                @error('code')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Кількість до відпуску *</label>
                                <input
                                    type="number"
                                    min="0.01"
                                    step="any"
                                    class="input peer w-full"
                                    wire:model="medicationQty"
                                />
                                @error('medicationQty')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        <div class="mt-6 flex justify-end">
                            <button type="button" class="button-primary" wire:click="openDispenseSignature">
                                Погасити рецепт
                            </button>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>

    <x-signature-modal method="sign" />
</div>
