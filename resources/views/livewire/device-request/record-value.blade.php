@if (is_array($value))
    @if (isset($value['start']))
        <span>{{ $value['start'] }} — {{ $value['end'] ?? __('care-plan.no_end_date') }}</span>
    @elseif (isset($value['display_value']))
        <span>{{ $value['display_value'] }}</span>
    @elseif (isset($value['coding']))
        @if (!empty($value['text']))
            <span>{{ $value['text'] }}</span>
        @endif
        @foreach ($value['coding'] as $coding)
            <span>{{ $coding['display'] ?? $coding['code'] ?? '' }} ({{ $coding['system'] ?? '' }})</span>
        @endforeach
    @elseif (isset($value['identifier']))
        <span>{{ data_get($value, 'identifier.value') }}</span>
    @elseif (isset($value['value']))
        <span>{{ $value['value'] }} {{ $value['unit'] ?? $value['code'] ?? '' }}</span>
    @else
        @foreach ($value as $key => $item)
            <div>
                @if (!is_int($key))
                    <span>{{ ['value_boolean' => 'Так / ні', 'value_string' => 'Значення', 'value_quantity' => 'Кількість', 'value_range' => 'Діапазон', 'value_codeable_concept' => 'Значення довідника', 'code' => 'Характеристика', 'low' => 'Мінімум', 'high' => 'Максимум', 'quantity' => 'Кількість', 'price' => 'Ціна', 'amount' => 'Сума', 'discount_amount' => 'Знижка', 'reimbursement_amount' => 'Відшкодування', 'device' => 'Виріб', 'model_number' => 'Модель', 'device_names' => 'Найменування', 'manufacturer' => 'Виробник', 'packaging' => 'Упаковка', 'packaging_count' => 'Виробів в упаковці', 'packaging_unit' => 'Одиниця упаковки', 'classification_types' => 'Класифікація', 'properties' => 'Властивості', 'first_name' => 'Ім’я', 'last_name' => 'Прізвище', 'second_name' => 'По батькові', 'birth_date' => 'Дата народження'][$key] ?? '' }}</span>
                @endif
                @include('livewire.device-request.record-value', ['value' => $item])
            </div>
        @endforeach
    @endif
@elseif (is_bool($value))
    {{ $value ? 'Так' : 'Ні' }}
@else
    {{ $value }}
@endif
