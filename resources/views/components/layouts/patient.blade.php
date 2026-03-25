@use('App\Models\DeclarationRequest')
@use('App\Models\MedicalEvents\Sql\Encounter')

<section>
    <x-header-navigation x-data="{ showFilter: true }" class="breadcrumb-form">
        <x-slot name="title">{{ $patientFullName }}</x-slot>

        @if(isset($headerActions))
            {{ $headerActions }}
        @else
            @can('create', Encounter::class)
                <a href="{{ route('encounter.create', [legalEntity(), 'patientId' => $id]) }}"
                   class="flex items-center gap-2 button-primary px-5 py-2 text-sm shadow-sm"
                >
                    @icon('plus', 'w-4 h-4')
                    {{ __('patients.starts_interacting') }}
                </a>
            @endcan
        @endif

        <x-slot name="description">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm font-semibold rounded-lg mt-1 border border-gray-100 dark:border-gray-700">
                @icon('file-text', 'w-4 h-4 text-gray-400')
                Декларація №1000000000000
            </div>
        </x-slot>

        <x-slot name="navigation">

            @if(!request()->routeIs('persons.summary'))
                <nav x-data="{ currentPath: window.location.pathname }">
                    @php
                        $navItems = request()->routeIs('persons.summary')
                            ? [
                                'summary' => 'patients.summary',
                            ]
                            : [
                                'patient-data' => 'patients.patient_data',
                                'summary' => 'patients.summary',
                                'episodes' => 'patients.episodes',
                            ];
                    @endphp
                    {{-- Mobile version --}}
                    <div class="sm:hidden">
                        <label for="tabs" class="sr-only"></label>
                        <select id="tabs"
                                x-model="currentPath"
                                @change="window.location.href = $event.target.value"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                        >
                            @foreach($navItems as $route => $translation)
                                <option value="{{ route('persons.' . $route, [legalEntity(), 'id' => $id]) }}"
                                        :selected="currentPath.includes('{{ $route }}')"
                                >
                                    {{ __($translation) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Desktop version --}}
                    <ul class="hidden text-sm font-medium text-center text-gray-500 rounded-lg shadow-sm sm:flex dark:divide-gray-700 dark:text-gray-400">
                        @foreach($navItems as $route => $translation)
                            <li class="w-full focus-within:z-10">
                                <a href="{{ route('persons.' . $route, [legalEntity(), 'id' => $id]) }}"
                                   @click="currentPath = '{{ route('persons.' . $route, [legalEntity(), 'id' => $id]) }}'"
                                   class="inline-block w-full p-4 border-gray-200 dark:border-gray-700 focus:ring-4 focus:ring-blue-300 focus:outline-none"
                                   :class="currentPath.includes('{{ $route }}')
                                   ? 'text-gray-900 bg-gray-100 dark:bg-gray-700 dark:text-white'
                                   : 'bg-white hover:text-gray-700 hover:bg-gray-50 dark:hover:text-white dark:bg-gray-800 dark:hover:bg-gray-700'"
                                >
                                    {{ __($translation) }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </x-slot>
    </x-header-navigation>

    {{ $slot }}
    <livewire:components.x-message :key="time()" />
</section>
