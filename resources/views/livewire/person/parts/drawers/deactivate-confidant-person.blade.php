<div x-show="showDeactivateConfidantPersonDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="translate-x-full"
     x-cloak
     class="fixed top-0 right-0 z-40 h-screen pt-20 p-4 overflow-y-auto transition-transform bg-white w-4/5 dark:bg-gray-800 shadow-2xl"
     x-data="{
         showResults: false,
         showDocumentDrawer: false,
     }"
     tabindex="-1"
>
    <h3 class="modal-header"
        x-text="'{{ __('Деактивувати законного представника') }}'"
    ></h3>

    {{-- Documents inside drawer --}}
    @include('livewire.person.parts.drawers.modals.documents')

    {{-- Drawer for adding documents that confirm confidant --}}
    @include('livewire.person.parts.drawers.add-documents-relationship')

    <div class="flex gap-3 mt-6">
        <button class="button-minor" type="button" @click="showDeactivateConfidantPersonDrawer = false">
            {{ __('forms.cancel') }}
        </button>

        <button type="button"
                class="button-primary"
                @click="
                      $wire.deactivateConfidantPerson(
                          confidantPersons[selectedConfidantIndex].uuid,
                          confidantPerson.documentsRelationship
                      );
                      openDropdown = false;
                "
        >
            {{ __('Деактивувати законного представника') }}
        </button>
    </div>
</div>
