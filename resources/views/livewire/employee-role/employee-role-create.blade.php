<section class="section-form">
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">{{ __('employee-roles.label') }}</x-slot>
    </x-header-navigation>

    <form class="form shift-content">
        <fieldset class="fieldset">
            <legend class="legend">{{ __('employee-roles.new') }}</legend>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="healthcareServiceId" class="label-modal">
                        {{ __('employee-roles.healthcareServiceId') }}
                    </label>

                    <select
                        wire:model="form.healthcareServiceId"
                        id="healthcareServiceId"
                        name="healthcareServiceId"
                        class="input-select peer"
                        type="text"
                        required
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach($healthcareServices as $healthcareService)
                            <option value="{{ $healthcareService['uuid'] }}">
                                {{ $healthcareService['label'] }}
                            </option>
                        @endforeach
                    </select>

                    @error('form.healthcareServiceId')
                    <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group group">
                    <label for="employeeId" class="label-modal">{{ __('employee-roles.employeeId') }}</label>

                    <select
                        wire:model="form.employeeId"
                        id="employeeId"
                        name="employeeId"
                        class="input-select peer"
                        type="text"
                        required
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach($employees as $employee)
                            <option value="{{ $employee['uuid'] }}">
                                {{ $employee['fullName'] }} - {{ $dictionaries['POSITION'][$employee['position']] }}
                            </option>
                        @endforeach
                    </select>

                    @error('form.employeeId')
                    <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

        </fieldset>

        <div class="flex gap-8">
            <a href="{{ route('employee-role.index', legalEntity()) }}" wire:navigate class="button-minor">
                {{ __('forms.cancel') }}
            </a>
            <button wire:click.prevent="create" type="submit" class="button-primary">
                {{ __('forms.create') }}
            </button>
        </div>
    </form>

    <livewire:components.x-message :key="time()" />
    <x-forms.loading />
</section>
