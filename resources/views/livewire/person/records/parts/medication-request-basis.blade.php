@if (!$isSearchMode && !empty($record['carePlanId']))
    <a
        href="{{ route('care-plans.show', [legalEntity(), $record['carePlanId']]) }}"
        class="text-primary hover:underline"
    >
        {{ $record['basisLabel'] }}
    </a>
@elseif (!$isSearchMode && !empty($record['encounterId']))
    <a
        href="{{ route('persons.encounters.package.show', [legalEntity(), 'person' => $personId, 'encounter' => $record['encounterId']]) }}"
        class="text-primary hover:underline"
    >
        {{ $record['basisLabel'] }}
    </a>
@else
    {{ $record['basisLabel'] ?? '—' }}
@endif
