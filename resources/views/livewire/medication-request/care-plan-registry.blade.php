@use('App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest')

<section class="space-y-4 rounded-xl border p-6">
    <h3 class="font-semibold">{{ __('medication-requests.retsiepty') }}</h3>
    <form wire:submit="syncEPrescriptions" class="form-row-3">
        @foreach ($carePlanPrescriptionFilters as $filter => $value)
            <div class="form-group">
                <label class="label">{{ __('medication-requests.filters.'.$filter) }}</label
                ><input
                    class="input w-full"
                    type="{{ str_contains($filter, '_from') || str_contains($filter, '_to') ? 'date' : 'text' }}"
                    wire:model="carePlanPrescriptionFilters.{{ $filter }}"
                />
            </div>
        @endforeach
        <button class="button-sync" type="submit">{{ __('medication-requests.detail_sync') }}</button>
    </form>
    @foreach ($carePlanImportedPrescriptions as $prescription)
        <div wire:key="care-plan-rx-{{ $prescription['id'] }}" class="flex flex-wrap items-center gap-4">
            <span>{{ $prescription['request_number'] ?? $prescription['id'] }}</span
            ><span>{{ $prescription['status'] }}</span>
            <span>{{ $prescription['started_at'] }} — {{ $prescription['ended_at'] }}</span>
            <a
                class="button-minor"
                href="{{ route($prescription['_resource_type'] === MedicationRequestRequest::TYPE_REQUEST ? 'persons.prescription-requests.view' : 'persons.medication-requests.view', [legalEntity(), 'person' => $carePlan->personId, 'requestId' => $prescription['id']]) }}"
            >{{ __('forms.details') }}</a>
        </div>
    @endforeach
</section>
