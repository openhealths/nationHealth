@if (!empty($externalContractors))
    <fieldset class="fieldset">
        <legend class="legend">
            {{ __('contracts.external_contractor') }}
            <span class="show-badge-count">{{ count($externalContractors) }}</span>
        </legend>

        <div class="show-fieldset-table-wrapper">
            <table class="show-fieldset-table">
                <thead class="index-table-thead">
                    <tr>
                        <th class="index-table-th">{{ __('contracts.legal_entity_name') }}</th>
                        <th class="index-table-th">{{ __('contracts.external_contractor_number') }}</th>
                        <th class="index-table-th">{{ __('contracts.period') }}</th>
                        <th class="index-table-th">{{ __('contracts.divisions_places') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($externalContractors as $contractor)
                        @php
                            $legalEntityName = data_get($contractor, 'legal_entity.name')
                                ?? (data_get($contractor, 'legalEntityName') ?? (data_get($contractor, 'legal_entity_id') ?? '---'));
                            $contractNumber = data_get($contractor, 'contract.number') ?? '---';
                            $issuedAt = data_get($contractor, 'contract.issued_at')
                                ?? data_get($contractor, 'contract.issuedAt');
                            $expiresAt = data_get($contractor, 'contract.expires_at')
                                ?? data_get($contractor, 'contract.expiresAt');
                        @endphp
                        <tr class="index-table-tr">
                            <td class="index-table-td-primary">{{ $legalEntityName }}</td>
                            <td class="index-table-td">{{ $contractNumber }}</td>
                            <td class="index-table-td">
                                @if ($issuedAt || $expiresAt)
                                    {{ $issuedAt ?? '—' }} – {{ $expiresAt ?? '—' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="index-table-td">
                                @forelse (data_get($contractor, 'divisions', []) as $division)
                                    <div>{{ $division['name'] ?? ($division['id'] ?? '---') }}</div>
                                    @if (isset($division['medical_service']))
                                        <div class="mt-1 text-xs text-gray-500">{{ $division['medical_service'] }}</div>
                                    @endif
                                @empty
                                    —
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </fieldset>
@endif
