@use('App\Livewire\CarePlan\CarePlanIndex')

<section class="section-form">
    <x-header-navigation x-data="{ showFilter: false }" class="breadcrumb-form">
        <x-slot name="title">
            {{ __('care-plan.care_plan') }}
        </x-slot>
        <x-slot name="actions">
            <button wire:click.prevent="sync"
                    class="button-primary-outline flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm"
                    wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="sync">🔄</span>
                <span wire:loading wire:target="sync" class="animate-spin">⏳</span>
                {{ __('patients.sync_ehealth_data') ?? 'Синхронізувати' }}
            </button>
            <a href="{{ route('care-plan.create', legalEntity()) }}" class="button-primary">
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
        <div class="index-table-wrapper">
            <table class="index-table">
                <thead class="index-table-thead">
                    <tr>
                        <th class="index-table-th">{{ __('care-plan.requisition') }}</th>
                        <th class="index-table-th">{{ __('care-plan.name_care_plan') }}</th>
                        <th class="index-table-th">{{ __('care-plan.category') }}</th>
                        <th class="index-table-th">{{ __('forms.status.label') }}</th>
                        <th class="index-table-th">{{ __('forms.start_date') }}</th>
                        <th class="index-table-th">{{ __('care-plan.patient') }}</th>
                        <th class="index-table-th"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($carePlans as $plan)
                        @php
                            /** @var \App\Models\CarePlan $plan */
                        @endphp
                        <tr class="index-table-tr">
                            <td class="index-table-td">{{ $plan['requisition'] ?? $plan->requisition ?? '-' }}</td>
                            <td class="index-table-td">{{ $plan['title'] ?? $plan->title ?? '-' }}</td>
                            <td class="index-table-td text-sm">
                                @if(is_array($plan['category'] ?? []))
                                    {{ ($plan['category'] ?? [])['text'] ?? ($plan['category'] ?? [])['coding'][0]['display'] ?? '-' }}
                                @else
                                    {{ $plan['category'] ?? $plan->category ?? '-' }}
                                @endif
                            </td>
                            <td class="index-table-td">
                                @php
                                    $status = $plan['status'] ?? $plan->status ?? '';
                                    if(is_array($status)) $status = $status['coding'][0]['code'] ?? ($status['text'] ?? '');
                                @endphp
                                <span class="badge {{ in_array(strtoupper($status), ['ACTIVE', 'active']) ? 'badge-success' : 'badge-secondary' }}">
                                    {{ $plan['status_display'] ?? $status ?? '-' }}
                                </span>
                            </td>
                            <td class="index-table-td">
                                @if(isset($plan['period']['start']))
                                    {{ \Carbon\Carbon::parse($plan['period']['start'])->format('d.m.Y') }}
                                @elseif($plan->period_start ?? null)
                                    {{ $plan->period_start->format('d.m.Y') }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="index-table-td">
                                @if(isset($plan['patient']))
                                    {{ $plan['patient']['display_name'] ?? '-' }}
                                @else
                                    {{ $plan->person?->last_name }} {{ $plan->person?->first_name }}
                                @endif
                            </td>
                            <td class="index-table-td-actions">
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
