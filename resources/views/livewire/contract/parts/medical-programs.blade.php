@if(isset($contract) && isset($data))
    @if(data_get($data, 'medical_programs'))
        <fieldset class="fieldset">
            <legend class="legend">{{ __('contracts.medical_programs') }}</legend>
            <div class="flex flex-wrap gap-2">
                @foreach($data['medical_programs'] as $program)
                    @php
                        $key = is_array($program) ? ($program['name'] ?? $program['id']) : $program;
                        $translationKey = 'contracts.' . strtolower($key);
                        $translatedName = __($translationKey);
                        if ($translatedName === $translationKey) {
                            $translatedName = $key;
                        }
                    @endphp
                    <span class="text-gray-900 dark:text-white">
                        {{ $translatedName }}
                    </span>
                @endforeach
            </div>
        </fieldset>
    @endif
@else
    <div class="mt-6 border-t pt-6">
        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">
            {{ __('contracts.medical_programs') }}
        </h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if(!empty($medicalProgramsList))
                @foreach($medicalProgramsList as $program)
                    <div class="relative flex items-start">
                        <div class="flex h-5 items-center">
                            <input id="program-{{ $program['id'] }}" name="medicalPrograms" type="checkbox"
                                   value="{{ $program['id'] }}" wire:model="form.medicalPrograms"
                                   class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        </div>
                        <div class="ml-3 text-sm">
                            <label for="program-{{ $program['id'] }}" class="font-medium text-gray-700">
                                {{ $program['name'] }}
                            </label>
                        </div>
                    </div>
                @endforeach
            @else
                <div class="text-sm text-gray-500">
                    {{ __('contracts.medical_programs_list_empty') }}
                </div>
            @endif
        </div>
        <x-input-error for="form.medicalPrograms" class="mt-2" />
    </div>
@endif
