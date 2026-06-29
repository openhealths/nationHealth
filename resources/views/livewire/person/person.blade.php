@use('App\Models\Person\PersonRequest')
@use('App\Livewire\Person\PersonUpdate')

<div x-data="{ showUnidentifiedPatientModal: false, patientType: $wire.entangle('form.person.patientType') }">
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">{{ __('patients.add_patient') }}</x-slot>
    </x-header-navigation>

    @if($viewState === 'default')
        <section wire:key="{{ $viewState }}" class="section-form shift-content">
            <form class="form" wire:key="patient-form-{{ $formKey }}" novalidate>
                @include('livewire.person.parts.patient-type')
                @include('livewire.person.parts.person')

                <div x-show="patientType === 'unidentified'" x-cloak>
                    @include('livewire.person.parts.unidentified-contact-person')
                </div>

                <div x-show="patientType === 'identified'" x-cloak>
                    @include('livewire.person.parts.documents')
                    @include('livewire.person.parts.identity')
                    @include('livewire.person.parts.contact-data')
                    @include('livewire.person.parts.addresses')
                    @include('livewire.person.parts.emergency-contact')
                    @include('livewire.person.parts.incapacitated')
                    @if(!$this instanceof PersonUpdate)
                        @include('livewire.person.parts.authentication-methods')
                    @endif
                </div>

                <div class="flex flex-wrap gap-4 items-center">
                    @if($this instanceof PersonUpdate)
                        <a href="{{ route('persons.index', [legalEntity()]) }}" class="button-minor">
                            {{ __('forms.back') }}
                        </a>

                        @can('create', PersonRequest::class)
                            <button type="submit" wire:click.prevent="openAuthMethodModal" class="button-primary">
                                {{ __('forms.update_data') }}
                            </button>
                        @endcan
                    @else
                        <a href="{{ route('persons.index', [legalEntity()]) }}" class="button-primary-outline-red">
                            {{ __('forms.delete') }}
                        </a>

                        @can('create', PersonRequest::class)
                            <div x-show="patientType === 'unidentified'" class="flex flex-wrap gap-4 items-center" x-cloak>
                                <button type="button" @click.prevent="window.location.href = '{{ route('persons.index', [legalEntity()]) }}'" class="button-primary-outline flex items-center gap-2">
                                    @icon('archive', 'w-4 h-4')
                                    {{ __('forms.save') }}
                                </button>
                                <button type="button" @click.prevent="showUnidentifiedPatientModal = true" class="button-primary">
                                    {{ __('forms.create') }}
                                </button>
                            </div>
                            <div x-show="patientType === 'identified'" class="flex flex-wrap gap-4 items-center" x-cloak>
                                <button type="submit" wire:click.prevent="createLocally" class="button-primary-outline flex items-center gap-2">
                                    @icon('archive', 'w-4 h-4')
                                    {{ __('forms.save') }}
                                </button>
                                <button type="submit" wire:click.prevent="create" class="button-primary">
                                    {{ __('forms.create') }}
                                </button>
                            </div>
                        @endcan
                    @endif
                </div>
            </form>
        </section>

    @elseif($viewState === 'new')
        <section class="section-form">
            <form class="form">
                @include('livewire.person.parts.sign')
            </form>
        </section>
    @endif

    @if($showInformationMessageModal)
        @include('livewire.person.parts.modals.information-message')
    @endif

    @if($this instanceof PersonUpdate && $showAuthMethodModal)
        @include('livewire.person.parts.modals.choose-auth-method')
    @endif

    @if($showLeafletModal)
        @include('livewire.person.parts.modals.leaflet')
    @endif

    @include('livewire.person.parts.modals.unidentified-warning')

    @can('create', PersonRequest::class)
        <x-signature-modal method="sign" />
    @endcan

    <livewire:components.x-message :key="time()" />
    <x-forms.loading />
</div>
