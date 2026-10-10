<div
    class="shift-content space-y-5 p-6"
    x-data
    @print-device-request.window="window.printSandboxedHtml($event.detail.html)"
>
    @assets
        <script src="{{ asset('js/print-sandboxed.js') }}"></script>
    @endassets
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <x-forms.loading />
    <h1 class="text-xl font-bold">
        Е-запити на медичні вироби
        @if ($person) —{{ $person->fullName }} @endif
    </h1>
    @if ($errors->any())
        <div role="alert" class="rounded bg-red-50 p-4 text-red-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif
    @if (!$person)
        <form wire:submit="searchPatients" class="flex gap-3">
            <label>Прізвище пацієнта<input wire:model="patientSearch" class="input" /></label
            ><button class="button-primary">Знайти пацієнта</button>
        </form>
        @foreach ($patients as $patient)
            <button wire:click="openPatient({{ $patient['id'] }})" class="button-minor">{{ $patient['name'] }}</button>
        @endforeach
    @else
        @can('device_request:write')
            <a
                class="button-primary inline-block"
                href="{{ route('device-requests.create', ['legalEntity' => $legalEntity, 'person' => $person]) }}"
            >Створити е-запит</a>
        @endcan
        @if ($drafts)
            <section>
                <h2 class="font-semibold">Мої збережені чернетки</h2>
                @foreach ($drafts as $draft)
                    <a
                        class="button-minor inline-block"
                        href="{{ route('device-requests.create', ['legalEntity' => $legalEntity, 'person' => $person, 'draft' => $draft['uuid']]) }}"
                    >{{ $draft['device'] }} · {{ $draft['updatedAt'] }} — відкрити та перевірити</a>
                @endforeach
            </section>
        @endif
        <form wire:submit="search" class="grid gap-4 md:grid-cols-3">
            @foreach (['requester_legal_entity' => 'Заклад, що створив запит', 'code' => 'Код медичного виробу', 'context_episode_id' => 'Епізод', 'encounter' => 'Взаємодія', 'program' => 'Медична програма'] as $key => $label)
                <label>{{ $label }}<input class="input w-full" wire:model="filters.{{ $key }}" /></label>
            @endforeach
            <label
                >Статус<select wire:model="filters.status" class="input-select w-full">
                    <option value="">Усі</option>
                    @foreach (['active' => 'Активний', 'completed' => 'Завершений', 'revoked' => 'Відкликаний', 'entered-in-error' => 'Внесений помилково'] as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach</select></label
            ><button class="button-primary">Пошук в ЕСОЗ</button>
        </form>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr>
                        <th>Номер</th>
                        <th>Виріб</th>
                        <th>Статус</th>
                        <th>Кількість</th>
                        <th>Програма</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requests as $record)
                        <tr wire:key="request-{{ $record['id'] }}">
                            <td>{{ $record['requisition'] ?? '—' }}</td>
                            <td>
                                {{ data_get($record, 'code_reference.display_value', data_get($record, 'code.coding.0.display', data_get($record, 'code.coding.0.code', '—'))) }}
                            </td>
                            <td>{{ $record['status'] }}</td>
                            <td>
                                {{ data_get($record, 'quantity.value', '—') }} {{ data_get($record, 'quantity.unit', data_get($record, 'quantity.code')) }}
                            </td>
                            <td>{{ data_get($record, 'program.display_value', '—') }}</td>
                            <td>
                                <button wire:click="showDetails('{{ $record['id'] }}')" class="button-minor">
                                    Деталі
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">Запитів за обраними параметрами не знайдено.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex gap-3">
            <button wire:click="goToPage({{ $page - 1 }})" class="button-minor" @disabled($page <= 1)>Назад</button
            ><span>{{ $page }} / {{ $totalPages }}</span
            ><button wire:click="goToPage({{ $page + 1 }})" class="button-minor" @disabled($page >= $totalPages)>
                Далі
            </button>
        </div>
        @if ($selected)
            <section class="space-y-4 rounded border p-4">
                <h2 class="text-lg font-semibold">Запит {{ $selected['requisition'] ?? '' }}</h2>
                @include('livewire.device-request.record-details', ['record' => $selected])
                @foreach ($catalog as $device)
                    <details class="rounded border p-3">
                        <summary>
                            Інформація з Переліку виробів: {{ data_get($device, 'device_names.0.name', $device['model_number'] ?? '') }}
                        </summary>
                        @include('livewire.device-request.record-value', ['value' => $device])
                    </details>
                @endforeach
                <div class="flex flex-wrap gap-3">
                    <button wire:click="printout" class="button-minor">Друк пам’ятки А5</button>
                    @if ($selected['status'] === 'active')
                        @can('device_request:revoke')
                            <button wire:click="startAction('revoke')" class="button-minor">Відкликати</button>
                        @endcan
                        @if (!isset($selected['quantity']))
                            @can('device_request:complete')
                                <button
                                    wire:click="complete"
                                    wire:confirm="Завершити активний запит без кількості?"
                                    class="button-minor"
                                >
                                    Завершити
                                </button>
                            @endcan
                        @endif
                    @endif
                    @if (!in_array($selected['status'], ['entered-in-error', 'entered_in_error']))
                        @can('device_request:mark_in_error')
                            <button wire:click="startAction('mark_in_error')" class="button-minor">
                                Позначити внесеним помилково
                            </button>
                        @endcan
                    @endif
                    @can('device_dispense:read')
                        <button wire:click="loadDispenses" class="button-minor">Відпуски за запитом</button>
                    @endcan
                </div>
                @if ($phone && $selected['status'] === 'active')
                    @can('device_request:resend')
                        <div>
                            <p>СМС можна повторно надіслати один раз. Номер: {{ $phone }}</p>
                            <label
                                ><input type="checkbox" wire:model="phoneConfirmed" /> Пацієнт підтвердив актуальність
                                номера</label
                            ><button
                                wire:click="resendSms"
                                wire:confirm="Повторно надіслати СМС пацієнту?"
                                class="button-minor"
                            >
                                Повторити СМС
                            </button>
                        </div>
                    @endcan
                @endif
                @if ($action)
                    <div class="space-y-3">
                        <label
                            >Причина<select wire:model="reason" class="input-select w-full">
                                <option value="">Оберіть причину</option>
                                @foreach ($reasons as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach</select></label
                        ><label>Пояснення<textarea wire:model="reasonText" class="input w-full"></textarea></label
                        ><button wire:click="openSignatureModal" class="button-primary">
                            Перевірити та підписати зміну
                        </button>
                    </div>
                @endif
                @foreach ($dispenses as $dispense)
                    <button wire:click="showDispense('{{ $dispense['id'] }}')" class="button-minor">
                        Відпуск {{ $dispense['id'] }} · {{ $dispense['status'] ?? '' }}
                    </button>
                @endforeach
                @if ($dispenseDetails)
                    <h3 class="font-semibold">Деталі відпуску</h3>
                    @include('livewire.device-request.record-details', ['record' => $dispenseDetails])
                @endif
            </section>
        @endif
    @endif
    <x-signature-modal method="sign" />
</div>
