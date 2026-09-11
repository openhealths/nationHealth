<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName" activeTab="prescription-requests">
    <x-slot name="headerActions">
        <button type="button" class="button-primary-outline px-5 py-2 text-sm whitespace-nowrap">
            {{ __('patients.data_access') }}
        </button>
        <button type="button" class="button-sync flex items-center gap-2 px-5 py-2 text-sm shadow-sm transition-colors whitespace-nowrap">
            @icon('refresh', 'w-4 h-4')
            {{ __('legal-entity-connection.sync_data') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('medication-requests.poshuk_zaiavok_na_retsepti') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <select id="filterStatus" wire:model="filterStatus" class="input-select peer w-full">
                        <option value="">{{ __('medication-requests.usi') }}</option>
                        <option value="NEW">{{ __('preperson.statuses.NEW') }}</option>
                    </select>
                    <label class="label" for="filterStatus">{{ __('validation.attributes.status') }}</label>
                </div>
                <div class="form-group group">
                    <select id="filterDoctor" wire:model="filterDoctor" class="input-select peer w-full">
                        <option value="">{{ __('medication-requests.usi') }}</option>
                        <option value="Shevchenko">{{ __('medication-requests.шевченко_тг') }}</option>
                    </select>
                    <label class="label" for="filterDoctor">{{ __('users.role.DOCTOR') }}</label>
                </div>
                <div class="form-group group">
                    <select id="filterLegalEntity" wire:model="filterLegalEntity" class="input-select peer w-full">
                        <option value="">{{ __('medication-requests.usi') }}</option>
                        <option value="hospital4">{{ __('medication-requests.likarnia') }} №4</option>
                    </select>
                    <label class="label" for="filterLegalEntity">{{ __('devices.legal_entity') }}</label>
                </div>
            </div>

            <div class="mb-9 flex flex-wrap gap-2">
                <button type="button" wire:click.prevent="applyFilters" class="button-primary flex items-center gap-2 px-5 py-2.5 text-sm shadow-sm">
                    @icon('search', 'w-4 h-4')
                    <span>{{ __('forms.search') }}</span>
                </button>
                <button type="button" wire:click.prevent="resetFilters" class="button-primary-outline-red px-5 py-2.5 text-sm">
                    {{ __('patients.reset_filters') }}
                </button>
                <button type="button" class="button-minor flex items-center gap-2 px-5 py-2.5 text-sm whitespace-nowrap" @click.prevent="showAdditionalParams = !showAdditionalParams">
                    @icon('adjustments', 'w-4 h-4 text-gray-500')
                    <span>{{ __('forms.additional_search_parameters') }}</span>
                </button>
            </div>

            <div x-show="showAdditionalParams" x-transition x-cloak>
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <input id="filterInteractionId" type="text" class="input peer" wire:model="filterInteractionId" placeholder=" " autocomplete="off" />
                        <label class="label" for="filterInteractionId">ID {{ __('medication-requests.vzaiemodiyi') }}</label>
                        <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" wire:click="$set('filterInteractionId', '')">✕</button>
                    </div>
                    <div class="form-group group">
                        <input id="filterCarePlanId" type="text" class="input peer" wire:model="filterCarePlanId" placeholder=" " autocomplete="off" />
                        <label class="label" for="filterCarePlanId">ID {{ __('medication-requests.planu_likuvannia') }}</label>
                        <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" wire:click="$set('filterCarePlanId', '')">✕</button>
                    </div>
                    <div class="form-group group">
                        <input id="filterAppointmentId" type="text" class="input peer" wire:model="filterAppointmentId" placeholder=" " autocomplete="off" />
                        <label class="label" for="filterAppointmentId">ID {{ __('medication-requests.priznachennia') }}</label>
                        <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" wire:click="$set('filterAppointmentId', '')">✕</button>
                    </div>
                </div>
                
                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <input id="filterEpisodeId" type="text" class="input peer" wire:model="filterEpisodeId" placeholder=" " autocomplete="off" />
                        <label class="label" for="filterEpisodeId">ID {{ __('medication-requests.epizodu') }}</label>
                        <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" wire:click="$set('filterEpisodeId', '')">✕</button>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($prescriptionRequests as $request)
                    <div class="record-inner-card" wire:key="mr-{{ $request['id'] ?? $request['uuid'] }}">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox h-5 w-5" />
                            </div>

                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ $request['requestNumber'] ?? '—' }}</div>
                                <div class="record-inner-value text-[16px]">{{ $request['medicationName'] ?? '—' }}</div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('validation.attributes.status') }}</div>
                                <div>
                                    <span class="{{ $request['statusBadge'] ?? 'badge-dark' }}">
                                        {{ $request['statusLabel'] ?? ($request['status'] ?? '—') }}
                                    </span>
                                </div>
                            </div>

                            <div class="record-inner-action-col">
                                <div class="relative flex justify-center">
                                    <div
                                        x-data="{
                                            open: false,
                                            toggle() {
                                                if (this.open) {
                                                    return this.close();
                                                }
                                                this.$refs.button.focus();
                                                this.open = true;
                                            },
                                            close(focusAfter) {
                                                if (! this.open) return;
                                                this.open = false;
                                                focusAfter && focusAfter.focus();
                                            },
                                        }"
                                        @keydown.escape.prevent.stop="close($refs.button)"
                                        @focusin.window="! $refs.panel.contains($event.target) && close()"
                                        x-id="['dropdown-button']"
                                        class="relative"
                                    >
                                        <button
                                            @click="toggle()"
                                            x-ref="button"
                                            :aria-expanded="open"
                                            :aria-controls="$id('dropdown-button')"
                                            type="button"
                                            class="record-inner-action-btn cursor-pointer"
                                        >
                                            @icon('edit-user-outline', 'w-5 h-5 text-gray-700 dark:text-gray-300')
                                        </button>
                                        
                                        <div
                                            x-show="open"
                                            x-cloak
                                            x-ref="panel"
                                            x-transition.origin.top.right
                                            @click.outside="close($refs.button)"
                                            :id="$id('dropdown-button')"
                                            class="absolute right-0 z-50 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-md dark:border-gray-600 dark:bg-gray-700"
                                        >
                                            <a href="{{ route($prepersonId !== null ? 'prepersons.prescription-requests.view' : 'persons.prescription-requests.view', [legalEntity(), $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId, 'requestId' => data_get($request, 'id')]) }}" wire:navigate @click="close($refs.button)" class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600">
                                                @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                {{ __('patients.view_details') }}
                                            </a>
                                            <button @click="close($refs.button)" type="button" class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600">
                                                @icon('check-circle', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                {{ __('declarations.sign_declaration_request') }}
                                            </button>
                                            <div class="border-t border-gray-100 dark:border-gray-600 my-1"></div>
                                            <button @click="close($refs.button)" type="button" class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-red-500 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">
                                                @icon('cancel', 'w-5 h-5 text-red-500 dark:text-red-400')
                                                {{ __('medication-requests.vidminiti_zaiavku') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('medication-requests.kil_kist') }}</div>
                                        <div class="record-inner-value">{{ $request['medicationQty'] ?? '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('medication-requests.programa') }}</div>
                                        <div class="record-inner-value">{{ $request['programName'] ?? '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('medication-requests.likuvannia') }}</div>
                                        <div class="record-inner-value">{{ $request['periodLabel'] ?? '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('medication-requests.likar') }}</div>
                                        <div class="record-inner-value">{{ $request['doctorName'] ?? '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.legal_entity') }}</div>
                                        <div class="record-inner-value">{{ $request['legalEntityName'] ?? '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('legal-entity-connection.table_created') }}</div>
                                        <div class="record-inner-value">{{ $request['createdAt'] ?? '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('medication-requests.dostupnii_do_otrimannia') }}</div>
                                        <div class="record-inner-value">{{ $request['dispensePeriodLabel'] ?? $request['periodLabel'] ?? '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('medication-requests.id_priznachennia') }}</div>
                                        <div class="record-inner-value truncate" title="{{ $request['appointmentId'] ?? '—' }}">{{ $request['appointmentId'] ?? '—' }}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0 mb-3">
                                    <div class="record-inner-label">{{ __('medication-requests.id_vzaiemodiyi') }}</div>
                                    <div class="record-inner-id-value">{{ $request['encounterId'] ?? '—' }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('medication-requests.bazuiet_sia_na') }}</div>
                                    <div class="record-inner-id-value">{{ $request['basisLabel'] ?? '—' }}<br>{{ $request['basisId'] ?? '—' }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found description="{{ __('medication-requests.zaiavok_na_retsepti_za_obranimi_fil_tram') }}." />
                @endforelse
            </div>
        </div>
    </div>


</x-layouts.patient>
