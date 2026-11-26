@use('App\Enums\CarePlanStatus')
@use('App\Enums\Person\DeviceRequestStatus')
@use('App\Enums\Person\MedicationRequestStatus')
@use('App\Enums\Person\ServiceRequestStatus')
@use('Carbon\Carbon')
@use('Illuminate\Support\Facades\Lang')

@php
    $isDevice = $resolvedKind === 'device_request';
    $isSupply = $isDevice || $resolvedKind === 'medication_request';
    $normalizedStatus = CarePlanStatus::normalize($activity->status);
    $cardStatus = Lang::has('care-plan.activity_card_status.'.$normalizedStatus)
        ? __('care-plan.activity_card_status.'.$normalizedStatus) : $activityStatusDisplay;
    $cardStatusColor = match ($normalizedStatus) {
        'draft', 'new', 'scheduled' => 'badge-yellow',
        'completed' => $isSupply ? 'badge-blue' : 'badge-green',
        'cancelled', 'entered-in-error' => 'badge-red',
        default => 'badge-green',
    };
    $documents = collect($resolvedKind === 'medication_request' ? ($activePrescriptions ?? []) : ($activeReferrals ?? []))->filter(
        fn ($item) => $activity->uuid && ($item['based_on_uuid'] ?? null) === $activity->uuid
    )->values();
    $remaining = $remainingValue;
