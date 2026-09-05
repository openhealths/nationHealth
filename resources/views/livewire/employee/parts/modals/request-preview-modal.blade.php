<template x-teleport="body">
    <div
        x-show="showRequestPreviewModal"
        style="display: none"
        @keydown.escape.prevent.stop="showRequestPreviewModal = false"
        role="dialog"
        aria-modal="true"
        class="modal"
    >
        <div x-show="showRequestPreviewModal" x-transition.opacity class="fixed inset-0 bg-black/25"></div>

        <div
            x-show="showRequestPreviewModal"
            x-transition
            @click="showRequestPreviewModal = false"
            class="relative flex min-h-screen items-center justify-center p-4"
        >
            <div
                @click.stop
                x-trap.noscroll.inert="showRequestPreviewModal"
                class="modal-content h-fit max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white shadow-lg"
            >
                <h3 class="modal-header">{{ __('forms.employee_request_preview_title') }}</h3>

                <div class="space-y-4 p-6 text-sm text-gray-700 dark:text-gray-200">
                    <p class="text-gray-500 dark:text-gray-400">{{ __('forms.employee_request_preview_hint') }}</p>

                    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.last_name') }}</dt>
                            <dd>{{ $this->form->party['lastName'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.first_name') }}</dt>
                            <dd>{{ $this->form->party['firstName'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.second_name') }}</dt>
                            <dd>{{ $this->form->party['secondName'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.birth_date') }}</dt>
                            <dd>{{ $this->form->party['birthDate'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.gender') }}</dt>
                            <dd>
                                {{ $this->dictionaries['GENDER'][$this->form->party['gender'] ?? ''] ?? ($this->form->party['gender'] ?? '—') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.tax_id') }}</dt>
                            <dd>
                                {{ !empty($this->form->party['noTaxId']) ? __('forms.no_tax_id') . ': ' . ($this->form->party['taxId'] ?? '—') : ($this->form->party['taxId'] ?? '—') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.email') }}</dt>
                            <dd>{{ $this->form->party['email'] ?? ($this->formEmail ?? '—') }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.working_experience') }}</dt>
                            <dd>{{ $this->form->party['workingExperience'] ?? '—' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="font-medium text-gray-500">{{ __('forms.about_myself') }}</dt>
                            <dd>{{ $this->form->party['aboutMyself'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.role') }}</dt>
                            <dd>
                                {{ $this->dictionaries['EMPLOYEE_TYPE'][$this->form->employeeType] ?? ($this->form->employeeType ?: '—') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.position') }}</dt>
                            <dd>
                                {{ $this->dictionaries['POSITION'][$this->form->position] ?? ($this->form->position ?: '—') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.start_date_work') }}</dt>
                            <dd>{{ $this->form->startDate ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.division') }}</dt>
                            <dd>
                                @php
                                    $divisionName = collect($this->divisions)->firstWhere('id', (int) $this->form->divisionId)['name'] ?? null;
                                @endphp
                                {{ $divisionName ?: '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.request_status_label') }}</dt>
                            <dd>{{ $this->previewRequestStatusLabel() }}</dd>
                        </div>
                    </dl>

                    @if (!empty($this->form->documents))
                        <div>
                            <h4 class="mb-2 font-semibold">{{ __('forms.documents') }}</h4>
                            <ul class="list-inside list-disc space-y-1">
                                @foreach ($this->form->documents as $document)
                                    <li>
                                        {{ $this->dictionaries['DOCUMENT_TYPE'][$document['type'] ?? ''] ?? ($document['type'] ?? '—') }}: {{ $document['number'] ?? '—' }}
                                        @if (!empty($document['issuedAt']) || !empty($document['issuedBy']))
                                            ({{ $document['issuedAt'] ?? '—' }} / {{ $document['issuedBy'] ?? '—' }})
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (!empty($this->form->party['phones']))
                        <div>
                            <h4 class="mb-2 font-semibold">{{ __('forms.phones') }}</h4>
                            <ul class="list-inside list-disc space-y-1">
                                @foreach ($this->form->party['phones'] as $phone)
                                    <li>
                                        {{ $this->dictionaries['PHONE_TYPE'][$phone['type'] ?? ''] ?? ($phone['type'] ?? '—') }}: {{ $phone['number'] ?? '—' }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (in_array($this->form->employeeType, config('ehealth.medical_employees', []), true))
                        <div class="space-y-3">
                            <div>
                                <h4 class="mb-1 font-semibold">{{ __('forms.educations') }}</h4>
                                <ul class="list-inside list-disc space-y-1">
                                    @forelse (($this->form->doctor['educations'] ?? []) as $education)
                                        <li>
                                            {{ $education['institutionName'] ?? '—' }} ({{ $this->dictionaries['EDUCATION_DEGREE'][$education['degree'] ?? ''] ?? ($education['degree'] ?? '—') }}, {{ $this->dictionaries['SPECIALITY_TYPE'][$education['speciality'] ?? ''] ?? ($education['speciality'] ?? '—') }})
                                            @if (!empty($education['country']))
                                                — {{ $this->dictionaries['COUNTRY'][$education['country']] ?? $education['country'] }}
                                            @endif
                                            @if (!empty($education['city']))
                                                — {{ $education['city'] }}
                                            @endif
                                            @if (!empty($education['issuedDate']))
                                                — {{ $education['issuedDate'] }}
                                            @endif
                                            @if (!empty($education['diplomaNumber']))
                                                — № {{ $education['diplomaNumber'] }}
                                            @endif
                                        </li>
                                    @empty
                                        <li>—</li>
                                    @endforelse
                                </ul>
                            </div>
                            <div>
                                <h4 class="mb-1 font-semibold">{{ __('forms.specialities') }}</h4>
                                <ul class="list-inside list-disc space-y-1">
                                    @forelse (($this->form->doctor['specialities'] ?? []) as $speciality)
                                        <li>
                                            {{ $this->dictionaries['SPECIALITY_TYPE'][$speciality['speciality'] ?? ''] ?? ($speciality['speciality'] ?? '—') }}
                                            @if (!empty($speciality['specialityOfficio'])) ({{ __('forms.primary') }}) @endif
                                            @if (!empty($speciality['level']))
                                                — {{ $this->dictionaries['SPECIALITY_LEVEL'][$speciality['level']] ?? $speciality['level'] }}
                                            @endif
                                            @if (!empty($speciality['qualificationType']))
                                                — {{ $this->dictionaries['SPEC_QUALIFICATION_TYPE'][$speciality['qualificationType']] ?? ($this->dictionaries['QUALIFICATION_TYPE'][$speciality['qualificationType']] ?? $speciality['qualificationType']) }}
                                            @endif
                                            @if (!empty($speciality['attestationName']))
                                                — {{ $speciality['attestationName'] }}
                                            @endif
                                            @if (!empty($speciality['attestationDate']))
                                                — {{ $speciality['attestationDate'] }}
                                            @endif
                                            @if (!empty($speciality['certificateNumber']))
                                                — № {{ $speciality['certificateNumber'] }}
                                            @endif
                                            @if (!empty($speciality['validToDate']))
                                                — {{ __('forms.valid_until') }}: {{ $speciality['validToDate'] }}
                                            @endif
                                        </li>
                                    @empty
                                        <li>—</li>
                                    @endforelse
                                </ul>
                            </div>
                            <div>
                                <h4 class="mb-1 font-semibold">{{ __('forms.qualifications') }}</h4>
                                <ul class="list-inside list-disc space-y-1">
                                    @forelse (($this->form->doctor['qualifications'] ?? []) as $qualification)
                                        <li>
                                            {{ $this->dictionaries['QUALIFICATION_TYPE'][$qualification['type'] ?? ''] ?? ($qualification['type'] ?? '—') }} — {{ $qualification['institutionName'] ?? '—' }}
                                            @if (!empty($qualification['speciality']))
                                                — {{ $this->dictionaries['SPECIALITY_TYPE'][$qualification['speciality']] ?? $qualification['speciality'] }}
                                            @endif
                                            @if (!empty($qualification['issuedDate']))
                                                — {{ $qualification['issuedDate'] }}
                                            @endif
                                            @if (!empty($qualification['certificateNumber']))
                                                — № {{ $qualification['certificateNumber'] }}
                                            @endif
                                            @if (!empty($qualification['validTo']))
                                                — {{ __('forms.valid_until') }}: {{ $qualification['validTo'] }}
                                            @endif
                                            @if (!empty($qualification['additionalInfo']))
                                                — {{ $qualification['additionalInfo'] }}
                                            @endif
                                        </li>
                                    @empty
                                        <li>—</li>
                                    @endforelse
                                </ul>
                            </div>
                            <div>
                                <h4 class="mb-1 font-semibold">{{ __('forms.science_degree') }}</h4>
                                @php
                                    $scienceDegree = $this->form->doctor['scienceDegree'] ?? [];
                                    $hasScienceDegree = !empty($scienceDegree['degree'] ?? null);
                                @endphp
                                @if ($hasScienceDegree)
                                    <ul class="list-inside list-disc space-y-1">
                                        <li>
                                            {{ $this->dictionaries['SCIENCE_DEGREE'][$scienceDegree['degree'] ?? ''] ?? ($scienceDegree['degree'] ?? '—') }}
                                            @if (!empty($scienceDegree['speciality']))
                                                — {{ $this->dictionaries['SPECIALITY_TYPE'][$scienceDegree['speciality']] ?? $scienceDegree['speciality'] }}
                                            @endif
                                            @if (!empty($scienceDegree['institutionName']))
                                                — {{ $scienceDegree['institutionName'] }}
                                            @endif
                                            @if (!empty($scienceDegree['country']))
                                                — {{ $this->dictionaries['COUNTRY'][$scienceDegree['country']] ?? $scienceDegree['country'] }}
                                            @endif
                                            @if (!empty($scienceDegree['city']))
                                                — {{ $scienceDegree['city'] }}
                                            @endif
                                            @if (!empty($scienceDegree['issuedDate']))
                                                — {{ $scienceDegree['issuedDate'] }}
                                            @endif
                                            @if (!empty($scienceDegree['diplomaNumber']))
                                                — № {{ $scienceDegree['diplomaNumber'] }}
                                            @endif
                                        </li>
                                    </ul>
                                @else
                                    <p>{{ __('forms.no') }}</p>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                <div class="flex flex-row items-center gap-4 border-t border-gray-200 px-6 pt-6 pb-6">
                    <button type="button" @click="showRequestPreviewModal = false" class="button-minor">
                        {{ __('forms.back') }}
                    </button>
                    <button type="button" wire:click="proceedToSigning" class="button-primary">
                        {{ __('forms.proceed_to_kep_sign') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
