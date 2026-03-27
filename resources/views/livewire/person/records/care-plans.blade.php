<div class="form shift-content">
    <div class="flex items-center justify-between mb-6">
        <h2 class="title">{{ __('care-plan.care_plans') }}</h2>
        <a href="{{ route('carePlan.create', [legalEntity(), 'patientUuid' => $uuid]) }}" class="button-primary">
            + {{ __('care-plan.new_care_plan') }}
        </a>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('care-plan.requisition') }}</th>
                    <th>{{ __('care-plan.name_care_plan') }}</th>
                    <th>{{ __('care-plan.category') }}</th>
                    <th>{{ __('forms.status.label') }}</th>
                    <th>{{ __('forms.start_date') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($carePlans as $plan)
                    <tr>
                        <td>{{ $plan->requisition ?? '-' }}</td>
                        <td>{{ $plan->title }}</td>
                        <td>
                            @if(is_array($plan->category))
                                {{ $plan->category['text'] ?? $plan->category['coding'][0]['display'] ?? $plan->category['coding'][0]['code'] ?? '-' }}
                            @else
                                {{ $plan->category ?? '-' }}
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ in_array($plan->status, ['ACTIVE', 'active']) ? 'badge-success' : 'badge-secondary' }}">
                                @if(is_array($plan->status))
                                    {{ $plan->status['text'] ?? $plan->status['coding'][0]['display'] ?? $plan->status['coding'][0]['code'] ?? '-' }}
                                @else
                                    {{ $plan->status ?? '-' }}
                                @endif
                            </span>
                        </td>
                        <td>{{ $plan->period_start?->format('d.m.Y') ?? '-' }}</td>
                        <td class="text-right">
                            <a href="{{ route('carePlan.show', [legalEntity(), $plan->id]) }}"
                               class="text-blue-500 hover:underline text-sm">
                                {{ __('forms.show') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center py-6 text-gray-400">
                            {{ __('care-plan.no_care_plans') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
