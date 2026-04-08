@use('App\Livewire\CarePlan\CarePlanIndex')

<section class="section-form">
    <x-header-navigation x-data="{ showFilter: false }" class="breadcrumb-form">
        <x-slot name="title">
            {{ __('care-plan.care_plan') }}
        </x-slot>
        <x-slot name="actions">
            <a href="{{ route('carePlan.create', legalEntity()) }}" class="button-primary">
                + {{ __('care-plan.new_care_plan') }}
            </a>
        </x-slot>
    </x-header-navigation>

    <div class="form shift-content">
        {{-- Search by Requisition --}}
        <div class="flex items-center gap-3 mb-6">
            <input type="text"
                   wire:model="searchRequisition"
                   class="input peer"
                   placeholder="{{ __('care-plan.search_by_requisition') }}"
            />
            <button type="button"
                    wire:click="searchByRequisition"
                    class="button-primary-outline"
            >
                {{ __('forms.search') }}
            </button>
        </div>

        {{-- Plans Table --}}
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('care-plan.requisition') }}</th>
                        <th>{{ __('care-plan.name_care_plan') }}</th>
                        <th>{{ __('care-plan.category') }}</th>
                        <th>{{ __('forms.status') }}</th>
                        <th>{{ __('forms.start_date') }}</th>
                        <th>{{ __('care-plan.patient') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($carePlans as $plan)
                        @php
                            /** @var \App\Models\CarePlan $plan */
                        @endphp
                        <tr>
                            <td>{{ $plan['requisition'] ?? $plan->requisition ?? '-' }}</td>
                            <td>{{ $plan['title'] ?? $plan->title ?? '-' }}</td>
                            <td>{{ $plan['category'] ?? $plan->category ?? '-' }}</td>
                            <td>
                                <span class="badge {{ in_array($plan['status'] ?? $plan->status ?? '', ['ACTIVE', 'active']) ? 'badge-success' : 'badge-secondary' }}">
                                    {{ $plan['status'] ?? $plan->status ?? '-' }}
                                </span>
                            </td>
                            <td>
                                @if(isset($plan['period']['start']))
                                    {{ \Carbon\Carbon::parse($plan['period']['start'])->format('d.m.Y') }}
                                @elseif($plan->period_start ?? null)
                                    {{ $plan->period_start->format('d.m.Y') }}
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                @if(isset($plan['patient']))
                                    {{ $plan['patient']['display_name'] ?? '-' }}
                                @else
                                    {{ $plan->person?->last_name }} {{ $plan->person?->first_name }}
                                @endif
                            </td>
                            <td>
                                @if(isset($plan->id))
                                    <a href="{{ route('carePlan.show', [legalEntity(), $plan->id]) }}"
                                       class="text-blue-500 hover:underline text-sm">
                                        {{ __('forms.show') }}
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-6 text-gray-400">
                                {{ __('care-plan.no_care_plans') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
