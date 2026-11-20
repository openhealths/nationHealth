@php
    $programOptions = [\App\Services\Dictionary\MedicationProgramPicker::DEFAULT_ID => __('care-plan.prescription_medication')] + ($dictionaries['medical_programs_medication'] ?? []);
@endphp
<x-dialog-drawer id="medications-drawer-right" x-model="showMedicationDrawer" x-data="{ step: 1, showProgramDetails: false }" x-effect="if (!showMedicationDrawer) { step = 1; showProgramDetails = false }" maxWidth="4/5" topClass="top-[57px]" zIndex="40" overlayWidth="100%" onCloseClick="showMedicationDrawer = false">
    <x-slot name="title">
        <span class="block"><span class="care-plan-service-context block">{{ $carePlan->person?->full_name }} — {{ __('care-plan.care_plan') }} №{{ $carePlan->requisition ?? $carePlan->id }}</span>{{ __('care-plan.new_medication_wizard') }}</span>
    </x-slot>
    <div class="care-plan-service-form care-plan-medication-program">
        <fieldset>
            <legend>{{ __('care-plan.program_selection') }}</legend>
            <div class="form-group group max-w-sm mb-8">
                <select id="medication_program" class="input-select peer" wire:model.live="selectedProgram" @change="showProgramDetails = false">
                    @foreach ($programOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <label for="medication_program" class="label">{{ __('care-plan.program') }}*</label>
            </div>
            <div x-show="step === 2" x-cloak>
                <div class="care-plan-program-options">
                    @foreach ($programOptions as $id => $name)
                        <div class="flex items-center gap-2">
                            <button type="button" class="care-plan-program-question" aria-label="{{ __('care-plan.show_program_details') }} — {{ $name }}" @click="showProgramDetails = ($wire.selectedProgram !== @js($id)) || !showProgramDetails; $wire.set('selectedProgram', @js($id))" :aria-expanded="showProgramDetails && $wire.selectedProgram === @js($id)">@icon('question-mark-circle', 'w-4 h-4')</button>
                            <button type="button" class="text-left text-sm" @click="$wire.set('selectedProgram', @js($id)); showProgramDetails = false">{{ $name }}</button>
                        </div>
                    @endforeach
                </div>
                <fieldset class="care-plan-program-details relative" x-show="showProgramDetails" x-cloak>
                    <legend>{{ $programOptions[$selectedProgram] ?? '' }} — {{ __('care-plan.program_details') }}</legend>
                    <button type="button" class="record-inner-action-btn absolute right-4 top-3 p-1" @click="showProgramDetails = false" aria-label="{{ __('forms.close') }}">@icon('close', 'w-5 h-5')</button>
                    <dl>
                        @forelse (($medicationProgramDetails[$selectedProgram] ?? []) as $field => $value)
                            @php
                                $labelKey = match ($field) {
                                    'mr_blank_type' => 'dictionaries.mr_blank_type',
                                    default => 'dictionaries.medication_programs.'.$field,
                                };
                            @endphp
                            <div class="mb-1"><dt class="inline font-semibold">{{ __($labelKey) }}:</dt> <dd class="inline">{{ $value }}</dd></div>
                        @empty
                            <dd>{{ __('forms.no_data') }}</dd>
                        @endforelse
                    </dl>
                </fieldset>
            </div>
        </fieldset>
        <div class="flex gap-4 pb-8">
            <button type="button" class="button-minor" @click="showMedicationDrawer = false">{{ __('forms.cancel') }}</button>
            <button type="button" class="button-primary" @click="if (step === 1) { step = 2 } else { showMedicationDrawer = false; showMedicationSearchDrawer = true }">{{ __('forms.continue') }}</button>
        </div>
    </div>
</x-dialog-drawer>
