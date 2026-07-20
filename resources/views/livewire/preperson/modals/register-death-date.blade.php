<template x-teleport="body">
    <div
        x-show="showRegisterDeathDateModal"
        style="display: none"
        @keydown.escape.prevent.stop="showRegisterDeathDateModal = false"
        role="dialog"
        aria-modal="true"
        class="modal"
    >
        <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
        <div x-transition @click="showRegisterDeathDateModal = false" class="modal-wrapper">
            <div
                @click.stop
                x-trap.noscroll.inert="showRegisterDeathDateModal"
                class="modal-content w-full max-w-3xl mx-auto p-8 rounded-xl bg-white dark:bg-gray-800"
            >
                <div class="flex flex-col gap-6">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                        {{ __('preperson.register_death_title') }}
                    </h3>

                    <div class="space-y-4">
                        <div class="form-group group max-w-[200px]">
                            <div class="datepicker-wrapper">
                                <input
                                    type="text"
                                    id="deathDate"
                                    name="deathDate"
                                    wire:model="deathDate"
                                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                                    class="datepicker-input with-leading-icon input peer @error('deathDate') input-error @enderror"
                                    placeholder=" "
                                    required
                                    autocomplete="off"
                                />
                                <label for="deathDate" class="wrapped-label">
                                    {{ __('preperson.death_date') }}
                                </label>
                            </div>

                            @error('deathDate')
                                <p class="text-error mt-1 text-xs">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>
                    </div>


                    <div class="flex gap-4 xl:flex-row justify-start items-center mt-4">
                        <button
                            type="button"
                            @click="showRegisterDeathDateModal = false; showRegisterDeathModal = true;"
                            class="button-minor"
                            style="margin: 0 !important;"
                        >
                            {{ __('forms.back') }}
                        </button>
                        <button
                            type="button"
                            wire:click="registerDeath"
                            class="button-danger"
                            style="margin: 0 !important;"
                        >
                            {{ __('patients.register_death') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
