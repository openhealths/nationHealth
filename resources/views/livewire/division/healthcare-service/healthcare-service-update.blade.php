@use('App\Models\HealthcareService')

<section class="section-form">
    <x-header-navigation class="breadcrumb-form" title="{{ __('forms.medical_service') }}">
        <x-slot name="title">{{ __('forms.medical_service') }}</x-slot>
    </x-header-navigation>

    <fieldset class="fieldset shift-content">
        <div class="form-row">
            <div>
                <label for="comment" class="label-modal">{{ __('forms.comment') }}</label>
                <div>
                    <textarea
                        wire:model="form.comment"
                        rows="4"
                        id="comment"
                        name="comment"
                        class="textarea"
                        placeholder="{{ __('forms.write_comment_here') }}"
                    ></textarea>
                </div>
            </div>
        </div>

        @include('livewire.division.healthcare-service.parts.working-hours')
    </fieldset>

    <div class="mt-10 flex justify-start gap-4">
        <a href="{{ url()->previous() }}" type="button" class="button-minor"> {{ __('forms.back') }} </a>

        @if ($canUpdate)
            <button wire:click.prevent="update" type="submit" class="button-primary">{{ __('forms.update') }}</button>
        @endif
    </div>

    <x-forms.loading />
    <livewire:components.x-message :key="now()->timestamp" />
</section>
