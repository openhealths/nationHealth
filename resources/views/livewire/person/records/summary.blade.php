<x-layouts.patient :id="$id" :patientFullName="$patientFullName">
    <div class="breadcrumb-form p-4 shift-content">
        <button wire:click.prevent=""
                class="button-primary mb-10"
        >
            {{ __('patients.get_access_to_medical_data') }}
        </button>

        <div id="accordion-open" data-accordion="open">
            <h2 id="accordion-open-heading-1">
                <button wire:click.once="getEpisodes"
                        type="button"
                        class="accordion-button rounded-t-xl border-b-0 group"
                        data-accordion-target="#accordion-open-body-1"
                        aria-expanded="false"
                        aria-controls="accordion-open-body-1"
                >
                    <span>{{ __('patients.episodes') }}</span>
                    @icon('chevron-down', 'w-5 h-5 text-gray-500 dark:text-gray-400 transition-transform group-aria-expanded:rotate-180')
                </button>
            </h2>
            <div id="accordion-open-body-1" class="hidden" aria-labelledby="accordion-open-heading-1" wire:ignore.self>
                <div class="accordion-content border-b-0">
                    <div class="form-row-4 items-baseline">

                    </div>
                </div>
            </div>

            <h2 id="accordion-open-heading-2" wire:ignore>
                <button wire:click.once="getDiagnoses"
                        type="button"
                        class="accordion-button border-b-0 group"
                        data-accordion-target="#accordion-open-body-2"
                        aria-expanded="false"
                        aria-controls="accordion-open-body-2"
                >
                    <span>{{ __('patients.diagnoses') }}</span>
                    @icon('chevron-down', 'w-5 h-5 text-gray-500 dark:text-gray-400 transition-transform group-aria-expanded:rotate-180')
                </button>
            </h2>
            <div id="accordion-open-body-2" class="hidden" aria-labelledby="accordion-open-heading-2" wire:ignore.self>
                <div class="accordion-content border-b-0">
                    <div class="form-row-4 items-baseline">
                    </div>
                </div>
            </div>

            <h2 id="accordion-open-heading-3" wire:ignore>
                <button wire:click.once="getObservations"
                        type="button"
                        class="accordion-button group"
                        data-accordion-target="#accordion-open-body-3"
                        aria-expanded="false"
                        aria-controls="accordion-open-body-3"
                >
                    <span>{{ __('patients.observation') }}</span>
                    @icon('chevron-down', 'w-5 h-5 text-gray-500 dark:text-gray-400 transition-transform group-aria-expanded:rotate-180')
                </button>
            </h2>
            <div id="accordion-open-body-3" class="hidden" aria-labelledby="accordion-open-heading-3" wire:ignore.self>
                <div class="accordion-content border-t-0">
                    <div class="form-row-4 items-baseline">

                    </div>
                </div>
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
