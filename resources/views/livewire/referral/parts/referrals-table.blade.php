@php
    $statusLabels = [
        'active' => __('referrals.status_labels.active'),
        'completed' => __('referrals.status_labels.completed'),
        'entered_in_error' => __('referrals.status_labels.entered_in_error'),
        'draft' => __('forms.draft'),
        'revoked' => __('referrals.status_labels.revoked'),
        'new' => __('referrals.status_labels.new'),
        'in_progress' => __('referrals.status_labels.in_progress'),
        'in_queue' => __('referrals.status_labels.in_queue'),
    ];
    $stateLabels = array_replace($statusLabels, [
        'in_progress' => __('referrals.state_labels.in_progress'),
    ]);
    $badgeClasses = [
        'active' => 'badge-green',
        'new' => 'badge-green',
        'completed' => 'badge-dark bg-sky-500! text-white! dark:bg-sky-600!',
        'entered_in_error' => 'badge-red',
        'revoked' => 'badge-red',
        'draft' => 'badge-yellow',
        'in_progress' => 'badge-yellow',
        'in_queue' => 'badge-yellow',
    ];
@endphp

<div class="index-table-wrapper">
    <table class="index-table">
        <thead class="index-table-thead">
            <tr>
                <th scope="col" class="index-table-th w-[12%]">{{ __('referrals.table.created_at') }}</th>
                <th scope="col" class="index-table-th w-[17%]">{{ __('referrals.table.service') }}</th>
                <th scope="col" class="index-table-th w-[14%]">{!! __('referrals.table.patient_name_age') !!}</th>
                <th scope="col" class="index-table-th w-[9%]">{{ __('referrals.table.priority') }}</th>
                <th scope="col" class="index-table-th w-[10%]">{{ __('referrals.table.program') }}</th>
                <th scope="col" class="index-table-th w-[11%]">{{ __('referrals.status') }}</th>
                <th scope="col" class="index-table-th w-[12%] text-center!">{{ __('referrals.table.state') }}</th>
                <th scope="col" class="index-table-th w-[10%]">{{ __('referrals.table.number') }}</th>
                <th scope="col" class="index-table-th w-[5%] text-center">{{ __('forms.action') }}</th>
            </tr>
        </thead>

        @foreach ($referrals as $referral)
            @php
                $referralId = $referral['id'] ?? (string) $loop->index;
                $authoredOn = data_get($referral, 'authored_on') ?? data_get($referral, 'inserted_at');
                $createdAt = $authoredOn ? \Carbon\Carbon::parse($authoredOn) : null;
                $serviceCode = data_get($referral, 'code.coding.0.code') ?? data_get($referral, 'service.code');
                $serviceName = data_get($referral, 'code.coding.0.display')
                    ?? data_get($referral, 'code.text')
                    ?? data_get($referral, 'code.display')
                    ?? data_get($referral, 'service.name');
                $categoryCode = data_get($referral, 'category.coding.0.code') ?? '';
                $categoryKey = 'care-plan.referral_category.' . $categoryCode;
                $categoryLabel = \Illuminate\Support\Facades\Lang::has($categoryKey) ? __($categoryKey) : ($categoryCode ?: '—');
                $serviceDisplay = $serviceName ?: ($serviceCode ?: $categoryLabel);
                if ($serviceCode && $serviceName && !str_contains($serviceName, $serviceCode)) {
                    $serviceDisplay = $serviceCode . ' - ' . $serviceName;
                }
                $identity = $referral['identity'] ?? [];
                $patientName = trim(($identity['last_name'] ?? '') . ' '
                    . \Illuminate\Support\Str::substr($identity['first_name'] ?? '', 0, 1) . (empty($identity['first_name']) ? '' : '.') . ' '
                    . \Illuminate\Support\Str::substr($identity['second_name'] ?? '', 0, 1) . (empty($identity['second_name']) ? '' : '.'));
                $patientName = $patientName ?: (data_get($referral, 'subject.display') ?: '—');
                $programName = data_get($referral, 'program.name')
                    ?? data_get($referral, 'program.display')
                    ?? data_get($referral, 'medical_program.name')
                    ?? '—';
                $status = str_replace('-', '_', $referral['status'] ?? '');
                $status = $status === 'recalled' ? 'revoked' : $status;
                $programStatus = str_replace('-', '_', $referral['program_processing_status'] ?? '');
                $programStatus = $programStatus === 'recalled' ? 'revoked' : $programStatus;
                $hasActions = ($this->canTakeIntoWork($referral) && auth()->user()?->can('service_request:makeinprogress'))
                    || ($this->canManageUsage($referral) && (auth()->user()?->can('service_request:complete') || auth()->user()?->can('service_request:use')));
                $isDraft = $status === 'draft';
                $isIssuedReferral = $this->canManageIssuedReferral($referral);
                $hasActions = $hasActions || $isDraft || $isIssuedReferral;
            @endphp

            <tbody wire:key="referral-{{ $referralId }}">
                <tr class="index-table-tr">
                    <td class="index-table-td">
                        @if ($createdAt)
                            <div>{{ $createdAt->format('d.m.Y') }}</div>
                            <div>{{ $createdAt->format('H:i') }}</div>
                        @else
                            —
                        @endif
                    </td>
                    <td class="index-table-td-primary">{{ $serviceDisplay }}</td>
                    <td class="index-table-td">
                        <div>{{ $patientName }}</div>
                        @if (isset($identity['age']))
                            <div>{{ __('referrals.search_age', ['age' => $identity['age']]) }}</div>
                        @endif
                    </td>
                    <td class="index-table-td">
                        {{ __('referrals.priorities.' . ($referral['priority'] ?? 'unknown')) }}
                    </td>
                    <td class="index-table-td">{{ $programName }}</td>
                    @foreach (['status' => $status, 'state' => $programStatus] as $badgeColumn => $badgeStatus)
                        <td class="index-table-td {{ $badgeColumn === 'state' ? 'text-center!' : '' }}">
                            @if ($badgeStatus !== '')
                                <span class="{{ $badgeClasses[$badgeStatus] ?? 'badge-dark' }} me-0! text-center leading-tight {{ $badgeStatus === 'entered_in_error' && $badgeColumn === 'status' ? 'inline-block max-w-full break-normal!' : 'inline-flex items-center justify-center whitespace-nowrap!' }}">
                                    {{ ($badgeColumn === 'state' ? $stateLabels : $statusLabels)[$badgeStatus] ?? $badgeStatus }}
                                </span>
                            @else
                                —
                            @endif
                        </td>
                    @endforeach
                    <td class="index-table-td">
                        {{ $referral['requisition'] ?? $referral['request_number'] ?? $referral['id'] ?? '—' }}
                    </td>
                    <td class="index-table-td-actions">
                        @if ($hasActions)
                            @include('livewire.referral.parts.actions-dropdown')
                        @else
                            <button
                                type="button"
                                data-referral-action
                                disabled
                                aria-label="{{ __('forms.view_details') }}"
                                class="hover:text-primary cursor-pointer"
                            >
                                @icon('eye', 'svg-hover-action w-6 h-6 text-gray-800 dark:text-gray-300')
                            </button>
                        @endif
                    </td>
                </tr>
            </tbody>
        @endforeach
    </table>
</div>
