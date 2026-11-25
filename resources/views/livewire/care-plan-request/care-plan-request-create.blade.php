<x-layouts.patient
    :personId="$personId"
    :patientFullName="$patientFullName"
    :hideNavigation="true"
    :showLegacyMessages="false"
>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <x-slot name="headerActions"></x-slot>

    <div class="breadcrumb-form shift-content space-y-4 p-4">
        @if ($savedRequestId !== null)
            <div class="record-inner-card space-y-4 p-4" role="status">
                <p>
                    {{ __('forms.draft') }} #{{ $savedRequestId }}
                </p>
                <a href="{{ route('persons.encounters.package.show', [legalEntity(), $personId, $encounterId]) }}" class="button-primary-outline">
                    {{ __('forms.back') }}
                </a>
            </div>
        @else
            <form
                wire:submit="save"
                method="post"
                class="record-inner-card space-y-4 p-4"
                aria-labelledby="care-plan-request-heading"
            >
                @csrf
                <h2 id="care-plan-request-heading" class="text-lg font-semibold">
                    {{ __('care-plan.create_care_plan') }} · {{ __('forms.draft') }}
                </h2>

                @if ($errors->any())
                    <p class="text-error" tabindex="-1" x-data x-init="$nextTick(() => $el.focus())">
                        {{ $errors->first() }}
                    </p>
                @endif

                <div class="form-group">
                    <label for="care-plan-request-category" class="label">
                        {{ __('care-plan.category') }} *
                    </label>
                    <select
                        id="care-plan-request-category"
                        name="category"
                        wire:model="form.category"
                        class="input-select w-full"
                        required
                        aria-invalid="{{ $errors->has('form.category') ? 'true' : 'false' }}"
                        @if ($errors->has('form.category') || $categoryOptions === []) aria-describedby="care-plan-request-category-error" @endif
                    >
                        <option value="">
                            {{ __('forms.select') }}
                        </option>
                        @foreach ($categoryOptions as $code => $label)
                            <option value="{{ $code }}">
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('form.category')
                        <p id="care-plan-request-category-error" class="text-error">
                            {{ $message }}
                        </p>
                    @enderror
                    @if ($categoryOptions === [] && !$errors->has('form.category'))
                        <p id="care-plan-request-category-error" class="text-error">
                            {{ __('care-plan.category_diagnosis_unavailable') }}
                        </p>
                    @endif
                </div>

                @foreach (['title' => 'name_care_plan', 'periodStart' => 'date_and_time_start', 'periodEnd' => 'date_and_time_end'] as $field => $label)
                    <div class="form-group">
                        <label for="care-plan-request-{{ $field }}" class="label">
                            {{ __('care-plan.' . $label) }}{{ $field !== 'periodEnd' ? ' *' : '' }}
                            @if ($field !== 'title')
                                ({{ frontendDateFormat() }})
                            @endif
                        </label>
                        <input
                            id="care-plan-request-{{ $field }}"
                            name="{{ $field }}"
                            type="text"
                            wire:model="form.{{ $field }}"
                            class="input w-full"
                            @if ($field !== 'title')
                                autocomplete="off"
                            @endif
                            @required($field !== 'periodEnd')
                            aria-invalid="{{ $errors->has('form.' . $field) ? 'true' : 'false' }}"
                            @if ($errors->has('form.' . $field)) aria-describedby="care-plan-request-{{ $field }}-error" @endif
                        />
                        @error('form.' . $field)
                            <p id="care-plan-request-{{ $field }}-error" class="text-error">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                @endforeach

                @foreach (['description' => 'extended_description', 'note' => 'notes'] as $field => $label)
                    <div class="form-group">
                        <label for="care-plan-request-{{ $field }}" class="label">
                            {{ __('care-plan.' . $label) }}
                        </label>
                        <textarea
                            id="care-plan-request-{{ $field }}"
                            name="{{ $field }}"
                            wire:model="form.{{ $field }}"
                            class="textarea w-full"
                            rows="3"
                            aria-invalid="{{ $errors->has('form.' . $field) ? 'true' : 'false' }}"
                            @if ($errors->has('form.' . $field)) aria-describedby="care-plan-request-{{ $field }}-error" @endif
                        ></textarea>
                        @error('form.' . $field)
                            <p id="care-plan-request-{{ $field }}-error" class="text-error">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                @endforeach

                <div class="flex flex-wrap gap-4">
                    <a href="{{ route('persons.encounters.package.show', [legalEntity(), $personId, $encounterId]) }}" class="button-primary-outline">
                        {{ __('forms.back') }}
                    </a>
                    <button type="submit" class="button-primary" wire:loading.attr="disabled" wire:target="save" @disabled($categoryOptions === [])>
                        {{ __('forms.save') }}
                    </button>
                    <a href="{{ route('persons.encounters.care-plans.create-and-sign', [legalEntity(), $personId, $encounterId]) }}" class="button-primary-outline">
                        {{ __('care-plan.create_care_plan') }} · {{ __('forms.sign') }}
                    </a>
                </div>
            </form>
        @endif
    </div>
</x-layouts.patient>
