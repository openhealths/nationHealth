<div class="overflow-x-auto relative" id="care-plan-section">
    <fieldset class="fieldset">
        <legend class="legend">
            <h2>{{ __('patients.care_plans') }}</h2>
        </legend>

        <div class="flex items-center justify-between mb-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('care-plan.care_plans_description_in_encounter') ?? 'Плани лікування, пов’язані з цією взаємодією.' }}
            </p>
            <a href="{{ route('carePlan.create', [legalEntity(), 'patientUuid' => $patientUuid, 'encounterUuid' => $form->encounter['uuid'] ?? '']) }}" 
               target="_blank"
               class="button-primary-outline flex items-center gap-2">
                @icon('plus', 'w-4 h-4')
                {{ __('care-plan.new_care_plan') }}
            </a>
        </div>

        {{-- List existing care plans linked to this patient (filtering by encounter if possible) --}}
        @php
            // Since we might not have a saved encounter yet, we show plans for the patient 
            // but highlight ones linked to this encounter if it exists.
            $linkedCarePlans = \App\Models\CarePlan::where('person_id', $patientId)
                ->where('encounter_id', $form->encounter['id'] ?? 0)
                ->get();
        @endphp

        @if($linkedCarePlans->count() > 0)
            <table class="table-input w-inherit">
                <thead class="thead-input">
                    <tr>
                        <th class="th-input">{{ __('care-plan.requisition') }}</th>
                        <th class="th-input">{{ __('care-plan.name_care_plan') }}</th>
                        <th class="th-input">{{ __('forms.status.label') }}</th>
                        <th class="th-input"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($linkedCarePlans as $plan)
                        <tr>
                            <td class="td-input">{{ $plan->requisition ?? '-' }}</td>
                            <td class="td-input">{{ $plan->title }}</td>
                            <td class="td-input">
                                <span class="badge {{ in_array($plan->status, ['ACTIVE', 'active']) ? 'badge-success' : 'badge-secondary' }}">
                                    {{ is_array($plan->status) ? ($plan->status['text'] ?? '-') : $plan->status }}
                                </span>
                            </td>
                            <td class="td-input text-right">
                                <a href="{{ route('carePlan.show', [legalEntity(), $plan->id]) }}" 
                                   target="_blank"
                                   class="text-blue-500 hover:underline text-sm">
                                    {{ __('forms.show') }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="text-center py-6 text-gray-400 border border-dashed border-gray-200 rounded-lg">
                {{ __('care-plan.no_care_plans') }}
            </div>
        @endif
    </fieldset>
</div>
