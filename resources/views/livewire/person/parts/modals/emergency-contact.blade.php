<div x-data="{ showEmergencyContactModal: $wire.entangle('showEmergencyContactModal') }">
    <template x-teleport="body">
        <div
            x-show="showEmergencyContactModal"
            style="display: none"
            @keydown.escape.prevent.stop="showEmergencyContactModal = false"
            role="dialog"
            aria-modal="true"
            class="modal"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div x-transition @click="showEmergencyContactModal = false" class="modal-wrapper">
                <div
                    @click.stop
                    x-trap.noscroll.inert="showEmergencyContactModal"
                    class="modal-content mx-auto w-full max-w-3xl"
                >
                    <legend class="legend">{{ __('patients.emergency_contact') }}</legend>

                    @if (empty($emergencyContact))
                        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('patients.emergency_contact_request.choose_evidence') }}
                        </p>

                        @if (empty($emergencyContactEvidences))
                            <p class="py-8 text-center text-gray-500 dark:text-gray-400">
                                {{ __('patients.emergency_contact_request.no_evidences') }}
                            </p>
                        @else
                            <table class="table-input w-full">
                                <thead class="thead-input">
                                    <tr>
                                        <th scope="col" class="th-input">
                                            {{ __('patients.emergency_contact_request.evidence_type') }}
                                        </th>
                                        <th scope="col" class="th-input">{{ __('forms.description') }}</th>
                                        <th scope="col" class="th-input">
                                            {{ __('patients.emergency_contact_request.created_at') }}
                                        </th>
                                        <th scope="col" class="th-input text-center">{{ __('forms.action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($emergencyContactEvidences as $evidence)
                                        <tr wire:key="evidence-{{ $evidence['uuid'] }}">
                                            <td class="td-input">{{ $evidence['label'] }}</td>
                                            <td class="td-input">{{ $evidence['description'] }}</td>
                                            <td class="td-input">{{ $evidence['insertedAt'] }}</td>
                                            <td class="td-input text-center">
                                                <button
                                                    type="button"
                                                    class="button-primary text-sm"
                                                    wire:click.prevent="getEmergencyContact('{{ $evidence['type'] }}', '{{ $evidence['uuid'] }}')"
                                                >
                                                    {{ __('forms.select') }}
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    @else
                        <dl class="mb-6 grid gap-4">
                            <div>
                                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('forms.first_name') }}</dt>
                                <dd class="font-bold text-gray-900 dark:text-white">
                                    {{ $emergencyContact['first_name'] }}
                                </dd>
                            </div>

                            @foreach ($emergencyContact['phones'] as $phone)
                                <div wire:key="emergency-phone-{{ $loop->index }}">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ $this->dictionaries['PHONE_TYPE'][$phone['type']] }}
                                    </dt>
                                    <dd class="font-bold text-gray-900 dark:text-white">{{ $phone['number'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    <div class="mt-12 flex gap-3">
                        <button type="button" @click="showEmergencyContactModal = false" class="button-minor">
                            {{ __('forms.close') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
