<fieldset class="fieldset">
    <legend class="legend">
        {{ __('forms.main_information') }}
    </legend>

    {{-- Code --}}
    <div class="form-row-modal" x-data="{ openModal: false }">
        <div class="form-group group">
            <select x-model="modalClinicalImpression.code.coding[0].code"
                    id="code"
                    class="input-select peer"
                    type="text"
                    required
            >
                <option selected value="">
                    {{ __('forms.select') }} {{ mb_strtolower(__('forms.code')) }} *
                </option>
                @foreach($this->dictionaries['eHealth/clinical_impression_patient_categories'] as $key => $clinicalImpressionPatientCategory)
                    <option value="{{ $key }}">{{ $clinicalImpressionPatientCategory }}</option>
                @endforeach
            </select>
        </div>

        {{-- Rule engine rules --}}
        <div class="form-group group">
            <div class="flex items-start pt-[10px]">
                <a @click.prevent="modalClinicalImpression.code.coding[0].code && (openModal = true)"
                   class="rule-engine-rules default-p"
                >
                    {{ __('patients.set_of_rule_engines') }}
                </a>
            </div>
        </div>

        <template x-teleport="body"> {{-- This moves the modal at the end of the body tag --}}
            <div x-show="openModal"
                 style="display: none"
                 @keydown.escape.prevent.stop="openModal = false"
                 role="dialog"
                 aria-modal="true"
                 x-id="['modal-title']"
                 :aria-labelledby="$id('modal-title')" {{-- This associates the modal with unique ID --}}
                 class="modal"
            >

                {{-- Overlay --}}
                <div x-show="openModal" x-transition.opacity class="fixed inset-0 bg-black/25"></div>

                {{-- Panel --}}
                <div x-show="openModal"
                     x-transition
                     @click="openModal = false"
                     class="relative flex min-h-screen items-center justify-center p-4"
                >
                    <div @click.stop
                         x-trap.noscroll.inert="openModal"
                         class="modal-content h-fit w-full lg:max-w-5xl"
                    >
                        {{-- Title --}}
                        <h3 class="modal-header" :id="$id('modal-title')">{{ __('patients.set_of_rule_engines') }}</h3>

                        {{-- Content --}}
                        <form>
                            <div class="space-y-2">
                                <p class="default-p">Деталі:</p>
                                <div class="space-y-2 border-2 rounded-md border-dashed border-white dark:border-white">
                                    <template
                                        x-for="(detail, index) in $wire.dictionaries['custom/rule_engine_details'][modalClinicalImpression.code.coding[0].code]?.items"
                                        :key="index"
                                    >
                                        <p class="default-p" x-text="detail.value.string"></p>
                                    </template>
                                </div>
                            </div>

                            {{-- Action button --}}
                            <div class="mt-6 flex justify-center space-x-2">
                                <button @click.prevent
                                        type="button"
                                        @click="openModal = false"
                                        class="button-minor"
                                >
                                    {{ __('forms.cancel') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- Description --}}
    <div class="form-row-modal">
        <div class="form-group group">
            <input x-model="modalClinicalImpression.description"
                   type="text"
                   name="description"
                   id="description"
                   class="input peer"
                   placeholder=" "
                   autocomplete="off"
            >
            <label for="description" class="label">
                {{ __('patients.conclusion') }}
            </label>
        </div>
    </div>

    @include('livewire.encounter.clinical-impression-parts.previous')
</fieldset>
