<x-dialog-drawer
    id="service-search-drawer-right"
    x-model="showServiceSearchDrawer"
    :stopClickPropagation="true"
    maxWidth="4/5"
    topClass="top-[57px]"
    zIndex="44"
    onCloseClick="$wire.set('showServiceSearchDrawer', false)"
>
    <x-slot name="title">{{ __('care-plan.search_service') }}</x-slot>
    <div class="care-plan-catalog">
    @if ($showServiceSearchDrawer)
        <livewire:dictionary.service-catalog
            :legal-entity="legalEntity()"
            :selection-mode="true"
            :allow-group-selection="true"
            selection-event="care-plan-service-selected"
            :key="'care-plan-service-catalog-'.$carePlan->id"
        />
    @endif
    </div>

    <div class="mt-8">
        <button type="button" wire:click="$set('showServiceSearchDrawer', false)" class="button-minor">
            {{ __('forms.cancel') }}
        </button>
    </div>
</x-dialog-drawer>
