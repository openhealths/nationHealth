<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName" :declarationNumber="$declarationNumber">
    <div x-data="{ 
        activeSection: 'general',
        openSections: ['general', 'reasons', 'diagnoses', 'care_plans']
    }" class="form shift-content space-y-8 mt-6 pb-24">
        
        <div class="flex flex-col lg:flex-row gap-8">
            
            <!-- Main Content (Left Column) -->
            <div class="flex-1 space-y-6">
                <h1 class="text-2xl font-bold text-gray-900 mb-6">
                    Взаємодія — {{ $patientFullName }}
                </h1>

                <!-- Секція: Основні дані -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
                    <button @click="openSections.includes('general') ? openSections = openSections.filter(s => s !== 'general') : openSections.push('general')"
                            class="w-full px-6 py-4 flex items-center justify-between bg-white hover:bg-gray-50 transition-colors">
                        <div class="flex items-center gap-3">
                            @icon('info', 'w-5 h-5 text-gray-400')
                            <span class="font-bold text-gray-900">Основні дані</span>
                        </div>
                        <svg :class="openSections.includes('general') ? 'rotate-180' : ''" class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div x-show="openSections.includes('general')" x-collapse>
                        <div class="p-6 border-t border-gray-100 grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="record-inner-column">
                                <div class="record-inner-label">Дата початку</div>
                                <div class="record-inner-value">{{ $encounter->period?->start ? \Carbon\Carbon::parse($encounter->period->start)->format('d.m.Y H:i') : '-' }}</div>
                            </div>
                            <div class="record-inner-column">
                                <div class="record-inner-label">Тип (Клас)</div>
                                <div class="record-inner-value"><span class="badge badge-blue">{{ $encounter->class_display ?? 'Ambulatory' }}</span></div>
                            </div>
                            <div class="record-inner-column">
                                <div class="record-inner-label">Відповідальний лікар</div>
                                <div class="record-inner-value font-bold">{{ $encounter->performer?->full_name ?? 'Копилець Андрій Дмитрович' }}</div>
                            </div>
                            <div class="record-inner-column">
                                <div class="record-inner-label">Пріоритет</div>
                                <div class="record-inner-value">{{ $encounter->priority ?? 'Routine' }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Секція: Причини звернення -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
                    <button @click="openSections.includes('reasons') ? openSections = openSections.filter(s => s !== 'reasons') : openSections.push('reasons')"
                            class="w-full px-6 py-4 flex items-center justify-between bg-white hover:bg-gray-50 transition-colors">
                        <div class="flex items-center gap-3">
                            @icon('edit-user-outline', 'w-5 h-5 text-gray-400')
                            <span class="font-bold text-gray-900">Причини звернення</span>
                        </div>
                        <svg :class="openSections.includes('reasons') ? 'rotate-180' : ''" class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div x-show="openSections.includes('reasons')" x-collapse>
                        <div class="p-6 border-t border-gray-100 space-y-3">
                            @forelse($encounter->reasons as $reason)
                                <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                                    @icon('check-circle', 'w-4 h-4 text-green-500 mt-1')
                                    <span class="text-sm text-gray-700">{{ $reason->display ?? 'Скарги пацієнта' }}</span>
                                </div>
                            @empty
                                <div class="text-gray-400 italic">Скарги не вказані</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <!-- Секція: Діагнози -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
                    <button @click="openSections.includes('diagnoses') ? openSections = openSections.filter(s => s !== 'diagnoses') : openSections.push('diagnoses')"
                            class="w-full px-6 py-4 flex items-center justify-between bg-white hover:bg-gray-50 transition-colors">
                        <div class="flex items-center gap-3">
                            @icon('file-text', 'w-5 h-5 text-gray-400')
                            <span class="font-bold text-gray-900">Діагнози</span>
                        </div>
                        <svg :class="openSections.includes('diagnoses') ? 'rotate-180' : ''" class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div x-show="openSections.includes('diagnoses')" x-collapse>
                        <div class="p-0 border-t border-gray-100">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left font-semibold text-gray-500">Код</th>
                                        <th class="px-6 py-3 text-left font-semibold text-gray-500">Назва</th>
                                        <th class="px-6 py-3 text-center font-semibold text-gray-500">Роль</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @forelse($encounter->diagnoses as $diagnose)
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-4 font-bold text-blue-600">{{ $diagnose->condition->code ?? '-' }}</td>
                                            <td class="px-6 py-4 text-gray-900">{{ $diagnose->condition->display ?? '-' }}</td>
                                            <td class="px-6 py-4 text-center">
                                                <span class="badge {{ $diagnose->role === 'primary' ? 'badge-orange' : 'badge-dark' }}">
                                                    {{ $diagnose->role ?? 'основний' }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="px-6 py-8 text-center text-gray-400">Діагнози не вказані</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Секція: Статус ЕСОЗ -->
                <div class="mt-12 pt-8 border-t border-gray-200">
                    <h3 class="text-lg font-bold text-gray-900 mb-4">Статус ЕСОЗ</h3>
                    <div class="bg-white border border-gray-200 rounded-2xl p-6 flex items-center justify-between shadow-sm">
                        <div class="space-y-1">
                            <p class="text-xs text-gray-400 font-bold uppercase">Статус підписання</p>
                            <p class="text-lg font-medium text-gray-900">
                                {{ $encounter->status instanceof \UnitEnum ? $encounter->status->value : $encounter->status }}
                            </p>
                        </div>
                        <button class="button-primary px-8">Оновити</button>
                    </div>
                </div>

                <!-- Додаткові дії -->
                <div class="mt-12 space-y-6">
                    <h3 class="text-lg font-bold text-gray-900">Додаткові дії</h3>
                    
                    <div class="grid grid-cols-1 gap-4">
                        <!-- Направлення -->
                        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                            <p class="text-sm font-bold text-gray-900 mb-4">Направлення</p>
                            <a href="#" class="text-blue-600 font-medium text-sm hover:underline">+ Додати направлення</a>
                        </div>

                        <!-- Медичні висновки -->
                        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                            <p class="text-sm font-bold text-gray-900 mb-4">Медичні висновки</p>
                            <a href="#" class="text-blue-600 font-medium text-sm hover:underline">+ Додати медичний висновок</a>
                        </div>

                        <!-- Плани лікування -->
                        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                            <p class="text-sm font-bold text-gray-900 mb-4">Плани лікування</p>
                            <a href="{{ route('care-plan.create', [legalEntity(), $person->id, 'encounter' => $encounter->uuid]) }}" 
                               class="text-blue-600 font-medium text-sm hover:underline">
                                + Додати план лікування
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Кнопка скасування -->
                <div class="mt-12 flex justify-center">
                    <button class="px-6 py-2 border border-red-200 text-red-600 bg-red-50 rounded-lg text-sm font-medium hover:bg-red-100 transition-colors">
                        Взаємодія внесена помилково
                    </button>
                </div>
            </div>

            <!-- Right Navigation Sidebar -->
            <div class="w-full lg:w-64 space-y-2 sticky top-24 h-fit hidden lg:block">
                <nav class="space-y-1">
                    <a href="javascript:void(0)" @click="activeSection = 'general'" :class="activeSection === 'general' ? 'bg-gray-100 text-blue-600 font-bold' : 'text-gray-600 hover:bg-gray-50'" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm transition-colors">
                        @icon('info', 'w-5 h-5') Основні дані
                    </a>
                    <a href="javascript:void(0)" @click="activeSection = 'diagnoses'" :class="activeSection === 'diagnoses' ? 'bg-gray-100 text-blue-600 font-bold' : 'text-gray-600 hover:bg-gray-50'" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm transition-colors">
                        @icon('file-text', 'w-5 h-5') Діагнози
                    </a>
                    <a href="javascript:void(0)" @click="activeSection = 'reasons'" :class="activeSection === 'reasons' ? 'bg-gray-100 text-blue-600 font-bold' : 'text-gray-600 hover:bg-gray-50'" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm transition-colors">
                        @icon('edit-user-outline', 'w-5 h-5') Причини звернення
                    </a>
                    <a href="javascript:void(0)" class="flex items-center gap-3 px-4 py-3 text-gray-400 cursor-not-allowed rounded-lg text-sm">
                        @icon('check-circle', 'w-5 h-5') Дії
                    </a>
                    <a href="javascript:void(0)" class="flex items-center gap-3 px-4 py-3 text-gray-400 cursor-not-allowed rounded-lg text-sm">
                        @icon('eye', 'w-5 h-5') Обстеження
                    </a>
                    <a href="javascript:void(0)" class="flex items-center gap-3 px-4 py-3 text-gray-400 cursor-not-allowed rounded-lg text-sm">
                        @icon('archive', 'w-5 h-5') Вакцинації
                    </a>
                    <a href="javascript:void(0)" class="flex items-center gap-3 px-4 py-3 text-gray-400 cursor-not-allowed rounded-lg text-sm">
                        @icon('settings', 'w-5 h-5') Процедури
                    </a>
                    <hr class="my-4 border-gray-100">
                    <a href="javascript:void(0)" class="flex items-center gap-3 px-4 py-3 text-gray-600 hover:bg-gray-50 rounded-lg text-sm">
                        @icon('alert-circle', 'w-5 h-5') Направлення
                    </a>
                    <a href="javascript:void(0)" class="flex items-center gap-3 px-4 py-3 text-gray-600 hover:bg-gray-50 rounded-lg text-sm">
                        @icon('edit', 'w-5 h-5') Медичні висновки
                    </a>
                    <a href="javascript:void(0)" class="flex items-center gap-3 px-4 py-3 bg-blue-50 text-blue-700 font-bold rounded-lg text-sm">
                        @icon('file-text', 'w-5 h-5') Плани лікування
                    </a>
                </nav>
            </div>
        </div>
    </div>
</x-layouts.patient>
