<div class="shift-content space-y-5 p-6">
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <x-forms.loading />
    <h1 class="text-xl font-bold">Е-запит на медичні вироби — {{ $person->fullName }}</h1>
    @if ($carePlanUuid)
        <p>План лікування: {{ $carePlanUuid }} · Призначення: {{ $activityUuid }}</p>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded bg-red-50 p-4 text-red-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif
    <form wire:submit="createDraft" class="space-y-5">
        <div class="grid gap-4 md:grid-cols-2">
            <label
                >Взаємодія поточного лікаря, завершена сьогодні
                <select wire:model="request.encounter_uuid" class="input-select w-full">
                    <option value="">Оберіть взаємодію</option>
                    @foreach ($encounters as $encounter)
                        <option value="{{ $encounter['uuid'] }}">{{ $encounter['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label
                >Медична програма
                <select wire:model.live="request.program_id" class="input-select w-full" @disabled($activityUuid)>
                    <option value="">Без програми — допоміжні засоби реабілітації</option>
                    @foreach ($programs as $program)
                        <option value="{{ $program['id'] }}">{{ $program['name'] }}</option>
                    @endforeach
                </select>
            </label>
            <label
                >Вибір виробу
                <select wire:model.live="request.device_code_type" class="input-select w-full" @disabled($activityUuid)>
                    <option value="CLASSIFICATION_TYPE">За типом</option>
                    <option value="DEVICE_DEFINITION">За моделлю</option>
                </select>
            </label>
            @if (!$activityUuid && $request['device_code_type'] === 'CLASSIFICATION_TYPE')
                <label
                    >Тип медичного виробу
                    <select class="input-select w-full" wire:change="selectType($event.target.value)">
                        <option value="">Оберіть тип</option>
                        @foreach ($types as $key => $type)
                            <option
                                value="{{ $key }}"
                                @selected($request['device_code_system'].'|'.$request['device_id'] === $key)
                            >
                                {{ $type['label'] }} · {{ $type['code'] }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @elseif (!$activityUuid)
                <div>
                    <label>Назва моделі<input wire:model="modelSearch" class="input w-full" /></label
                    ><button type="button" wire:click="searchModels" class="button-minor">Знайти моделі</button>
                    <select class="input-select w-full" wire:change="selectModel($event.target.value)">
                        <option value="">Оберіть модель</option>
                        @foreach ($models as $model)
                            <option value="{{ $model['id'] }}" @selected($request['device_id'] === $model['id'])>
                                {{ data_get($model, 'device_names.0.name', $model['model_number'] ?? $model['id']) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @else
                <p>Виріб за призначенням: {{ $request['device_id'] }}</p>
            @endif
            @if (!empty($options['model']))
                <p>
                    Обрана модель: {{ data_get($options, 'model.device_names.0.name', data_get($options, 'model.model_number', $request['device_id'])) }}
                </p>
            @endif
            <label
                >Початок періоду<input type="date" wire:model.live="request.started_at" class="input w-full"
            /></label>
            <label>Кінець періоду<input type="date" wire:model.live="request.ended_at" class="input w-full" /></label>
            @if ($request['program_id'])
                <div>
                    <label
                        >Кількість в одиницях упаковки
                        <select class="input-select w-full" wire:change="selectQuantity($event.target.value)">
                            <option value="">Оберіть кількість</option>
                            @foreach ($quantityOptions as $key => $quantity)
                                <option
                                    value="{{ $key }}"
                                    @selected($request['quantity_code'].'|'.$request['quantity'] === $key)
                                >
                                    {{ $quantity['quantity'] }} {{ $quantity['unit'] }}
                                </option>
                            @endforeach</select
                    ></label>
                    <button type="button" class="button-minor" wire:click="moreQuantities">
                        Показати більші кількості
                    </button>
                    <p>Доступні лише цілі упаковки в межах залишку та обмежень програми.</p>
                </div>
            @else
                <label
                    >Кількість (необов’язково)<input
                        type="number"
                        min="1"
                        step="1"
                        wire:model="request.quantity"
                        class="input w-full"
                /></label>
                <label>Одиниця виміру<input wire:model="request.quantity_code" class="input w-full" /></label>
            @endif
            <label
                >Метод автентифікації пацієнта
                <select wire:model.live="request.inform_with" class="input-select w-full">
                    <option value="">Метод за замовчуванням</option>
                    @foreach ($authMethods as $method)
                        <option value="{{ $method['id'] }}">
                            {{ $method['type'] }} {{ $method['phone_number'] ?? '' }}
                        </option>
                    @endforeach
                </select>
            </label>
            <div>
                @php
                    $chosenMethod = collect($authMethods)->firstWhere('id', $request['inform_with']);
                    if (!$request['inform_with']) {
                        $chosenMethod = collect($authMethods)->firstWhere('type', 'OTP') ?? ($authMethods[0] ?? []);
                    }
                @endphp
                @if (!empty($chosenMethod['phone_number']))
                    <p>Номер для автентифікації: {{ $chosenMethod['phone_number'] }}</p>
                @endif
                <p>Уточніть у пацієнта, чи доступний обраний номер телефону для отримання СМС.</p>
                <button type="button" wire:click="confirmPhone" class="button-minor">
                    Пацієнт підтвердив актуальність номера
                </button>
                @if ($request['phone_confirmed'])
                    <p>Актуальність номера підтверджено.</p>
                @endif
            </div>
        </div>
        <fieldset class="space-y-3">
            <legend class="font-semibold">Причини призначення</legend>
            @foreach ($request['reason_reference'] as $index => $reason)
                <div wire:key="reason-{{ $index }}" class="flex gap-3">
                    <select wire:model="request.reason_reference.{{ $index }}.type" class="input-select">
                        <option value="condition">Стан / діагноз</option>
                        <option value="observation">Спостереження</option>
                        <option value="diagnostic_report">Діагностичний звіт</option></select
                    ><input
                        wire:model="request.reason_reference.{{ $index }}.uuid"
                        class="input flex-1"
                        aria-label="Ідентифікатор причини"
                        placeholder="Ідентифікатор запису ЕСОЗ"
                    /><button type="button" wire:click="removeReason({{ $index }})" class="button-minor">
                        Видалити
                    </button>
                </div>
            @endforeach
            <button type="button" wire:click="addReason" class="button-minor">Додати причину</button>
        </fieldset>
        <fieldset class="grid gap-4 md:grid-cols-2">
            <legend class="font-semibold">Характеристики виробу</legend>
            @foreach ($options['parameters'] ?? [] as $key => $parameter)
                <label wire:key="parameter-{{ $key }}"
                    >{{ $parameter['label'] ?? $parameter['code'] }} {{ $parameter['required'] ? '*' : '' }}
                    @if ($parameter['type'] === 'boolean')
                        <select wire:model="request.parameter_values.{{ $key }}" class="input-select w-full">
                            <option value="">Не задано</option>
                            <option value="1">Так</option>
                            <option value="0">Ні</option>
                        </select>
                    @elseif ($parameter['type'] === 'codeable_concept')
                        <select wire:model="request.parameter_values.{{ $key }}" class="input-select w-full">
                            <option value="">Оберіть значення</option>
                            @foreach ($parameter['values'] as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @elseif ($parameter['type'] === 'quantity')
                        <input
                            type="number"
                            step="any"
                            wire:model="request.parameter_values.{{ $key }}.value"
                            class="input w-full"
                            aria-label="Значення"

                        /><input
                            wire:model="request.parameter_values.{{ $key }}.code"
                            class="input w-full"
                            placeholder="Одиниця виміру"

                        /><input
                            wire:model="request.parameter_values.{{ $key }}.system"
                            class="input w-full"
                            placeholder="Довідник одиниць"
                        />
                    @elseif ($parameter['type'] === 'range')
                        @foreach (['low' => 'Мінімум', 'high' => 'Максимум'] as $bound => $label)
                            <span>{{ $label }}</span
                            ><input
                                type="number"
                                step="any"
                                wire:model="request.parameter_values.{{ $key }}.{{ $bound }}.value"
                                class="input w-full"

                            /><input
                                wire:model="request.parameter_values.{{ $key }}.{{ $bound }}.code"
                                class="input w-full"
                                placeholder="Одиниця виміру"

                            /><input
                                wire:model="request.parameter_values.{{ $key }}.{{ $bound }}.system"
                                class="input w-full"
                                placeholder="Довідник одиниць"
                            />
                        @endforeach
                    @else
                        <input wire:model="request.parameter_values.{{ $key }}" class="input w-full" />
                    @endif
                </label>
            @endforeach
        </fieldset>
        <p>
            Перед підписанням перевірте пацієнта, взаємодію, виріб, програму, період, кількість, причини та
            характеристики.
        </p>
        <div class="flex gap-3">
            <button type="submit" class="button-primary" wire:loading.attr="disabled">
                Перевірити та зберегти чернетку</button
            ><button type="button" wire:click="openSignatureModal" class="button-primary" wire:loading.attr="disabled">
                Підписати КЕП та створити</button
            ><a
                class="button-minor"
                href="{{ route('device-requests.index', ['legalEntity' => $legalEntity, 'person' => $person]) }}"
                >До реєстру</a>
        </div>
    </form>
    <x-signature-modal method="sign" />
</div>
