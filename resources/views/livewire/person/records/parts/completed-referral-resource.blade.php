<x-modal wire:model="showCompletedResourceModal">
    <h2 class="p-4 text-lg font-semibold">{{ __('referrals.completed_resource') }}</h2>
    <div class="max-h-[70vh] space-y-3 overflow-y-auto p-4">
        @include('livewire.person.records.parts.clinical-resource-fields', ['fields' => $completedResource])
    </div>
    <div class="p-4">
        <button type="button" class="button-primary-outline" wire:click="$set('showCompletedResourceModal', false)">
            {{ __('forms.close') }}
        </button>
    </div>
</x-modal>
