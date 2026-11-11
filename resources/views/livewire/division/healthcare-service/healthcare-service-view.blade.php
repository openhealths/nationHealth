@section('healthcare-service-header-actions')
    @can('sync', $this->healthcareService)
        <button
            type="button"
            wire:click="sync"
            wire:loading.attr="disabled"
            wire:target="sync"
            class="button-sync flex items-center gap-2 whitespace-nowrap"
        >
            @icon('refresh', 'w-4 h-4')
            <span>{{ __('forms.synchronise_with_eHealth') }}</span>
        </button>
    @endcan
@endsection

<div>
    @include('livewire.division.healthcare-service.healthcare-service')
</div>
