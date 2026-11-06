@php
    use App\Models\LegalEntity;

    $isDetails ??= false;

    $legalEntityUuid = legalEntity()?->uuid ?? '';

    if(!empty($isNew)) {
        $legalEntityForm->type = collect($legalEntityTypes)->keys()->first(fn ($k) => $k !== LegalEntity::TYPE_MSP_LIMITED && $k !== legalEntity()?->type->name);
    }
@endphp

<fieldset
    class="p-4 sm:p-8 sm:pb-10 mb-16 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-[1280px]"
    xmlns="http://www.w3.org/1999/html"
    x-data="{
        title: '{{ __('forms.edrpou') }}',
        index: 1,
        isDisabled: @json(!empty(legalEntity()->id) && ($isEdit || $isDetails)),
    }"
    x-init="typeof addHeader !== 'undefined' && addHeader(title, index)"
    x-show="activeStep === index || isEdit"
    x-cloak
    :key="`step-${index}`"
>
    <template x-if="isEdit">
        <legend x-text="title" class="legend"></legend>
    </template>

    <div class='form-row-3'>
        <div class="form-group group" x-id="['edrpou']">
            <input
                required
                type="text"
                :id="$id('edrpou')"
                maxlength="10"
                placeholder=" "
                autocomplete="off"
                name="edrpou"
                wire:model="legalEntityForm.edrpou"
                aria-describedby="@error('legalEntityForm.edrpou') edrpouErrorHelp @enderror"
                class="input @error('legalEntityForm.edrpou') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
                :class="isDisabled ? 'text-gray-400 border-gray-200 dark:text-gray-500' : 'text-gray-900 border-gray-300'"
                :disabled="isDisabled"
            />

            @error('legalEntityForm.edrpou')
                <p id="edrpouErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label :for="$id('edrpou')" class="label z-10">
                {{__('forms.edrpou_rnokpp')}}
            </label>
        </div>

        <div class="form-group group">
            <select
                required
                id="lealEntityType"
                wire:model.defer="legalEntityForm.type"
                aria-describedby="@error('legalEntityForm.type') legalEntityTypeErrorHelp @enderror"
                class="input-select peer @error('legalEntityForm.type') input-error border-red-500 scroll-to-error @enderror"
                :class="isDisabled ? 'text-gray-400 border-gray-200 dark:text-gray-500 !cursor-default' : 'text-gray-900 border-gray-300'"
                :disabled="isDisabled"
            >
                @if($isEdit)
                    <option value="{{ $legalEntityForm->type }}" selected>{{ $legalEntityTypes[$legalEntityForm->type] ?? __('forms.select') }}</option>
                @endif

                @foreach($legalEntityTypes as $k => $legalEntityType)
                    @if ($k === LegalEntity::TYPE_MSP_LIMITED)
                        @continue
                    @endif

                    @if(legalEntity()?->type->name !== $k)
                        <option value="{{ $k }}" @selected($k === $legalEntityForm->type)>
                            {{ $legalEntityType }}
                        </option>
                    @endif
                @endforeach
            </select>

            @error('legalEntityForm.type')
                <p id="legalEntityTypeErrorHelp" class="text-error">{{ $message }}</p>
            @enderror

            <label for="lealEntityType" class="label z-10">
                {{ __('forms.legal_entity_type') }}
            </label>
        </div>

        {{-- Legal Entity UUID --}}
        @if($isDetails)
            <div class="form-group">
                <input
                    id="legalEntityUuid"
                    type="text"
                    placeholder=" "
                    name="legalEntityUuid"
                    class="peer input"
                    value="{{ $legalEntityUuid }}"
                    x-bind:disabled="isDisabled"
                />

                <label
                    for="legalEntityUuid"
                    class="label"
                >
                    {{ __('legal-entity.uuid') }}
                </label>
            </div>
        @endif
    </div>
</fieldset>
