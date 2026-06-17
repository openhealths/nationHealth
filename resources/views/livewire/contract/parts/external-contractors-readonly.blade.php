@if(!empty($externalContractors))
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
                    @foreach($externalContractors as $contractor)
                        @php
                            $legalEntityName = data_get($contractor, 'legal_entity.name')
                                ?? data_get($contractor, 'legalEntityName')
                                ?? '---';
                            $contractNumber = data_get($contractor, 'contract.number')
                                ?? data_get($contractor, 'contract.number')
                                ?? '---';
                            $issuedAt = data_get($contractor, 'contract.issued_at')
                                ?? data_get($contractor, 'contract.issuedAt');
                            $expiresAt = data_get($contractor, 'contract.expires_at')
                                ?? data_get($contractor, 'contract.expiresAt');
                            $divisionName = data_get($contractor, 'divisions.name')
                                ?? data_get($contractor, 'divisions.name')
                                ?? '---';
                            $medicalService = data_get($contractor, 'divisions.medical_service')
                                ?? data_get($contractor, 'divisions.medicalService');
                        @endphp
                        <tr class="index-table-tr">
                            <td class="index-table-td-primary">{{ $legalEntityName }}</td>
                            <td class="index-table-td">{{ $contractNumber }}</td>
                            <td class="index-table-td">
                                @if($issuedAt || $expiresAt)
                                    {{ $issuedAt ?? '—' }} – {{ $expiresAt ?? '—' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="index-table-td">
                                <div>{{ $divisionName }}</div>
                                @if($medicalService)
                                    <div class="text-xs text-gray-500 mt-1">{{ $medicalService }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </fieldset>
@endif
