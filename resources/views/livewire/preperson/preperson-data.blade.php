<x-layouts.patient
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :title="$uuid ? 'ID ' . strtoupper($uuid) : null"
    :activeTab="'patient-data'"
>
    @include('livewire.preperson.parts.preperson-data')
</x-layouts.patient>
