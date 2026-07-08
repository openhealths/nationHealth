@use('App\Enums\Preperson\Status')

@php
    $emergencyContact = (array) $preperson->emergencyContact;
@endphp

<div class="breadcrumb-form p-4 shift-content space-y-6" x-data="{ showCertificate: false }">
    <div x-data="{ open: true }"
         class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
        <h2>
            <button
                type="button"
                class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                @click="open = !open"
                :aria-expanded="open"
            >
                <span class="text-base font-semibold text-gray-900 dark:text-white">
                    {{ __('preperson.identification') }}
                </span>
                @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
            </button>
        </h2>
        <div x-show="open">
            <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-4">
                <button
                    type="button"
                    @click="showMergePatientDrawer = true"
                    class="cursor-pointer text-blue-600 hover:text-blue-800 flex items-center gap-1.5 font-medium"
                >
                    @icon('plus', 'w-4 h-4')
                    <span class="text-sm">{{ __('preperson.associate_patient') }}</span>
                </button>
            </div>
        </div>
    </div>

    <div x-data="{ open: true }"
         class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
        <h2>
            <button
                type="button"
                class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                @click="open = !open"
                :aria-expanded="open"
            >
                <span
                    class="text-base font-semibold text-gray-900 dark:text-white">{{ __('patients.main_info') }}</span>
                @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
            </button>
        </h2>
        <div x-show="open" wire:ignore.self>
            <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-4 space-y-6">
                <div class="form-row-3">
                    <div class="form-group group">
                        <input
                            type="text"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->externalId }}"
                        />
                        <label class="label">{{ __('preperson.external_id') }}</label>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group group">
                        <input
                            type="text"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->firstName ?: '-' }}"
                        />
                        <label class="label">{{ __('forms.first_name') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->lastName ?: '-' }}"
                        />
                        <label class="label">{{ __('forms.last_name') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->secondName ?: '-' }}"
                        />
                        <label class="label">{{ __('forms.second_name') }}</label>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group group">
                        <input
                            type="text"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->gender->label() }}"
                        />
                        <label class="label">{{ __('forms.gender') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->birthDate ?: '-' }}"
                        />
                        <label class="label">{{ __('forms.birth_date') }}</label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group group">
                        <label class="label-secondary">{{ __('preperson.note') }}</label>
                        <textarea
                            class="textarea w-full"
                            rows="3"
                            readonly
                            placeholder="{{ __('preperson.note') }}"
                        >{{ $preperson->reasonNote }}</textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div
        x-data="{ open: true }"
        class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm"
    >
        <h2>
            <button
                type="button"
                class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                @click="open = !open"
                :aria-expanded="open"
            >
                <span class="text-base font-semibold text-gray-900 dark:text-white">
                    {{ __('preperson.contact_person') }}
                </span>
                @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
            </button>
        </h2>

        <div x-show="open">
            <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-4 space-y-6">
                <div class="form-row-3">
                    <div class="form-group group">
                        <input
                            type="text"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->emergencyContact['first_name'] ?? '-' }}"
                        />
                        <label class="label">{{ __('forms.first_name') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->emergencyContact['last_name'] ?? '-' }}"
                        />
                        <label class="label">{{ __('forms.last_name') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->emergencyContact['second_name'] ?? '-' }}"
                        />
                        <label class="label">{{ __('forms.second_name') }}</label>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group group">
                        <input
                            type="text"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ dictionary()->basics()->byName('PHONE_TYPE')->asCodeDescription()->toArray()[$preperson->emergencyContact['phones'][0]['type'] ?? ''] ?? '-' }}"
                        />
                        <label class="label">{{ __('forms.phone_type') }}</label>
                    </div>
                    <div class="form-group group">
                        <input
                            type="text"
                            class="input peer"
                            placeholder=" "
                            readonly
                            value="{{ $preperson->emergencyContact['phones'][0]['number'] ?? '-' }}"
                        />
                        <label class="label">{{ __('forms.phone') }}</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="flex flex-wrap gap-4 pt-4 border-t border-gray-100 dark:border-gray-700 justify-start items-center">
        <a href="{{ route('prepersons.index', [legalEntity()]) }}" class="button-minor">
            {{ __('forms.close') }}
        </a>
        @if($preperson->status !== Status::DRAFT)
            <button
                type="button"
                class="button-primary-outline flex items-center gap-2"
                @click="showCertificate = true"
            >
                @icon('printer', 'w-4 h-4')
                <span>{{ __('preperson.info_certificate') }}</span>
            </button>
            <button type="button" class="button-primary-outline-red">
                {{ __('preperson.register_death') }}
            </button>
        @endif
    </div>

    @include('livewire.person.records.partials.information-certificate')
</div>
