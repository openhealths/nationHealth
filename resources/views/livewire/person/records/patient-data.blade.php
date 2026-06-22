@use('App\Enums\Person\AuthenticationMethod')
@use('App\Enums\Person\VerificationStatus as Status')

<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName">
    <div class="breadcrumb-form px-4 pt-4 pb-10 shift-content">

        <div class="flex items-center gap-4 mb-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                <span class="font-medium">{{ __('patients.verification_in_eHealth') }}:</span>
                <span class="ml-1">{{ Status::from($verificationStatus)->label() }}</span>
            </p>

            <button wire:click.once="getVerificationStatus"
                    type="button"
                    class="inline-flex items-center gap-2 px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer"
            >
                {{ __('patients.update_status') }}
                @icon('refresh', 'w-4 h-4')
            </button>
        </div>

        <div id="accordion-open" data-accordion="open" class="flex flex-col gap-4">

            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                <h2 id="accordion-open-heading-1">
                    <button type="button"
                            class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                            data-accordion-target="#accordion-open-body-1"
                            aria-expanded="true"
                            aria-controls="accordion-open-body-1"
                            data-accordion-icon
                    >
                        <span class="text-base font-semibold text-gray-900 dark:text-white">{{ __('patients.passport_data') }}</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                    </button>
                </h2>
                <div id="accordion-open-body-1" aria-labelledby="accordion-open-heading-1" wire:ignore.self>
                    <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700">
                        <div class="mt-4 space-y-4">
                            <div class="flex items-center gap-4">
                                <label class="w-40 text-sm text-gray-500 dark:text-gray-400 shrink-0">{{ __('forms.last_name') }}:</label>
                                <input wire:model="lastName"
                                       type="text"
                                       name="lastName"
                                       id="lastName"
                                       class="flex-1 max-w-xs bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white text-sm rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                       placeholder=" "
                                       autocomplete="off"
                                />
                            </div>

                            <div class="flex items-center gap-4">
                                <label class="w-40 text-sm text-gray-500 dark:text-gray-400 shrink-0">{{ __('forms.first_name') }}:</label>
                                <input wire:model="firstName"
                                       type="text"
                                       name="firstName"
                                       id="firstName"
                                       class="flex-1 max-w-xs bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white text-sm rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                       placeholder=" "
                                       autocomplete="off"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                <h2 id="accordion-open-heading-2">
                    <button type="button"
                            class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                            data-accordion-target="#accordion-open-body-2"
                            aria-expanded="false"
                            aria-controls="accordion-open-body-2"
                            data-accordion-icon
                    >
                        <span class="text-base font-semibold text-gray-900 dark:text-white">{{ __('patients.contact_data') }}</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                    </button>
                </h2>
                <div id="accordion-open-body-2" class="hidden" aria-labelledby="accordion-open-heading-2" wire:ignore.self>
                    <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700">
                        <div class="mt-4 space-y-4">
                            @foreach($phones as $key => $phone)
                                <div class="flex items-center gap-4">
                                    <label class="w-40 text-sm text-gray-500 dark:text-gray-400 shrink-0">{{ __('forms.phone') }}:</label>
                                    <input wire:model="phones.{{ $key }}.number"
                                           type="text"
                                           name="phoneNumber_{{ $key }}"
                                           id="phoneNumber_{{ $key }}"
                                           class="flex-1 max-w-xs bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white text-sm rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                           placeholder=" "
                                           autocomplete="off"
                                    />
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                <h2 id="accordion-open-heading-3" wire:ignore>
                    <button wire:click.once="getConfidantPersons"
                            type="button"
                            class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                            data-accordion-target="#accordion-open-body-3"
                            aria-expanded="false"
                            aria-controls="accordion-open-body-3"
                            data-accordion-icon
                    >
                        <span class="text-base font-semibold text-gray-900 dark:text-white">{{ __('patients.patient_legal_representative') }}</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                    </button>
                </h2>
                <div id="accordion-open-body-3" class="hidden" aria-labelledby="accordion-open-heading-3" wire:ignore.self>
                    <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700">
                        <div class="mt-4 space-y-4">
                            @if(!empty($confidantPersonRelationships))
                                @foreach($confidantPersonRelationships as $key => $confidantPersonRelationship)
                                    <div class="flex items-center gap-4">
                                        <label class="w-40 text-sm text-gray-500 dark:text-gray-400 shrink-0">{{ __('forms.full_name') }}:</label>
                                        <input wire:model="confidantPersonRelationships.{{ $key }}.confidant_person.name"
                                               type="text"
                                               name="name_{{ $key }}"
                                               id="name_{{ $key }}"
                                               class="flex-1 max-w-xs bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white text-sm rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                               placeholder=" "
                                               autocomplete="off"
                                        />
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <label class="w-40 text-sm text-gray-500 dark:text-gray-400 shrink-0">{{ __('forms.active_to') }}:</label>
                                        <input wire:model="confidantPersonRelationships.{{ $key }}.active_to"
                                               type="text"
                                               name="activeTo_{{ $key }}"
                                               id="activeTo_{{ $key }}"
                                               class="flex-1 max-w-xs bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-900 dark:text-white text-sm rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                               placeholder=" "
                                               autocomplete="off"
                                        />
                                    </div>
                                @endforeach
                            @else
                                <p class="text-sm text-gray-500 dark:text-gray-400 py-2">{{ __('patients.confidant_person_not_exist') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                <h2 id="accordion-open-heading-4" wire:ignore>
                    <button wire:click.once="getAuthenticationMethods"
                            type="button"
                            class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                            data-accordion-target="#accordion-open-body-4"
                            aria-expanded="false"
                            aria-controls="accordion-open-body-4"
                            data-accordion-icon
                    >
                        <span class="text-base font-semibold text-gray-900 dark:text-white">{{ __('patients.authentication_methods') }}</span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                    </button>
                </h2>
                <div id="accordion-open-body-4" class="hidden" aria-labelledby="accordion-open-heading-4" wire:ignore.self>
                    <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700">
                        <div class="mt-4">
                            @include('livewire.person.records.authentication-methods')
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div class="mt-8">
            <a href="{{ route('persons.update', [legalEntity(), $personId]) }}"
               class="inline-flex items-center px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer"
            >
                {{ __('patients.edit_data') }}
            </a>
        </div>

    </div>

    <x-forms.loading />
</x-layouts.patient>
