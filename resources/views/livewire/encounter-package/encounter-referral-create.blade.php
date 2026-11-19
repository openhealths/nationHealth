@php
    $prototypeEnabled = (bool) ($referralPrototypeUi ?? (app()->environment('local') && ($referralId ?? null) === null));
    $parameters = ['legalEntity' => $legalEntityId, 'person' => $personId, 'encounter' => $encounterId];
    $prototypeContext = $prototypeEnabled ? [
        'key' => 'referral-preview:'.$legalEntityId.':'.$personId.':'.$encounterId,
        'createUrl' => route('persons.encounters.referrals.create', $parameters, false),
        'reviewUrl' => route('persons.encounters.referrals.preview', $parameters, false),
        'pieceUnit' => __('referrals.pcs'),
        'consultationLabel' => __('encounters.referral_category.consultation'),
        'demoReferrals' => [
            ['quantity' => 2, 'service' => ['id' => 'preview-endocrinology', 'code' => 'T67002', 'name' => __('encounters.referral_layout.preview_endocrinology'), 'category' => 'consultation', 'is_demo' => true]],
            ['quantity' => 1, 'service' => ['id' => 'preview-psychiatry', 'code' => 'T67003', 'name' => __('encounters.referral_layout.preview_psychiatry'), 'category' => 'consultation', 'is_demo' => true]],
        ],
        'messages' => [
            'serviceRequired' => __('encounters.referral_layout.preview_service_required'),
            'quantityError' => __('encounters.referral_layout.preview_quantity_error'),
            'signatureRequired' => __('encounters.referral_layout.preview_signature_required'),
            'storageError' => __('encounters.referral_layout.preview_storage_error'),
            'noReferrals' => __('encounters.referral_layout.preview_no_referrals'),
            'assistantError' => str_replace('\\"', '"', __('referrals.assistant_error')),
        ],
    ] : null;
    $prototypeBreadcrumbs = $prototypeEnabled ? [
        ['label' => __('forms.home'), 'url' => route('dashboard', ['legalEntity' => $legalEntityId])],
        ['label' => __('patients.patients'), 'url' => route('persons.index', ['legalEntity' => $legalEntityId])],
        ['label' => $patientFullName, 'url' => route('persons.patient-data', ['legalEntity' => $legalEntityId, 'person' => $personId])],
        ['label' => __('referrals.new_referral')],
    ] : [];
@endphp
<div
    @if ($prototypeEnabled)
        x-data="referralPrototype(@js($prototypeContext))"
        data-referral-prototype-root
        @referral-prototype-submit.stop="createPreview($event.detail)"
        @referral-prototype-save.stop="saveForm($event.detail)"
        @referral-prototype-form-ready.stop="formReady($event.detail.form)"
        @referral-prototype-discard.stop="discard()"
        @popstate.window="handleHistory()"
    @endif
>
    @if ($prototypeEnabled)
        @include ('livewire.encounter-package.parts.referral-prototype-state')
    @endif
    <x-layouts.patient
        :breadcrumbs="$prototypeBreadcrumbs"
        :personId="$personId"
        :patientFullName="$patientFullName"
        :hideNavigation="true"
        :showLegacyMessages="false"
    >
        <x-slot name="title">
            @if ($prototypeEnabled)
                <span
                    x-text="stage === 'review' ? @js(__('referrals.creation_title').' - '.$patientFullName) : @js(__('patients.referrals').' - '.$patientFullName)"
                    >{{ __('patients.referrals').' - '.$patientFullName }}</span
                >
            @else
                {{ __('patients.referrals').' - '.$patientFullName }}
            @endif
        </x-slot>
        <x-slot name="headerActions"></x-slot>
        <livewire:components.x-message
            :consume-messages="true"
            :key="(string) str()->uuid()"
        />
        <div
            class="breadcrumb-form shift-content p-4"
            @if ($prepareReferralForSigning && !$prototypeEnabled) wire:init="prepareSavedReferralForSigning" @endif
        >
            <div
                class="mt-4 w-full max-w-screen-xl"
                @if ($prototypeEnabled)
                    x-show="stage === 'form'"
                    data-referral-prototype-form
                @endif
            >
                @if ($referralReadOnly)
                    <p role="status">{{ $referralSummary['status'] }} {{ $referralSummary['number'] }}</p>
                @endif
                @include ('livewire.encounter-package.parts.encounter-referral-form', [
                    'encounterReturnUrl' => $this->encounterUrl, 'referralReadonly' => $referralReadOnly,
                    'referralPrototypeEnabled' => $prototypeEnabled,
                ])
            </div>
            @if ($prototypeEnabled)
                <template x-if="stage === 'review'">
                    @include ('livewire.encounter-package.parts.encounter-referral-review')
                </template>
            @endif
        </div>
        @if ($prototypeEnabled)
            <x-electronic-signature-drawer
                model="showSignatureDrawer"
                :preview="true"
                idPrefix="referralPreview"
            >
                <x-slot name="header">
                    <livewire:components.header
                        :key="'referral-preview-signature-header'"
                    />
                </x-slot>
            </x-electronic-signature-drawer>
            <template x-teleport="body">
                <x-modal
                    id="referralPreviewError"
                    maxWidth="3xl"
                    alpineModel="showErrorModal"
                >
                    <section
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="referralPreviewErrorTitle"
                        class="p-6 sm:p-12"
                    >
                        <h2
                            id="referralPreviewErrorTitle"
                            class="text-2xl font-bold text-gray-900 dark:text-white"
                        >
                            {{ __('forms.error') }}
                        </h2>
                        <div
                            role="alert"
                            class="mt-8 flex items-center gap-3 rounded-lg bg-red-50 p-4 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-200"
                        >
                            @icon ('alert-circle', 'h-5 w-5 shrink-0')
                            <p x-text="errorMessage"></p>
                        </div>
                        <button
                            type="button"
                            @click="returnToForm()"
                            class="button-primary mt-8"
                        >
                            {{ __('referrals.return_to_en') }}
                        </button>
                    </section>
                </x-modal>
            </template>
        @else
            <x-signature-modal method="sign" />
        @endif
    </x-layouts.patient>
</div>
