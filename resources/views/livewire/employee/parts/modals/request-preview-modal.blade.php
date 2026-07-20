<template x-teleport="body">
    <div x-show="showRequestPreviewModal"
         style="display: none"
         @keydown.escape.prevent.stop="showRequestPreviewModal = false"
         role="dialog"
         aria-modal="true"
         class="modal">
        <div x-show="showRequestPreviewModal" x-transition.opacity class="fixed inset-0 bg-black/25"></div>

        <div x-show="showRequestPreviewModal"
             x-transition
             @click="showRequestPreviewModal = false"
             class="relative flex min-h-screen items-center justify-center p-4">
            <div @click.stop
                 x-trap.noscroll.inert="showRequestPreviewModal"
                 class="modal-content h-fit w-full max-w-3xl rounded-2xl shadow-lg bg-white max-h-[90vh] overflow-y-auto">

                <h3 class="modal-header">{{ __('forms.employee_request_preview_title') }}</h3>

                <div class="p-6 space-y-4 text-sm text-gray-700 dark:text-gray-200">
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
                            <dd>{{ $this->dictionaries['GENDER'][$this->form->party['gender'] ?? ''] ?? ($this->form->party['gender'] ?? '—') }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.tax_id') }}</dt>
                            <dd>{{ !empty($this->form->party['noTaxId']) ? __('forms.no_tax_id') . ': ' . ($this->form->party['taxId'] ?? '—') : ($this->form->party['taxId'] ?? '—') }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.email') }}</dt>
                            <dd>{{ $this->form->party['email'] ?? ($this->formEmail ?? '—') }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.role') }}</dt>
                            <dd>{{ $this->dictionaries['EMPLOYEE_TYPE'][$this->form->employeeType] ?? ($this->form->employeeType ?: '—') }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">{{ __('forms.position') }}</dt>
                            <dd>{{ $this->dictionaries['POSITION'][$this->form->position] ?? ($this->form->position ?: '—') }}</dd>
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

                    @if(!empty($this->form->documents))
                        <div>
                            <h4 class="font-semibold mb-2">{{ __('forms.documents') }}</h4>
                            <ul class="list-disc list-inside space-y-1">
                                @foreach($this->form->documents as $document)
                                    <li>
                                        {{ $this->dictionaries['DOCUMENT_TYPE'][$document['type'] ?? ''] ?? ($document['type'] ?? '—') }}:
                                        {{ $document['number'] ?? '—' }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if(!empty($this->form->party['phones']))
                        <div>
                            <h4 class="font-semibold mb-2">{{ __('forms.phones') }}</h4>
                            <ul class="list-disc list-inside space-y-1">
                                @foreach($this->form->party['phones'] as $phone)
                                    <li>
                                        {{ $this->dictionaries['PHONE_TYPE'][$phone['type'] ?? ''] ?? ($phone['type'] ?? '—') }}:
                                        {{ $phone['number'] ?? '—' }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if(in_array($this->form->employeeType, config('ehealth.medical_employees', []), true))
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div>{{ __('forms.educations') }}: {{ count($this->form->doctor['educations'] ?? []) }}</div>
                            <div>{{ __('forms.specialities') }}: {{ count($this->form->doctor['specialities'] ?? []) }}</div>
                            <div>{{ __('forms.qualifications') }}: {{ count($this->form->doctor['qualifications'] ?? []) }}</div>
                            <div>
                                {{ __('forms.science_degree') }}:
                                {{ !empty($this->form->doctor['scienceDegree']['degree'] ?? null) ? __('forms.yes') : __('forms.no') }}
                            </div>
                        </div>
                    @endif
                </div>

                <div class="px-6 pb-6 flex flex-row items-center gap-4 border-t border-gray-200 pt-6">
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
