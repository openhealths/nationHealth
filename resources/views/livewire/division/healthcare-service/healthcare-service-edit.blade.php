@section('healthcare-service-actions')
    @can('edit', $this->healthcareService)
        <button wire:click="create" type="submit" class="button-primary">{{ __('forms.save_and_send') }}</button>
    @endcan
@endsection

<div>
    @include('livewire.division.healthcare-service.healthcare-service')
</div>
