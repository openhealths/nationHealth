<dl class="grid gap-3 md:grid-cols-2">
    @if (isset($record['quantity']))
        <div>
            <dt class="font-semibold">Одиниця виміру</dt>
            <dd>{{ data_get($record, 'quantity.unit', data_get($record, 'quantity.code', '')) }}</dd>
        </div>
    @endif
    @foreach (['status' => 'Статус', 'intent' => 'Намір', 'authored_on' => 'Дата створення', 'occurrence_period.start' => 'Початок періоду', 'occurrence_period.end' => 'Кінець періоду', 'dispense_valid_to' => 'Отримати до', 'requester_legal_entity.display_value' => 'Заклад', 'requester.display_value' => 'Лікар', 'subject.display_value' => 'Пацієнт', 'program.display_value' => 'Медична програма', 'quantity.value' => 'Кількість', 'quantity.unit' => 'Одиниця', 'note' => 'Примітка', 'status_reason.text' => 'Пояснення зміни статусу', 'when_handed_over' => 'Дата відпуску', 'performer.display_value' => 'Виконавець', 'location.display_value' => 'Місце відпуску'] as $path => $label)
        @if (data_get($record, $path) !== null)
            <div>
                <dt class="font-semibold">{{ $label }}</dt>
                <dd>{{ data_get($record, $path) }}</dd>
            </div>
        @endif
    @endforeach
    @foreach (['code' => 'Тип виробу', 'code_reference' => 'Модель виробу', 'status_reason' => 'Причина зміни статусу', 'reason' => 'Причини призначення', 'parameter' => 'Характеристики', 'based_on' => 'Пов’язані записи', 'device' => 'Виріб', 'details' => 'Вироби та вартість відпуску', 'performer_legal_entity' => 'Заклад відпуску', 'encounter' => 'Взаємодія', 'subject' => 'Пацієнт', 'supporting_info' => 'Додаткові записи'] as $key => $label)
        @if (!empty($record[$key]))
            <div>
                <dt class="font-semibold">{{ $label }}</dt>
                <dd>@include('livewire.device-request.record-value', ['value' => $record[$key]])</dd>
            </div>
        @endif
    @endforeach
</dl>
