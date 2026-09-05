@use('App\Enums\Person\AuthenticationMethod')

@php
    $isThirdPerson = ($form->person['authenticationMethods'][0]['type'] ?? null)
        === AuthenticationMethod::THIRD_PERSON->value;
@endphp

<div
    x-data="{
        printContent() {
            let printWindow = window.open('https://ehealth.gov.ua/privacy_patient.html', '_blank');
            printWindow.focus();
        },
    }"
>
    <div class="text-end">
        <button
            @click="printContent()"
            type="button"
            class="mb-6 cursor-pointer text-sm font-medium underline dark:text-white"
        >
            {{ __('patients.print_leaflet_for_patient') }}
        </button>
    </div>

    <ul class="mb-8 list-inside list-disc">
        <p class="default-p">{{ __('declarations.medical_worker_confirmation') }}</p>
        <li class="default-p pl-2">{{ __('declarations.patient_identified') }}</li>
        <li class="default-p pl-2">
            {{
                $isThirdPerson
                ? __('patients.leaflet.informed_representative')
                : __('declarations.informed_about_data_processing')
            }}
        </li>
        @if ($isThirdPerson)
            <li class="default-p pl-2">{{ __('patients.leaflet.confirm_representative_authority') }}</li>
        @endif

        <p class="default-p">{{ __('declarations.patient_memo') }}</p>
        @if ($isThirdPerson)
            <p class="default-p">{{ __('patients.leaflet.third_person_intro') }}</p>
        @else
            <p class="default-p">{{ __('patients.leaflet.self_intro') }}</p>
        @endif
        <li class="default-p pl-2">{{ __('patients.leaflet.memo_informed') }}</li>
        <li class="default-p pl-2">
            {{
                $isThirdPerson
                ? __('patients.leaflet.memo_consent_third_person')
                : __('patients.leaflet.memo_consent')
            }}
        </li>
    </ul>
</div>
