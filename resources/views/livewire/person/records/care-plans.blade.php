<x-layouts.patient :id="$id" :patientFullName="$patientFullName">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h2 class="title">{{ __('care-plan.care_plans') }}</h2>
            <a href="{{ route('care-plan.create', [legalEntity(), 'patientUuid' => $uuid]) }}" class="button-primary flex items-center gap-2">
                @icon('plus', 'w-4 h-4')
                {{ __('care-plan.new_care_plan') }}
            </a>
        </div>

        <div class="record-inner-card">
            <div class="record-inner-header">
                <h3>@icon('hugeicons-contracts', 'w-5 h-5 inline mr-2') {{ __('care-plan.care_plans') }}</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs uppercase bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-6 py-4 font-medium">{{ __('care-plan.name_care_plan') }}</th>
                            <th scope="col" class="px-6 py-4 font-medium">{{ __('care-plan.requisition') }}</th>
                            <th scope="col" class="px-6 py-4 font-medium">{{ __('care-plan.category') }}</th>
                            <th scope="col" class="px-6 py-4 font-medium">{{ __('forms.start_date') }}</th>
                            <th scope="col" class="px-6 py-4 font-medium">{{ __('care-plan.author') }}</th>
                            <th scope="col" class="px-6 py-4 font-medium">{{ __('forms.status.label') }}</th>
                            <th scope="col" class="px-6 py-4 font-medium text-right">{{ __('forms.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($carePlans as $plan)
                            @php
                                $status = is_array($plan->status) ? ($plan->status['coding'][0]['code'] ?? ($plan->status['text'] ?? '')) : $plan->status;
                                $statusDisplay = is_array($plan->status) ? ($plan->status['text'] ?? ($plan->status['coding'][0]['display'] ?? $status)) : $status;
                                
                                $categoryCode = is_array($plan->category) 
                                    ? ($plan->category['coding'][0]['code'] ?? null) 
                                    : $plan->category;

                                $categoryLabel = $dictionaries['care_plan_categories'][$categoryCode] 
                                    ?? (is_array($plan->category) 
                                        ? ($plan->category['text'] ?? ($plan->category['coding'][0]['display'] ?? $categoryCode)) 
                                        : $plan->category);
                            @endphp
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors" wire:key="care-plan-{{ $plan->id }}">
                                <td class="px-6 py-4">
                                    <a href="{{ route('care-plan.show', [legalEntity(), $plan->id]) }}" class="text-blue-600 dark:text-blue-400 font-semibold hover:underline">
                                        {{ $plan->title }}
                                    </a>
                                </td>
                                <td class="px-6 py-4 text-gray-600 dark:text-gray-400 font-mono text-xs">{{ $plan->requisition ?? '-' }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 border border-blue-100">
                                        {{ $categoryLabel ?? '-' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-gray-500 whitespace-nowrap">{{ $plan->period_start?->format('d.m.Y') ?? '-' }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-6 h-6 rounded-full bg-gray-100 flex items-center justify-center text-[10px] font-bold text-gray-500">
                                            {{ mb_substr($plan->author?->party?->full_name ?? '?', 0, 1) }}
                                        </div>
                                        <span class="text-gray-700 dark:text-gray-300">{{ $plan->author?->party?->full_name ?? '-' }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    @include('livewire.care-plan.parts.status-badge', ['status' => $status, 'display' => $statusDisplay])
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('care-plan.show', [legalEntity(), $plan->id]) }}" 
                                       class="text-gray-400 hover:text-blue-600 transition-colors"
                                       title="{{ __('forms.show') }}"
                                    >
                                        @icon('eye', 'w-5 h-5')
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-gray-400 italic">
                                    <div class="flex flex-col items-center gap-2">
                                        @icon('hugeicons-contracts', 'w-8 h-8 text-gray-200')
                                        {{ __('care-plan.no_care_plans') }}
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <x-forms.loading />
</x-layouts.patient>
