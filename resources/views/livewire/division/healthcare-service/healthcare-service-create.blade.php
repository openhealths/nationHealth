@use('App\Models\HealthcareService')

@section('healthcare-service-actions')
    @can('create', HealthcareService::class)
        <button wire:click.prevent="createLocally" type="submit" class="button-primary-outline">
            {{ __('forms.save') }}
        </button>

        <button wire:click="create" type="submit" class="button-primary">{{ __('forms.save_and_send') }}</button>
    @endcan
@endsection

<div>
    @include('livewire.division.healthcare-service.healthcare-service')
</div>
