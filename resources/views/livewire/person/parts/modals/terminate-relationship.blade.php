{{-- Modal for terminating relationship with representative --}}
<div
    x-data="{
    getParentData() {
      const fieldset = document.querySelector('fieldset[data-fieldset=&quot;incapacitated&quot;]');
      if (fieldset) return Alpine.$data(fieldset);
      const container = document.querySelector('div.breadcrumb-form');
      return container ? Alpine.$data(container) : null;
    },
    isVisible: false
  }"
    x-effect="isVisible = getParentData()?.showTerminateModal ?? false"
>
    <template x-teleport="body">
        <div x-show="isVisible"
             style="display: none; z-index: 100;"
             @keydown.escape.prevent.stop="
                 const parentData = getParentData();
                 if (parentData) parentData.showTerminateModal = false;
             "
             role="dialog"
             aria-modal="true"
             class="modal"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div x-transition @click="
                     const parentData = getParentData();
                     if (parentData) parentData.showTerminateModal = false;
                 " class="modal-wrapper">
                <div @click.stop
                     x-trap.noscroll.inert="isVisible"
                     class="modal-content w-full max-w-lg mx-auto"
                >
                    {{-- Title --}}
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-6">
                        {{ __('patients.relationship_terminated') }}
                    </h2>

                    {{-- Warning Box --}}
                    <div role="alert" class="mb-6 p-4 rounded-lg" style="background-color: #FFFBE6;">
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 mt-0.5">
                                @icon('alert-circle', 'w-5 h-5 text-gray-600 dark:text-gray-400')
                            </div>
                            <div class="flex-1 text-sm text-gray-700 dark:text-gray-300">
                                <p class="mb-2">
                                    {{ __('patients.terminate_relationship_warning_1') }}
                                </p>
                                <p>
                                    {{ __('patients.terminate_relationship_warning_2') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="flex justify-start gap-4">
                        <button type="button"
                                @click="
                                    const parentData = getParentData();
                                    if (parentData) parentData.showTerminateModal = false;
                                "
                                class="button-minor"
                        >
                            {{ __('forms.cancel') }}
                        </button>

                        <button type="button"
                                @click="
                                    const parentData = getParentData();
                                    if (parentData) {
                                        parentData.showTerminateModal = false;
                                        parentData.showSignatureDrawer = false;
                                        parentData.showAuthDrawer = false;
                                    }
                                "
                                class="button-outline-primary"
                        >
                            {{ __('patients.to_authentication_methods') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
