@php
    use App\Models\{Contracts\Contract,Contracts\ContractRequest,Declaration,DeclarationRequest,Division,Equipment,HealthcareService,LegalEntity,License};
    use App\Models\Employee\{Employee,EmployeeRequest};
    use App\Models\Person\{Person,PersonRequest};
@endphp

<aside id="drawer-navigation"
       class="fixed top-0 left-0 z-40 w-64 h-screen pt-14 transition-transform -translate-x-full bg-white border-r border-gray-200 md:translate-x-0 dark:bg-gray-800 dark:border-gray-700"
       aria-label="Sidebar"
>

    <div class="overflow-y-auto py-5 px-3 h-full bg-white dark:bg-gray-800">
        <ul class="space-y-2">

            @if(Auth::user()->can('create', LegalEntity::class) || Auth::user()->can('limitedAction', LegalEntity::class)  || legalEntity())
                <li x-data="{ open: false }" class="space-y-2">
                    <button @click="open = !open"
                            type="button"
                            class="menu-item"
                            aria-controls="dropdown-legal-entity"
                            :aria-expanded="open"
                    >
                        @icon('institution')
                        <span>{{ __('forms.institution') }}</span>

                        <svg fill="currentColor" viewBox="0 0 20 20"
                             xmlns="http://www.w3.org/2000/svg"
                             :class="{ 'rotate-180': open, 'rotate-0': !open }"
                        >
                            <path fill-rule="evenodd"
                                  d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                  clip-rule="evenodd"
                            ></path>
                        </svg>
                    </button>

                    <ul id="dropdown-legal-entity"
                        x-cloak
                        class="py-2 space-y-2"
                        x-show="open"
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="transform opacity-0 scale-95"
                        x-transition:enter-end="transform opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="transform opacity-100 scale-100"
                        x-transition:leave-end="transform opacity-0 scale-95"
                    >
                        @if(legalEntity())
                            @can('access', legalEntity())
                                <li>
                                    <a href="{{ route('legal-entity.details', [legalEntity()]) }}"
                                       class="submenu-item"
                                    >
                                        @icon('details')
                                        <span>{{ __('forms.details') }}</span>
                                    </a>
                                </li>
                            @endcan
                        @endif

                        @if (legalEntity()?->type->name !== LegalEntity::TYPE_MSP_LIMITED)
                            @if(legalEntity())
                                @can('edit', [LegalEntity::class, legalEntity()])
                                    <li>
                                        <a href="{{ route('legal-entity.edit', [legalEntity()]) }}"
                                           class="submenu-item"
                                        >
                                            @icon('edit2')
                                            <span>{{ __('forms.edit') }}</span>
                                        </a>
                                    </li>
                                @endcan
                            @endif

                            @canany(['create', 'limitedAction'], LegalEntity::class)
                                <li>
                                    <a href="{{ legalEntity()
                                        ? route('legal-entity.create', [legalEntity()->id])
                                        : route('legal-entity.new.create') }}"
                                       class="submenu-item"
                                    >
                                        @icon('create')
                                        <span>{{ __('forms.create_legal_entity') }}</span>
                                    </a>
                                </li>
                            @endcanany
                        @endif
                    </ul>
                </li>
            @endif

            @if(legalEntity())
                @can('viewAny', Division::class)
                    <li>
                        <a href="{{ route('division.index', [legalEntity()]) }}"
                           class="menu-item-simple"
                        >
                            @icon('divisions')
                            <span>{{ __('forms.divisions') }}</span>
                        </a>
                    </li>
                @endcan

                @can('viewAny', HealthcareService::class)
                    <li>
                        <a href="{{ route('healthcare-service.index', [legalEntity()]) }}"
                           class="menu-item-simple"
                        >
                            @icon('settings')
                            <span>{{ __('forms.services') }}</span>
                        </a>
                    </li>
                @endcan

                @if(Auth::user()->can('viewAny', Employee::class) || Auth::user()->can('viewAny', EmployeeRequest::class))
                    <li x-data="{ open: {{ (request()->routeIs('employee.*') || request()->routeIs('party.verification.*')) ? 'true' : 'false' }} }"
                        class="space-y-2">
                        <button @click="open = !open"
                                type="button"
                                class="menu-item"
                                aria-controls="dropdown-employees"
                                :aria-expanded="open"
                        >
                            @icon('employees')
                            <span>{{ __('forms.employees') }}</span>

                            <svg fill="currentColor" viewBox="0 0 20 20"
                                 xmlns="http://www.w3.org/2000/svg"
                                 :class="{ 'rotate-180': open, 'rotate-0': !open }"
                            >
                                <path fill-rule="evenodd"
                                      d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                      clip-rule="evenodd"
                                ></path>
                            </svg>
                        </button>

                        <ul id="dropdown-employees"
                            x-cloak
                            class="py-2 space-y-2"
                            x-show="open"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="transform opacity-0 scale-95"
                            x-transition:enter-end="transform opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="transform opacity-100 scale-100"
                            x-transition:leave-end="transform opacity-0 scale-95"
                        >
                            <li>
                                <a href="{{ route('employee.index', [legalEntity()]) }}"
                                   class="submenu-item"
                                >
                                    @icon('positions')
                                    <span>{{ __('forms.positions') }}</span>
                                </a>
                            </li>

                            {{-- Register of applications --}}
                            @can('viewAny', EmployeeRequest::class)
                                <li>
                                    <a href="{{ route('employee-request.index', [legalEntity()]) }}"
                                       class="submenu-item"
                                    >
                                        @icon('pencil-clipboard',)
                                        <span class="ml-3">Реєстр заявок</span>
                                    </a>
                                </li>
                            @endcan

                            <li>
                                <a href="{{ route('employee-role.index', [legalEntity()]) }}"
                                   class="submenu-item"
                                >
                                    @icon('users-roles')
                                    <span class="ml-3">{{ __('employee-roles.label') }}</span>
                                </a>
                            </li>

                            <li>
                                <a href="{{ route('party.verification.index', [legalEntity()]) }}"
                                   class="submenu-item"
                                >
                                    @icon('verifications')
                                    <span>{{ __('forms.verifications') }}</span>
                                </a>
                            </li>
                        </ul>
                    </li>
                @endif

                {{-- Section of Contracts (Dropdown) --}}
                @if(Auth::user()->can('viewAny', Contract::class) || Auth::user()->can('viewAny', ContractRequest::class))
                    <li x-data="{ open: {{ request()->routeIs('contract*') ? 'true' : 'false' }} }"
                        class="space-y-2">
                        <button @click="open = !open"
                                type="button"
                                class="menu-item"
                                aria-controls="dropdown-contracts"
                                :aria-expanded="open"
                        >
                            @icon('contracts')
                            <span>{{ __('Договори') }}</span>

                            <svg fill="currentColor" viewBox="0 0 20 20"
                                 xmlns="http://www.w3.org/2000/svg"
                                 :class="{ 'rotate-180': open, 'rotate-0': !open }"
                            >
                                <path fill-rule="evenodd"
                                      d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                      clip-rule="evenodd"
                                ></path>
                            </svg>
                        </button>

                        <ul id="dropdown-contracts"
                            x-cloak
                            class="py-2 space-y-2"
                            x-show="open"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="transform opacity-0 scale-95"
                            x-transition:enter-end="transform opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="transform opacity-100 scale-100"
                            x-transition:leave-end="transform opacity-0 scale-95"
                        >
                            <li>
                                <a href="{{ route('contract-request.index', [legalEntity()]) }}"
                                   class="submenu-item"
                                >
                                    @icon('hugeicons-contracts')
                                    <span>{{ __('contracts.contract_requests') }}</span>
                                </a>
                            </li>

                            <li>
                                <a href="{{ route('contract.index', [legalEntity()]) }}"
                                   class="submenu-item"
                                >
                                    @icon('document-catch-up')
                                    <span>{{ __('contracts.contracts_list') }}</span>
                                </a>
                            </li>
                        </ul>
                    </li>
                @endif

                @can('viewAny', License::class)
                    <li>
                        <a href="{{ route('license.index', [legalEntity()]) }}"
                           class="menu-item-simple"
                        >
                            @icon('licenses')
                            <span>{{ __('forms.licenses') }}</span>
                        </a>
                    </li>
                @endcan

                @if(Auth::user()->can('viewAny', Declaration::class) || Auth::user()->can('viewAny', DeclarationRequest::class))
                    <li>
                        <a href="{{ route('declaration.index', [legalEntity()]) }}"
                           class="menu-item-simple"
                        >
                            @icon('declaration')
                            <span>{{ __('forms.declarations') }}</span>
                        </a>
                    </li>
                @endif

                @if(Auth::user()->can('viewAny', Person::class) || Auth::user()->can('viewAny', PersonRequest::class))
                    <li>
                        <a href="{{ route('persons.index', [legalEntity()]) }}"
                           class="menu-item-simple"
                        >
                            @icon('patients')
                            <span>{{ __('patients.patients') }}</span>
                        </a>
                    </li>
                @endif

                @can('viewAny', Equipment::class)
                    <li>
                        <a href="{{ route('equipment.index', [legalEntity()]) }}"
                           class="menu-item-simple"
                        >
                            @icon('equipment')
                            <span>{{ __('equipments.label') }}</span>
                        </a>
                    </li>
                @endcan

                <li x-data="{ open: {{ (request()->routeIs('dictionaries.medication-programs.index') || request()->routeIs('dictionaries.service-programs.index') || request()->routeIs('dictionaries.drug-list.index')) ? 'true' : 'false' }} }"
                    class="space-y-2"
                >
                    <button @click="open = !open"
                            type="button"
                            class="menu-item"
                            aria-controls="dropdown-dictionaries"
                            :aria-expanded="open"
                    >
                        @icon('library-linear')
                        <span>{{ __('dictionaries.label') }}</span>

                        <svg fill="currentColor" viewBox="0 0 20 20"
                             xmlns="http://www.w3.org/2000/svg"
                             :class="{ 'rotate-180': open, 'rotate-0': !open }"
                        >
                            <path fill-rule="evenodd"
                                  d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                  clip-rule="evenodd"
                            ></path>
                        </svg>
                    </button>

                    <ul id="dropdown-dictionaries"
                        x-cloak
                        class="py-2 space-y-2"
                        x-show="open"
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="transform opacity-0 scale-95"
                        x-transition:enter-end="transform opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="transform opacity-100 scale-100"
                        x-transition:leave-end="transform opacity-0 scale-95"
                    >
                        <li>
                            <a href="{{ route('dictionaries.medication-programs.index', [legalEntity()]) }}"
                               class="submenu-item"
                            >
                                @icon('boxicons-file')
                                <span>{{ __('dictionaries.medication_programs.title') }}</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('dictionaries.service-programs.index', [legalEntity()]) }}"
                               class="submenu-item"
                            >
                                @icon('boxicons-file')
                                <span>{{ __('dictionaries.service_programs.title') }}</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('dictionaries.drug-list.index', [legalEntity()]) }}"
                               class="submenu-item"
                            >
                                @icon('boxicons-file')
                                <span>{{ __('drug-list.title') }}</span>
                            </a>
                        </li>
                    </ul>
                </li>
        @endif
    </div>
</aside>
