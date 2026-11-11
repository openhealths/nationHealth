@use('App\Models\LegalEntity')

<section class="section-form">
    <x-header-navigation class="breadcrumb-form" title="{{ __('forms.medical_service') }}">
        <x-slot name="title">{{ __('forms.medical_service') }}</x-slot>

        @yield('healthcare-service-header-actions')
    </x-header-navigation>

    <div class="form shift-content" wire:key="{{ time() }}">
        <fieldset
            class="fieldset"
            x-data="{
                      isDisabled: $wire.entangle('isDisabled'),
                      categoryCode: $wire.entangle('form.category.coding.0.code'),
                      specialityType: $wire.entangle('form.specialityType'),
                      providingCondition: $wire.entangle('form.providingCondition'),
                      typeCode: $wire.entangle('form.type.coding.0.code'),
                      licenseId: $wire.entangle('form.licenseId'),
                      requiredCategories: @js($this->categoryRequiredFields),
                      isRequired(field) {
                          return this.requiredCategories[field].includes(this.categoryCode);
                      },
                      init() {
                          this.$watch('categoryCode', () => {
                              if (! this.isRequired('speciality')) {
                                  this.specialityType = '';
                              }

                              if (! this.isRequired('providingCondition')) {
                                  this.providingCondition = '';
                              }

                              if (! this.isRequired('type')) {
                                  this.typeCode = '';
                              }

                              if (! this.isRequired('license')) {
                                  this.licenseId = null;
                              }
                          });
                      }
                  }"
        >
            <legend class="legend">{{ __('forms.main_information') }}</legend>

            <div class="form-row-2">
                <div class="form-group group">
                    <select
                        wire:model="form.divisionId"
                        type="text"
                        name="divisionName"
                        id="divisionName"
                        required
                        class="input-select"
                        disabled
                    >
                        <option value="{{ $this->form->divisionId }}" selected>{{ $divisionName }}</option>
                    </select>

                    <label for="divisionName" class="label">{{ __('forms.division_name') }}</label>

                    @error('form.divisionId')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group group">
                    <select
                        wire:model="form.category.coding.0.code"
                        type="text"
                        name="category"
                        id="category"
                        class="input-select @error('form.category.coding.0.code') input-error @enderror"
                        required
                        x-bind:disabled="isDisabled"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach ($this->dictionaries['HEALTHCARE_SERVICE_CATEGORIES'] as $key => $category)
                            <option value="{{ $key }}">{{ $category }}</option>
                        @endforeach
                    </select>

                    <label for="category" class="label">{{ __('healthcare-services.category') }}</label>

                    @error('form.category.coding.0.code')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="form-row-2">
                <x-forms.combobox
                    :options="$this->specialityTypes"
                    bind="form.specialityType"
                    bindValue="code"
                    bindParam="name"
                    is-required="isRequired('speciality')"
                    :label="__('healthcare-services.speciality_type')"
                    x-show="isRequired('speciality')"
                    x-cloak
                    x-bind:inert="isDisabled"
                />

                <div class="form-group group" x-show="isRequired('providingCondition')" x-cloak>
                    <select
                        wire:model="form.providingCondition"
                        type="text"
                        name="providingCondition"
                        id="providingCondition"
                        class="input-select @error('form.providingCondition') input-error @enderror"
                        x-bind:required="isRequired('providingCondition')"
                        x-bind:disabled="isDisabled"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach ($this->dictionaries['PROVIDING_CONDITION'] as $key => $providingCondition)
                            <option value="{{ $key }}">{{ $providingCondition }}</option>
                        @endforeach
                    </select>

                    <label for="providingCondition" class="label">
                        {{ __('healthcare-services.providing_condition') }}
                    </label>

                    @error('form.providingCondition')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            @if (legalEntity()->type->name !== LegalEntity::TYPE_PRIMARY_CARE)
                <div class="form-row-2">
                    <div class="form-group group" x-show="isRequired('type')" x-cloak>
                        <select
                            wire:model="form.type.coding.0.code"
                            type="text"
                            name="type"
                            id="type"
                            class="input-select @error('form.type.coding.0.code') input-error @enderror"
                            x-bind:disabled="isDisabled"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach ($this->dictionaries['HEALTHCARE_SERVICE_PHARMACY_DRUGS_TYPES'] as $key => $pharmacyDrugsType)
                                <option value="{{ $key }}">{{ $pharmacyDrugsType }}</option>
                            @endforeach
                        </select>

                        <label for="type" class="label">{{ __('healthcare-services.type') }}</label>

                        @error('form.type.coding.0.code')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-group group" x-show="isRequired('license')" x-cloak>
                        <select
                            wire:model="form.licenseId"
                            type="text"
                            name="licenseId"
                            id="licenseId"
                            class="input-select @error('form.licenseId') input-error @enderror"
                            x-bind:disabled="isDisabled"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach ($licenses as $key => $license)
                                <option value="{{ $license['uuid'] }}">{{ $license['type']->label() }}</option>
                            @endforeach
                        </select>

                        <label for="licenseId" class="label">{{ __('healthcare-services.license') }}</label>

                        @error('form.licenseId')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            @endif

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
                            x-bind:disabled="isDisabled"
                        ></textarea>
                    </div>
                </div>
            </div>
        </fieldset>

        @include('livewire.division.healthcare-service.parts.working-hours')

        <div class="mt-10 flex justify-start gap-4">
            <a href="{{ url()->previous() }}" type="button" class="button-minor"> {{ __('forms.back') }} </a>

            @yield('healthcare-service-actions')
        </div>
    </div>

    <x-forms.loading />
    <livewire:components.x-message :key="now()->timestamp" />
</section>
