@use('App\Enums\Person\AuthenticationMethod')
@use('App\Enums\Person\VerificationStatus as Status')

<x-layouts.patient :id="$id" :patientFullName="$patientFullName">
    <div class="breadcrumb-form p-4 shift-content">
        <div class="flex items-center gap-14 mb-10">
            <p class="default-p">
                {{ __('patients.verification_in_eHealth') }}: {{ Status::from($verificationStatus)->label() }}
            </p>

            <div>
                <button wire:click.once="getVerificationStatus"
                        type="button"
                        class="flex items-center gap-2 button-primary"
                >
                    {{ __('patients.update_status') }}
                    @icon('refresh', 'w-4 h-4')
                </button>
            </div>
        </div>

        <div id="accordion-open" data-accordion="open">
            <h2 id="accordion-open-heading-1">
                <button type="button"
                        class="accordion-button rounded-t-xl border-b-0 group"
                        data-accordion-target="#accordion-open-body-1"
                        aria-expanded="true"
                        aria-controls="accordion-open-body-1"
                        data-accordion-icon
                >
                    <span class="text-lg">{{ __('patients.passport_data') }}</span>
                    @icon('chevron-down', 'w-5 h-5 text-gray-500 dark:text-gray-400 transition-transform group-aria-expanded:rotate-180')
                </button>
            </h2>
            <div id="accordion-open-body-1" class="hidden" aria-labelledby="accordion-open-heading-1" wire:ignore.self>
                <div class="accordion-content dark:bg-gray-900 border-b-0">
                    <div class="form-row-4 items-baseline">
                        <div class="form-group group">
                            <p class="default-p">{{ __('forms.last_name') }}</p>
                        </div>
                        <div>
                            <input wire:model="lastName"
                                   type="text"
                                   name="lastName"
                                   id="lastName"
                                   class="input"
                                   placeholder=" "
                                   required
                                   autocomplete="off"
                            />
                        </div>
                    </div>

                    <div class="form-row-4 items-baseline">
                        <div class="form-group group">
                            <p class="default-p">{{ __('forms.first_name') }}</p>
                        </div>
                        <div>
                            <input wire:model="firstName"
                                   type="text"
                                   name="firstName"
                                   id="firstName"
                                   class="input"
                                   placeholder=" "
                                   required
                                   autocomplete="off"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <h2 id="accordion-open-heading-2">
                <button type="button"
                        class="accordion-button border-b-0 group"
                        data-accordion-target="#accordion-open-body-2"
                        aria-expanded="false"
                        aria-controls="accordion-open-body-2"
                        data-accordion-icon
                >
                    <span class="text-lg">{{ __('patients.contact_data') }}</span>
                    @icon('chevron-down', 'w-5 h-5 text-gray-500 dark:text-gray-400 transition-transform group-aria-expanded:rotate-180')
                </button>
            </h2>
            <div id="accordion-open-body-2" class="hidden" aria-labelledby="accordion-open-heading-2" wire:ignore.self>
                <div class="accordion-content dark:bg-gray-900 border-b-0">
                    @foreach($phones as $key => $phone)
                        <div class="form-row-4 items-baseline">
                            <div class="form-group group">
                                <p class="default-p">{{ __('forms.phone') }}</p>
                            </div>
                            <div>
                                <input wire:model="phones.{{ $key }}.number"
                                       type="text"
                                       name="phoneNumber_{{ $key }}"
                                       id="phoneNumber_{{ $key }}"
                                       class="input"
                                       placeholder=" "
                                       required
                                       autocomplete="off"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <h2 id="accordion-open-heading-3" wire:ignore>
                <button wire:click.once="getConfidantPersons"
                        type="button"
                        class="accordion-button border-b-0 group"
                        data-accordion-target="#accordion-open-body-3"
                        aria-expanded="false"
                        aria-controls="accordion-open-body-3"
                        data-accordion-icon
                >
                    <span class="text-lg">{{ __('patients.patient_legal_representative') }}</span>
                    @icon('chevron-down', 'w-5 h-5 text-gray-500 dark:text-gray-400 transition-transform group-aria-expanded:rotate-180')
                </button>
            </h2>
            <div id="accordion-open-body-3" class="hidden" aria-labelledby="accordion-open-heading-3" wire:ignore.self>
                <div class="accordion-content dark:bg-gray-900 border-t-0">
                    @if(!empty($confidantPersonRelationships))
                        @foreach($confidantPersonRelationships as $key => $confidantPersonRelationship)
                            <div class="form-row-4 items-baseline">
                                <div class="form-group group">
                                    <p class="default-p">{{ __('forms.full_name') }}</p>
                                </div>
                                <div>
                                    <input wire:model="confidantPersonRelationships.{{ $key }}.confidant_person.name"
                                           type="text"
                                           name="name_{{ $key }}"
                                           id="name_{{ $key }}"
                                           class="input"
                                           placeholder=" "
                                           required
                                           autocomplete="off"
                                    />
                                </div>
                            </div>
                            <div class="form-row-4 items-baseline">
                                <div class="form-group group">
                                    <p class="default-p">{{ __('forms.active_to') }}</p>
                                </div>
                                <div>
                                    <input wire:model="confidantPersonRelationships.{{ $key }}.active_to"
                                           type="text"
                                           name="activeTo_{{ $key }}"
                                           id="activeTo_{{ $key }}"
                                           class="input"
                                           placeholder=" "
                                           required
                                           autocomplete="off"
                                    />
                                </div>
                            </div>
                        @endforeach
                    @else
                        <p class="default-p">{{ __('patients.confidant_person_not_exist') }}</p>
                    @endif
                </div>
            </div>

            <h2 id="accordion-open-heading-4" wire:ignore>
                <button wire:click.once="getAuthenticationMethods"
                        type="button"
                        class="accordion-button group"
                        data-accordion-target="#accordion-open-body-4"
                        aria-expanded="false"
                        aria-controls="accordion-open-body-4"
                        data-accordion-icon
                >
                    <span class="text-lg">{{ __('patients.authentication_methods') }}</span>
                    @icon('chevron-down', 'w-5 h-5 text-gray-500 dark:text-gray-400 transition-transform group-aria-expanded:rotate-180')
                </button>
            </h2>
            <div id="accordion-open-body-4" class="hidden" aria-labelledby="accordion-open-heading-4" wire:ignore.self>
                <div class="accordion-content dark:bg-gray-900 border-t-0">
                    @include('livewire.person.records.authentication-methods')
                </div>
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