@endphp
<article @class(['care-plan-activity-card', 'is-medication' => $isSupply]) x-data="{ showMore: false }">
    <div class="care-plan-activity-card-header">
        <div>
            <span class="care-plan-card-label">{{ __($isSupply ? 'care-plan.medication_remaining_label' : 'care-plan.available_quantity') }}</span>
            <span>{{ $remaining ?? '-' }}@if ($remaining !== null && $remainingUnitLabel !== $quantityUnitLabel) {{ $remainingUnitLabel }}@endif / {{ $quantityValue ?? '-' }} {{ $resolvedKind === 'service_request' && $quantityUnitCode === 'PIECE' ? __('care-plan.services_count') : $quantityUnitLabel }}</span>
        </div>
        <div class="care-plan-card-product">
            <span class="care-plan-card-label">{{ __($isDevice ? 'care-plan.medical_device' : ($resolvedKind === 'medication_request' ? 'care-plan.medication' : 'forms.name')) }}</span>
            <strong>{{ $productLabel ?: $translatedKind }}</strong>
        </div>
        <div>
            <span class="care-plan-card-label">{{ __('care-plan.status_label') }}</span>
            <span class="{{ $cardStatusColor }}">{{ $cardStatus }}</span>
        </div>
        <div class="care-plan-card-actions">
            @include($activityActionsView ?? 'livewire.care-plan.parts.activity.actions-menu')
        </div>
    </div>
    <div class="care-plan-activity-card-body">
        <dl class="care-plan-card-details">
            <div>
                <dt>{{ __('care-plan.expected_result') }}</dt><dd>{{ $goals !== [] ? implode(', ', $goals) : '-' }}</dd>
                <dt>{{ __('care-plan.grounds_for_prescription') }}</dt><dd>{{ $activity->reasonCode ? $activity->reasonCode.' - '.$reasonCodeLabel : '-' }}</dd>
            </div>
            <div>
                <dt>{{ __('care-plan.program') }}</dt><dd>{{ $programLabel ?: '-' }}</dd>
                <dt>{{ __('care-plan.justification_of_grounds') }}</dt>
                <dd>@forelse ($reasonReferences as $reference)<div class="care-plan-card-ground" title="{{ $reference }}">{{ $reference }}</div>@empty - @endforelse</dd>
            </div>
            <div>
                <dt>{{ __('care-plan.daily_quantity_and_unit') }}</dt><dd>{{ $dailyAmountValue !== null ? $dailyAmountValue.' '.$dailyAmountUnit.'/'.__('care-plan.per_day_short') : '-' }}</dd>
                <dt>{{ __('care-plan.extended_description') }}</dt><dd>{{ $activity->description ?: '-' }}</dd>
            </div>
            <div>
                @if ($isSupply)
                    <dt>{{ __($isDevice ? 'care-plan.planned_remaining_device' : 'care-plan.planned_remaining_medication') }}</dt><dd>{{ $quantityValue ?? '-' }} {{ $quantityUnitLabel }} / {{ $remaining ?? '-' }} {{ $remainingUnitLabel }}</dd>
                @endif
                <dt>{{ __('care-plan.status_reason') }}</dt><dd>{{ $statusReason ?: '-' }}</dd>
            </div>
        </dl>
        <dl class="care-plan-card-meta">
            <dt>{{ __('care-plan.author') }}</dt><dd>{{ $authorName ?: '-' }}</dd>
            <dt>{{ __('care-plan.scheduled_period') }}</dt><dd>{{ $activity->scheduledPeriodStart?->format('d.m.Y') ?: '-' }}–{{ $activity->scheduledPeriodEnd?->format('d.m.Y') ?: '-' }}</dd>
            <dt>ID</dt><dd class="break-all">{{ $activity->uuid ?: $activity->id }}</dd>
        </dl>
    </div>
    @if ($documents->isNotEmpty())
        <div class="care-plan-card-documents">
            @foreach ($documents as $document)
                @php
                    $documentStatus = ServiceRequestStatus::resolve($document['status'] ?? null);
                    $documentState = match ($documentStatus?->value) {
                        'active', 'in_queue', 'in_progress', 'processing', 'processed' => ['badge-green', __('care-plan.document_active')],
                        'completed', 'used' => ['badge-blue', __('care-plan.document_completed')],
                        default => [ServiceRequestStatus::colorFor($document['status'] ?? null), ServiceRequestStatus::labelFor($document['status'] ?? null)],
                    };
                @endphp
                <dl class="care-plan-card-document" @if ($loop->index > 1) x-show="showMore" x-cloak @endif>
                    <div><dt>{{ __($isDevice ? 'device-requests.labels.request_number' : ($resolvedKind === 'medication_request' ? 'care-plan.eprescription_number' : 'care-plan.referral_number')) }}</dt><dd>{{ $document['request_number'] ?? $document['requisition'] ?? $document['uuid'] }}</dd></div>
                    <div><dt>{{ __($isDevice ? 'care-plan.device_request_quantity' : ($resolvedKind === 'medication_request' ? 'care-plan.eprescription_quantity' : 'care-plan.created_available')) }}</dt><dd>@if ($isSupply){{ $document['medication_qty'] ?? $document['quantity'] ?? '-' }} {{ $quantityUnitLabel }}@else{{ $document['quantity'] ?? '-' }}/{{ $document['remaining_quantity'] ?? '-' }}@endif</dd></div>
                    <div><dt>{{ __('care-plan.creation_date') }}</dt><dd>{{ !empty($document['created_at']) ? Carbon::parse($document['created_at'])->format('d.m.Y') : '-' }}</dd></div>
                    @if (!$isSupply)<div><dt>{{ __('care-plan.document_state') }}</dt><dd><span class="{{ $documentState[0] }}">{{ $documentState[1] }}</span></dd></div>@endif
                    <div><dt>{{ __('care-plan.status_label') }}</dt><dd><span class="{{ in_array($document['status'] ?? '', ['cancelled', 'recalled', 'revoked', 'entered-in-error']) ? 'badge-red' : (($document['status'] ?? '') === 'completed' ? 'badge-blue' : 'badge-green') }}">{{ $resolvedKind === 'medication_request' ? MedicationRequestStatus::labelFor($document['status'] ?? null) : ($document['status_label'] ?? ($isDevice ? DeviceRequestStatus::labelFor($document['status'] ?? null) : ServiceRequestStatus::labelFor($document['status'] ?? null))) }}</span></dd></div>
                    <div><dt>{{ __('care-plan.encounter_link') }}</dt><dd>{{ $document['encounter_label'] ?? '-' }}</dd></div>
                </dl>
            @endforeach
            @if ($documents->count() > 2)
                <button type="button" class="care-plan-show-more" @click="showMore = !showMore" :aria-expanded="showMore" x-text="showMore ? @js(__('care-plan.show_less')) : @js(__('care-plan.show_more'))">{{ __('care-plan.show_more') }}</button>
            @endif
        </div>
    @endif
</article>
