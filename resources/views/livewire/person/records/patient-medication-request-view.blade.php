@php
    $dashboardUrl = legalEntity() ? route('dashboard', [legalEntity()]) : url('/dashboard');
    $patientUrl = route($prepersonId !== null ? 'prepersons.patient-data' : 'persons.patient-data', [legalEntity(), $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId]);
    $requestsUrl = route($prepersonId !== null ? 'prepersons.medication-requests' : 'persons.medication-requests', [legalEntity(), $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId]);
    
    $breadcrumbs = [
        ['label' => 'Головна', 'url' => $dashboardUrl],
        ['label' => 'Пацієнти', 'url' => route('persons.index', [legalEntity()])],
        ['label' => $patientFullName, 'url' => $patientUrl],
        ['label' => 'Рецепти', 'url' => $requestsUrl],
        ['label' => $requestId]
    ];
@endphp

<x-layouts.patient 
    :personId="$personId" 
    :prepersonId="$prepersonId" 
    :patientFullName="$patientFullName" 
    :hideNavigation="true" 
    :breadcrumbs="$breadcrumbs"
    title="Дротаверин 20 мг/мл, р-н для ін'єкцій"
>
    <x-slot name="headerActions"></x-slot>
    <div class="shift-content pl-3.5 mt-8 max-w-6xl">
        <fieldset class="fieldset">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-6">
                <!-- Ліва колонка -->
                <div class="flex flex-col">
                    <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mb-6">Загальна інформація про ЕР</div>
                    
                    <div class="form-row-1">
                        <div class="form-group group">
                            <input value="" type="text" class="input peer" disabled />
                            <label class="label">Програма</label>
                        </div>
                    </div>
                    
                    <div class="form-row-1 mt-4">
                        <div class="form-group group">
                            <input value="" type="text" class="input peer" disabled />
                            <label class="label">Номер рецепту</label>
                        </div>
                    </div>
                    
                    <div class="form-row-1 mt-4">
                        <div class="form-group group">
                            <input value="" type="text" class="input peer" disabled />
                            <label class="label">Статус</label>
                        </div>
                    </div>
                </div>

                <!-- Права колонка -->
                <div>
                    <fieldset class="border border-gray-400 dark:border-gray-600 rounded-2xl p-5 h-full">
                        <legend class="text-lg font-bold text-gray-800 dark:text-gray-200 px-3">Деталі програми</legend>
                        <div class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                            Джерело фінансування:<br>
                            Тип рецептурного бланка:<br>
                            Обов'язковість використання плану лікування для ЕР:<br>
                            Типи користувачів, яким дозволено виписувати ЕР:<br>
                            Перелік спеціальностей лікарів СМД та ПМД, яким дозволено виписувати ЕР/Призначення ПЛ:<br>
                            Можливість виписувати ЕР на такий самий МНН протягом курсу лікування:<br>
                            Максимальна тривалість курсу лікування на який може бути виписаний ЕР за програмою:<br>
                            Можливість виписувати ЕР незалежно від наявності укладеної декларації з пацієнтом:<br>
                            Можливість виписувати ЕР незалежно від наявності укладеної декларації в закладі, де виписується ЕР:<br>
                            Можливість часткового погашення ЕР:<br>
                            Сповіщення пацієнта при операціях з рецептом вимкнено:<br>
                            Категорії пацієнтів, яким дозволено створення призначення ПЛ:
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">Інформація щодо виписаного ЛЗ</div>
            <div class="form-row-2">
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Назва ЛЗ</label>
                </div>
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Складові лікарського засобу (МНН)</label>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Форма випуску ЛЗ</label>
                </div>
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Обсяг первинної упаковки виписаного ЛЗ</label>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Разова доза</label>
                </div>
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Добова доза</label>
                </div>
            </div>
            <div class="form-row-1 mb-4 mt-2">
                <div class="form-group group">
                    <label class="block mb-1 text-[10.5px] text-gray-400">Сигнатура*</label>
                    <textarea class="textarea resize-none" disabled rows="3"></textarea>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Кількість виписаного ЛЗ</label>
                </div>
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Кількість ЛЗ, що доступна до отримання в аптеці</label>
                </div>
            </div>

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">Строки дії ЕР</div>
            <div class="form-row-2">
                <div class="form-group datepicker-wrapper relative w-full">
                    <input value="" type="text" class="peer input pl-10 appearance-none" disabled />
                    <label class="wrapped-label">Дата створення ЕР</label>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group datepicker-wrapper relative w-full">
                    <input value="" type="text" class="peer input pl-10 appearance-none" disabled />
                    <label class="wrapped-label">Дата початку курсу лікування виписаним ЛЗ</label>
                </div>
                <div class="form-group datepicker-wrapper relative w-full">
                    <input value="" type="text" class="peer input pl-10 appearance-none" disabled />
                    <label class="wrapped-label">Дата завершення курсу лікування виписаним ЛЗ</label>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group datepicker-wrapper relative w-full">
                    <input value="" type="text" class="peer input pl-10 appearance-none" disabled />
                    <label class="wrapped-label">Дата першого дня, коли можливо отримати виписаний ЛЗ</label>
                </div>
                <div class="form-group datepicker-wrapper relative w-full">
                    <input value="" type="text" class="peer input pl-10 appearance-none" disabled />
                    <label class="wrapped-label">Дата останнього дня, коли можливо отримати виписаний ЛЗ</label>
                </div>
            </div>

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">Інформація про СГУСОЗ, в якому було виписано ЕР</div>
            <div class="form-row-2">
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Назва СГУСОЗ</label>
                </div>
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">код ЄДРПОУ або РНОКПП у разі ФОП</label>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Публічна назва</label>
                </div>
            </div>

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">Інформація про лікаря та пацієнта</div>
            <div class="form-row-2">
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">ПІБ лікаря</label>
                </div>
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Контактні дані лікаря</label>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">ПІБ пацієнта</label>
                </div>
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Кількість повних років пацієнта</label>
                </div>
            </div>

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">Пов'язані медичні записи</div>
            <div class="form-row-2">
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Id плану лікування, на основі якого створено ЕР</label>
                </div>
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Id взаємодії, в складі якої створено ЕР</label>
                </div>
            </div>

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">Дані про резервування ЕР</div>
            <div class="form-row-2">
                <div class="form-group datepicker-wrapper relative w-full">
                    <input value="" type="text" class="peer input pl-10 appearance-none" disabled />
                    <label class="wrapped-label">Термін резервування ЕР</label>
                </div>
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">АЗ, що зарезервував ЕР</label>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Причина резервування ЕР</label>
                </div>
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Опис причини резервування ЕР</label>
                </div>
            </div>

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">Відхилення ЕР</div>
            <div class="form-row-2">
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Причина відхилення ЕР</label>
                </div>
                <div class="form-group group">
                    <input value="" type="text" class="input peer" disabled />
                    <label class="label">Додаткові роз'яснення про причину відхилення ЕР</label>
                </div>
            </div>

            <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-10 mb-6">Дані про погашення ЕР</div>
            <div class="bg-white dark:bg-gray-800 relative shadow-md sm:rounded-lg overflow-x-auto">
                <table class="index-table">
                    <thead class="index-table-thead">
                        <tr>
                            <th scope="col" class="index-table-th">ДАТА ПОГАШЕННЯ ЕР</th>
                            <th scope="col" class="index-table-th">СТАТУС ПОГАШЕННЯ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="index-table-tr">
                            <td class="index-table-td-primary">�</td>
                            <td class="index-table-td">
                                <span class="badge-red">Прострочено</span>
                            </td>
                        </tr>
                        <tr class="index-table-tr">
                            <td class="index-table-td-primary">�</td>
                            <td class="index-table-td">
                                <span class="badge-green">Погашено</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
        </fieldset>

        <!-- Buttons row -->
        <div class="flex items-center gap-4 mt-12 pb-10">
            <a href="{{ $requestsUrl }}" class="button-minor px-6 py-2.5">Назад</a>
            <button type="button" class="button-primary-outline flex items-center gap-2 px-6 py-2.5">
                @icon('refresh', 'w-4 h-4') Перевірити погашення
            </button>
            <button type="button" class="button-primary-outline flex items-center gap-2 px-6 py-2.5">
                @icon('printer', 'w-4 h-4') Надрукувати пам'ятку
            </button>
            <button type="button" class="button-primary-outline flex items-center gap-2 px-6 py-2.5">
                @icon('message-circle', 'w-4 h-4') Надіслати СМС
            </button>
            <button type="button" class="button-primary-outline-red px-6 py-2.5">Відмінити рецепт</button>
        </div>
    </div>
</x-layouts.patient>
