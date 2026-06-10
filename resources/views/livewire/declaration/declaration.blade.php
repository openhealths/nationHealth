@use('App\Enums\Declaration\Status')
@use('App\Models\DeclarationRequest')
@use('App\Livewire\Declaration\DeclarationCreate')


<section class="section-form">
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">
            {{ $isNeedToResign ? __('declarations.simplified_declaration_creation') : __('declarations.application_for_registration_of_declaration') }} - {{ $patientFullName }}
        </x-slot>
    </x-header-navigation>
    <form class="form shift-content pl-3.5 mt-8">
        @include('livewire.declaration.parts.main-information')

        @if (!$isNeedToResign)
            @include('livewire.declaration.parts.authentication')
        @endif

        <div class="flex gap-8">
            <a href="{{ url()->previous() }}" type="submit" class="button-minor">
                {{ __('forms.cancel') }}
            </a>
            @can('create', DeclarationRequest::class)
                @if($this instanceof DeclarationCreate && $status === Status::DRAFT)
                    <button wire:click.prevent="createLocally" type="submit" class="button-primary-outline">
                        {{ __('forms.create_locally') }}
                    </button>
                @endif
                <button
                    wire:click.prevent="{{
                        $isNeedToResign && $status === Status::NEW
                            ? 'approveSimplifiedDeclaration'
                            : ($status === Status::NEW
                                ? 'openMessageInformationModal'
                                : ($status === Status::APPROVED
                                    ? 'openSignatureModal'
                                    : 'create'
                                )
                            )
                    }}"
                    type="submit"
                    class="button-primary"
                >
                    {{ $status === Status::NEW
                        ? __('declarations.approve_declaration_request')
                        : ($status === Status::APPROVED
                            ? __('declarations.sign_declaration_request')
                            : __('declarations.create_an_application')
                        ) 
                    }}
                </button>
            @endcan
        </div>

        @if($showInformationMessageModal && !$isNeedToResign)
            @include('livewire.declaration.modals.information-message')
        @endif

        @if ($isNeedToPersonUpdate)
            @include('livewire.declaration.modals.person-missed-data-message')
        @endif

        @if($showAuthModal && !$isNeedToResign)
            @include('livewire.declaration.modals.authentication')
        @endif

        @if ($isNeedToPersonUpdate)
            @include('livewire.declaration.modals.person-missed-data-message')
        @endif

        @if($showUploadingDocumentsModal)
            @include('livewire.declaration.modals.uploading-documents')
        @endif

        @if($showSignModal)
            @include('livewire.declaration.modals.sign')
        @endif

        <x-signature-modal method="sign" />
    </form>

    <livewire:components.x-message :key="time()" />
    <x-forms.loading />
</section>
