<x-layouts.patient
    :personId="$personId"
    :patientFullName="$patientFullName"
    :showLegacyMessages="false"
    activeTab="referrals"
>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <div class="breadcrumb-form shift-content space-y-4 p-4">
        <form
            wire:submit="save"
            method="post"
            class="record-inner-card space-y-4 p-4"
            aria-labelledby="referral-edit-heading"
        >
            @csrf
            <h2 id="referral-edit-heading" class="text-lg font-semibold">
                {{ __('forms.edit') }} · {{ __('referrals.referral') }}
            </h2>
            @if ($errors->any())
                <p
                    class="text-error"
                    tabindex="-1"
                    x-data
                    x-init="$nextTick(() => $el.focus())"
                >
                    {{ $errors->first() }}
                </p>
            @endif
            <div class="form-group">
                <label for="referral-priority" class="label">
                    {{ __('referrals.table.priority') }} *
                </label>
                <select
                    id="referral-priority"
                    name="priority"
                    wire:model="form.priority"
                    class="input-select min-h-12 text-base"
                    required
                    aria-invalid="{{ $errors->has('form.priority') ? 'true' : 'false' }}"
                    @if ($errors->has('form.priority')) aria-describedby="referral-priority-error" @endif
                >
                    @foreach ($form->priorityOptions() as $code => $label)
                        <option value="{{ $code }}">
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('form.priority')
                    <p id="referral-priority-error" class="text-error">
                        {{ $message }}
                    </p>
                @enderror
            </div>
            @foreach (['note' => 'note', 'patientInstruction' => 'patient_instruction'] as $field => $label)
                <div class="form-group">
                    <label for="referral-{{ $field }}" class="label">
                        {{ __('referrals.registry.'.$label) }}
                    </label>
                    <textarea
                        id="referral-{{ $field }}"
                        name="{{ $field }}"
                        wire:model="form.{{ $field }}"
                        class="input min-h-24 resize-y text-base"
                        aria-invalid="{{ $errors->has('form.'.$field) ? 'true' : 'false' }}"
                        @if ($errors->has('form.'.$field)) aria-describedby="referral-{{ $field }}-error" @endif
                    ></textarea>
                    @error('form.'.$field)
                        <p id="referral-{{ $field }}-error" class="text-error">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            @endforeach
            <div class="flex flex-wrap gap-4">
                <a href="{{ route('persons.referrals', [legalEntity(), 'person' => $personId]) }}" class="button-primary-outline">
                    {{ __('forms.back') }}
                </a>
                <button type="submit" class="button-primary" wire:loading.attr="disabled" wire:target="save">
                    {{ __('forms.save') }}
                </button>
            </div>
        </form>
    </div>
</x-layouts.patient>
