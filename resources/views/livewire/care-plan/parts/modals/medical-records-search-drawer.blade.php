{{-- Medical Records Search Drawer Overlay --}}
<div x-show="showMedicalRecordsSearchDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     x-cloak
     @click="showMedicalRecordsSearchDrawer = false"
     class="fixed top-20 right-0 bg-gray-900/40"
     style="z-index: 54; width: 80%; height: calc(100vh - 80px);"
></div>

{{-- Medical Records Search Drawer (15% gap on the LEFT of the first drawer) --}}
<div id="medical-records-search-drawer-right"
     x-show="showMedicalRecordsSearchDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="translate-x-full"
     x-cloak
     class="fixed top-0 right-0 h-screen pt-20 p-8 overflow-y-auto bg-white dark:bg-gray-800 shadow-2xl"
     style="z-index: 55; width: calc(80% - 15%);"
     tabindex="-1"
     x-data="{
         searchQuery: '',
         selectedType: 'Condition',
         records: [
             @foreach(($availableConditions ?? []) as $item)
             {
                 uuid: '{{ $item['uuid'] }}',
                 type: 'Condition',
                 typeName: 'Стан/діагноз',
                 name: '{{ addslashes($item['name']) }}',
                 date: '{{ $item['date'] }}'
             },
             @endforeach
             @foreach(($availableObservations ?? []) as $item)
             {
                 uuid: '{{ $item['uuid'] }}',
                 type: 'Observation',
                 typeName: 'Спостереження',
                 name: '{{ addslashes($item['name']) }}',
                 date: '{{ $item['date'] }}'
             },
             @endforeach
             @foreach(($availableReports ?? []) as $item)
             {
                 uuid: '{{ $item['uuid'] }}',
                 type: 'DiagnosticReport',
                 typeName: 'Діагностичний звіт',
                 name: '{{ addslashes($item['name']) }}',
                 date: '{{ $item['date'] }}'
             },
             @endforeach
         ],
         get filteredRecords() {
             return this.records.filter(r => {
                 const matchesType = !this.selectedType || r.type === this.selectedType;
                 const matchesQuery = !this.searchQuery || r.name.toLowerCase().includes(this.searchQuery.toLowerCase());
                 return matchesType && matchesQuery;
             });
         }
     }"
>
    {{-- Header --}}
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white" id="medical-records-search-drawer-label">
            Пошук медичних записів
        </h2>
    </div>

    {{-- Search Fields Box --}}
    <fieldset class="fieldset bg-white dark:bg-gray-800 !rounded-xl !border-gray-100 dark:!border-gray-700 !max-w-full !p-6 !mb-6 shadow-sm">
        <legend class="legend">Пошук медичних записів</legend>

        <div class="space-y-6">
            {{-- Search icon + text header --}}
            <div class="flex items-center gap-2 text-gray-700 dark:text-gray-300 font-medium">
                @icon('search-outline', 'w-5 h-5 text-gray-400')
                <span>Пошук</span>
            </div>

            {{-- Filters row --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- Type select --}}
                <div class="form-group group">
                    <label class="label">Тип медичних записів</label>
                    <select x-model="selectedType" class="input-select peer w-full">
                        <option value="">Всі типи</option>
                        <option value="Condition">Стани/діагнози</option>
                        <option value="Observation">Спостереження</option>
                        <option value="DiagnosticReport">Діагностичні звіти</option>
                    </select>
                </div>

                {{-- Episode placeholder --}}
                <div class="form-group group">
                    <label class="label">Епізод</label>
                    <select class="input-select peer w-full">
                        <option value="">Оберіть епізод...</option>
                        <option value="1">Підтримання здоров'я/профілактика (діючий) від 8.05.2025</option>
                    </select>
                </div>
            </div>
        </div>
    </fieldset>

    {{-- Results Table --}}
    <div class="overflow-x-auto rounded-lg border border-gray-100 dark:border-gray-700 mb-6">
        <table class="w-full text-sm text-left">
            <thead class="thead-input">
                <tr>
                    <th scope="col" class="px-4 py-3 text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">ДАТА</th>
                    <th scope="col" class="px-4 py-3 text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">ТИП</th>
                    <th scope="col" class="px-4 py-3 text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">НАЗВА</th>
                    <th scope="col" class="px-4 py-3 text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider text-right">ДІЯ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                <template x-for="item in filteredRecords" :key="item.uuid">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-4 py-4 text-gray-500 dark:text-gray-400 whitespace-nowrap" x-text="item.date"></td>
                        <td class="px-4 py-4 text-gray-500 dark:text-gray-400" x-text="item.typeName"></td>
                        <td class="px-4 py-4 font-medium text-gray-900 dark:text-white" x-text="item.name"></td>
                        <td class="px-4 py-4 text-right">
                            <button type="button" 
                                    @click="$wire.addLinkedGround(item.type, item.uuid); showMedicalRecordsSearchDrawer = false;" 
                                    class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-medium inline-flex items-center justify-center p-1 rounded-full border border-blue-600 hover:bg-blue-50 transition-colors"
                            >
                                <svg class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                            </button>
                        </td>
                    </tr>
                </template>
                <template x-if="filteredRecords.length === 0">
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-gray-400 italic">
                            Нічого не знайдено за обраними фільтрами
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    {{-- Footer --}}
    <div class="mt-8 pt-6 border-t border-gray-100 dark:border-gray-700">
        <button type="button"
                class="button-minor"
                @click="showMedicalRecordsSearchDrawer = false"
        >
            Скасувати
        </button>
    </div>
</div>
