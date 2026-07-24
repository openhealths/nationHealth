@if(isset($contract) && isset($data))
    @if(data_get($data, 'medical_programs') || !empty($contract->medical_programs))
        <fieldset class="fieldset">
            <legend class="legend">{{ __('contracts.medical_programs') }}</legend>
            <div class="flex flex-wrap gap-2">
                @foreach(data_get($data, 'medical_programs', $contract->medical_programs ?? []) as $program)
                    @php
                        $programId = is_array($program) ? ($program['id'] ?? null) : $program;
                        $programName = is_array($program) ? ($program['name'] ?? null) : null;

                        if (!$programName && $programId) {
                            $programName = ($medicalProgramNames ?? [])[$programId] ?? null;
                        }

                        $displayName = $programName ?? $programId;
                    @endphp
                    <span class="text-gray-900 dark:text-white">
                        {{ $displayName }}
                    </span>
                @endforeach
            </div>
        </fieldset>
    @endif
@else
    <fieldset class="fieldset">
        <legend class="legend">
            <h2>{{ __('contracts.medical_programs') }}</h2>
        </legend>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if(!empty($medicalProgramsList))
                @foreach($medicalProgramsList as $program)
                    <div class="flex items-center">
                        <input id="program-{{ $program['id'] }}"
                               name="medicalPrograms"
                               type="checkbox"
                               value="{{ $program['id'] }}"
                               wire:model="form.medicalPrograms"
                               class="default-checkbox cursor-pointer"
                        >
                        <label for="program-{{ $program['id'] }}"
                               class="ml-3 text-sm font-medium text-gray-900 dark:text-gray-300 cursor-pointer"
                        >
                            {{ $program['name'] }}
                        </label>
                    </div>
                @endforeach
            @else
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('contracts.medical_programs_list_empty') }}
                </div>
            @endif
        </div>
        <x-input-error for="form.medicalPrograms" class="mt-2" />
    </fieldset>
@endif
