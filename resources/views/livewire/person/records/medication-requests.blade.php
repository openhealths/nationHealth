<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName">
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
                <p>{{ __('medication-requests.poshuk_retseptiv') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <input id="filterRequestNumber" type="text" class="input peer" wire:model="filterRequestNumber" placeholder=" " autocomplete="off" />
                    <label class="label" for="filterRequestNumber">{{ __('medication-requests.nomer_retsepta') }}</label>
                </div>
                <div class="form-group group">
                    <select id="filterStatus" wire:model="filterStatus" class="input-select peer w-full">
                        <option value="">{{ __('medication-requests.usi') }}</option>
                        <option value="NEW">{{ __('medication-requests.status_new') }}</option>
                        <option value="draft">{{ __('patients.status.draft') }}</option>
                        <option value="active">{{ __('patients.active_status') }}</option>
                        <option value="completed">{{ __('care-plan.medication_request_status.completed') }}</option>
                        <option value="rejected">{{ __('patients.status.rejected') }}</option>
                        <option value="entered-in-error">{{ __('forms.status.entered_in_error') }}</option>
                    </select>
                    <label class="label" for="filterStatus">{{ __('validation.attributes.status') }}</label>
                </div>
                <div class="form-group group">
                    <input id="filterMedication" type="text" class="input peer" wire:model="filterMedication" placeholder=" " autocomplete="off" />
                    <label class="label" for="filterMedication">{{ __('medication-requests.preparat') }}</label>
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
                    </div>
                    <div class="form-group group">
                        <input id="filterCarePlanId" type="text" class="input peer" wire:model="filterCarePlanId" placeholder=" " autocomplete="off" />
                        <label class="label" for="filterCarePlanId">ID {{ __('medication-requests.planu_likuvannia') }}</label>
                    </div>
                    <div class="form-group group">
                        <input id="filterDoctor" type="text" class="input peer" wire:model="filterDoctor" placeholder=" " autocomplete="off" />
                        <label class="label" for="filterDoctor">{{ __('users.role.DOCTOR') }}</label>
                    </div>
                </div>
                
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <input id="filterEpisodeId" type="text" class="input peer" wire:model="filterEpisodeId" placeholder=" " autocomplete="off" />
                        <label class="label" for="filterEpisodeId">ID {{ __('medication-requests.epizodu') }}</label>
                    </div>
                    <div class="form-group group">
                        <input id="filterLegalEntity" type="text" class="input peer" wire:model="filterLegalEntity" placeholder=" " autocomplete="off" />
                        <label class="label" for="filterLegalEntity">{{ __('medication-requests.iedrpou_zaklad') }}</label>
                    </div>
                    <div class="form-group group">
                        <input id="filterMedicalProgram" type="text" class="input peer" wire:model="filterMedicalProgram" placeholder=" " autocomplete="off" />
                        <label class="label" for="filterMedicalProgram">{{ __('medication-requests.medichna_programa') }}</label>
                    </div>
                </div>

                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input id="filterStartedAtRange" type="text" class="daterangepicker-uk with-leading-icon input peer w-full" placeholder=" " autocomplete="off" wire:model="filterStartedAtRange" />
                            <label class="wrapped-label" for="filterStartedAtRange">{{ __('medication-requests.pochatok_likuvannia') }}</label>
                        </div>
                    </div>
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input id="filterEndedAtRange" type="text" class="daterangepicker-uk with-leading-icon input peer w-full" placeholder=" " autocomplete="off" wire:model="filterEndedAtRange" />
                            <label class="wrapped-label" for="filterEndedAtRange">{{ __('medication-requests.zavershennia_likuvannia') }}</label>
                        </div>
                    </div>
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input id="filterCreatedAtRange" type="text" class="daterangepicker-uk with-leading-icon input peer w-full" placeholder=" " autocomplete="off" wire:model="filterCreatedAtRange" />
                            <label class="wrapped-label" for="filterCreatedAtRange">{{ __('forms.inserted_at') }}</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input id="filterDispenseStartRange" type="text" class="daterangepicker-uk with-leading-icon input peer w-full" placeholder=" " autocomplete="off" wire:model="filterDispenseAvailableFromRange" />
                            <label class="wrapped-label" for="filterDispenseStartRange">{{ __('medication-requests.pochatok_dost_pogashennia') }}</label>
                        </div>
                    </div>
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input id="filterDispenseEndRange" type="text" class="daterangepicker-uk with-leading-icon input peer w-full" placeholder=" " autocomplete="off" wire:model="filterDispenseAvailableToRange" />
                            <label class="wrapped-label" for="filterDispenseEndRange">{{ __('medication-requests.zavershennia_dost_pogashennia') }}</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($medicationRequests as $request)
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
                                            x-transition.opacity
                                            @click.outside="close($refs.button)"
                                            :id="$id('dropdown-button')"
                                            class="absolute right-0 z-50 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-md dark:border-gray-600 dark:bg-gray-700"
                                        >
                                            <a href="{{ route($prepersonId ? 'prepersons.medication-requests.view' : 'persons.medication-requests.view', [legalEntity(), 'person' => $prepersonId ?? $personId, 'requestId' => $request['id']]) }}" class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600">
                                                @icon('eye', 'w-5 h-5 text-gray-700 dark:text-gray-300')
                                                {{ __('patients.view_details') }}
                                            </a>
                                            <button class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600">
                                                @icon('message-circle', 'w-5 h-5 text-gray-700 dark:text-gray-300')
                                                {{ __('medication-requests.nadislati_sms') }}
                                            </button>
                                            <button class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600">
                                                @icon('refresh', 'w-5 h-5 text-gray-700 dark:text-gray-300')
                                                {{ __('medication-requests.pereviriti_pogashennia') }}
                                            </button>
                                            <button class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600">
                                                @icon('printer', 'w-5 h-5 text-gray-700 dark:text-gray-300')
                                                {{ __('preperson.merge.print_memo') }}
                                            </button>
                                            <div class="border-t border-gray-100 dark:border-gray-600 my-1"></div>
                                            <button class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-red-500 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">
                                                @icon('cancel', 'w-5 h-5 text-red-500 dark:text-red-400')
                                                {{ __('medication-requests.vidminiti_retsept') }}
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
                                        <div class="record-inner-label">{{ __('care-plan.ehealth_fields.quantity_value') }}</div>
                                        <div class="record-inner-value">{{ $request['medicationQty'] ?? '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('dictionaries.program_label') }}</div>
                                        <div class="record-inner-value">{{ $request['programName'] ?? '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('care-plan.referral_category.treatment') }}</div>
                                        <div class="record-inner-value">{{ $request['periodLabel'] ?? '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('users.role.DOCTOR') }}</div>
                                        <div class="record-inner-value">{{ $request['doctorName'] ?? '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('patients.emergency_contact_request.created_at') }}</div>
                                        <div class="record-inner-value">{{ isset($request['createdAt']) ? \Carbon\Carbon::parse($request['createdAt'])->format('d.m.Y') : '—' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('medication-requests.dostupnii_do_otrimannia') }}</div>
                                        <div class="record-inner-value">{{ $request['dispensePeriodLabel'] ?? $request['periodLabel'] ?? '—' }}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0 mb-3">
                                    <div class="record-inner-label">ID {{ __('medication-requests.vzaiemodiyi') }}</div>
                                    <div class="record-inner-id-value">
                                        @if(!empty($request['encounterId']))
                                            <a href="{{ route('encounters.show', [legalEntity(), $request['encounterId']]) }}" class="text-link">
                                                {{ $request['encounterId'] }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('medication-requests.bazuiet_sia_na') }}</div>
                                    <div class="record-inner-id-value">
                                        @if (!empty($request['carePlanId']) && !empty($request['activityId']))
                                            <a href="{{ route('care-plans.activities.show', [legalEntity(), $request['carePlanId'], $request['activityId']]) }}" class="text-link">
                                                {{ $request['basisLabel'] }}
                                            </a>
                                        @elseif (!empty($request['carePlanId']))
                                            <a href="{{ route('care-plans.show', [legalEntity(), $request['carePlanId']]) }}" class="text-link">
                                                {{ $request['basisLabel'] }}
                                            </a>
                                        @else
                                            {{ $request['basisLabel'] ?? '—' }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        @if(in_array($request['status'] ?? '', ['completed', 'rejected'], true))
                            @if(isset($request['dispenseDate']) || !empty($request['dispenseStatus']))
                            <div class="record-inner-body border-t border-gray-200 dark:border-gray-700">
                                <div class="record-inner-grid-container">
                                    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                                        <div class="min-w-0">
                                            <div class="record-inner-label">{{ __('medication-requests.data_pogashennia') }}</div>
                                            <div class="record-inner-value">{{ isset($request['dispenseDate']) ? \Carbon\Carbon::parse($request['dispenseDate'])->format('d.m.Y') : '—' }}</div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label">{{ __('medication-requests.status_pogashennia') }}</div>
                                            <div class="record-inner-value">
                                                @if(!empty($request['dispenseStatus']))
                                                    <span class="badge-green">{{ $request['dispenseStatus'] }}</span>
                                                @else
                                                    —
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @endif
                        @endif
                    </div>
                @empty
                    <x-nothing-found description="{{ __('medication-requests.retseptiv_za_obranimi_fil_tram') }}." />
                @endforelse
            </div>
        </div>
    </div>
</x-layouts.patient>



