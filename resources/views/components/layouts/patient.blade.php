@use('App\Models\DeclarationRequest')
@use('App\Models\MedicalEvents\Sql\Encounter')

<section>
    <x-header-navigation x-data="{ showFilter: true }" class="breadcrumb-form">
        <x-slot name="title">{{ $patientFullName }}</x-slot>

        <x-slot name="navigation">
            <div class="sm:flex md:divide-x md:divide-gray-100 dark:divide-gray-700 mb-8 gap-2">
                @can('create', Encounter::class)
                    <a href="{{ route('encounter.create', [legalEntity(), 'patientId' => $id]) }}"
                       class="flex items-center gap-2 button-sync"
                    >
                        @icon('plus', 'w-4 h-4')
                        {{ __('patients.start_interacting') }}
                    </a>
                @endcan
                @can('create', DeclarationRequest::class)
                    <a href="{{ route('declaration.create', [legalEntity(), 'patientId' => $id]) }}"
                       class="flex items-center gap-2 button-minor"
                    >
                        @icon('file-text', 'w-4 h-4')
                        {{ __('patients.sign_declaration') }}
                    </a>
                @endcan
            </div>

            <nav x-data="{ currentPath: window.location.pathname }">
                {{-- Mobile version --}}
                <div class="sm:hidden">
                    <label for="tabs" class="sr-only"></label>
                    <select id="tabs"
                            x-model="currentPath"
                            @change="window.location.href = $event.target.value"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                    >
                        @php
                            $navItems = [
                                'patient-data' => 'patients.patient_data',
                                'summary' => 'patients.summary',
                                'episodes' => 'patients.episodes'
                            ];
                        @endphp

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
        </x-slot>
    </x-header-navigation>

    {{ $slot }}
    <livewire:components.x-message :key="time()" />
</section>
