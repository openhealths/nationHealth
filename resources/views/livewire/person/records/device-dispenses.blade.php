<x-layouts.patient
    :showLegacyMessages="false"
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    activeTab="device-dispenses"
>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <div class="shift-content space-y-5 p-6">
        <h1 class="text-xl font-bold">Відпуски медичних виробів</h1>
        @if ($errors->any())
            <div role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
        <form wire:submit="search" class="grid gap-3 md:grid-cols-3">
            @foreach (['based_on' => 'Е-запит', 'encounter' => 'Взаємодія', 'context_episode_id' => 'Епізод', 'performer_legal_entity' => 'Заклад', 'performer' => 'Виконавець', 'device_code' => 'Код виробу', 'device' => 'Модель виробу', 'program' => 'Програма'] as $key => $label)
                <label>{{ $label }}<input wire:model="filters.{{ $key }}" class="input w-full" /></label>
            @endforeach
            <label
                >Статус<select wire:model="filters.status" class="input-select w-full">
                    <option value="">Усі</option>
                    @foreach (['active' => 'Активний', 'in_progress' => 'В роботі', 'completed' => 'Завершений', 'stopped' => 'Зупинений', 'entered-in-error' => 'Внесений помилково'] as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach</select
            ></label>
            <label
                >Дата відпуску від<input
                    type="date"
                    wire:model="filters.when_handed_over_from"
                    class="input w-full" /></label
            ><label
                >Дата відпуску до<input type="date" wire:model="filters.when_handed_over_to" class="input w-full"
            /></label>
            <button class="button-primary">Знайти в ЕСОЗ</button
            ><button type="button" wire:click="resetFilters" class="button-minor">Скинути</button>
        </form>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr>
                        <th>Ідентифікатор</th>
                        <th>Статус</th>
                        <th>Дата відпуску</th>
                        <th>Заклад</th>
                        <th>Виконавець</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($dispenses as $record)
                        <tr wire:key="dispense-{{ $record['id'] }}">
                            <td>{{ $record['id'] }}</td>
                            <td>{{ $record['status'] }}</td>
                            <td>{{ $record['when_handed_over'] ?? '' }}</td>
                            <td>{{ data_get($record, 'performer_legal_entity.display_value') }}</td>
                            <td>{{ data_get($record, 'performer.display_value') }}</td>
                            <td>
                                <button wire:click="showDetails('{{ $record['id'] }}')" class="button-minor">
                                    Деталі
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">Відпусків за цими параметрами не знайдено.</td>
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
        @if ($details)
            <section class="rounded border p-4">
                <h2 class="text-lg font-bold">Деталі відпуску</h2>
                @include('livewire.device-request.record-details', ['record' => $details])
                @foreach ($details['details'] ?? [] as $item)
                    <div class="border-t p-3">
                        @include('livewire.device-request.record-details', ['record' => $item])
                        <p>Вартість: {{ $item['sell_price'] ?? '' }} · Знижка: {{ $item['discount_amount'] ?? '' }}</p>
                    </div>
                @endforeach
            </section>
        @endif
    </div>
</x-layouts.patient>
