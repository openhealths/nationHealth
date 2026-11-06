@use(Carbon\Carbon)

<section class="section-form">
    <livewire:components.x-message :key="time()" />
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <x-header-navigation class="breadcrumb-form flex-1 min-w-0"
            :breadcrumbs="[
                ['label' => __('forms.home'), 'url' => route('dashboard', [legalEntity()])],
                ['label' => __('patients.data_access'), 'url' => route('data-access.index', [legalEntity()])],
                ['label' => __('forms.view_details')]
            ]"
        >
            <x-slot name="title">{{ __('approval.access_details') }}</x-slot>

            <div class="mt-3 ml-0 flex flex-col gap-2 self-start sm:flex-row sm:flex-wrap">
                <button
                    type="button"
                    wire:click="sync"
                    wire:loading.attr="disabled"
                    wire:target="sync"
                    class="button-sync flex items-center gap-2 whitespace-nowrap"
                >
                    @icon('refresh', 'w-4 h-4')
                    <span>{{ __('forms.synchronise_with_eHealth') }}</span>
                </button>
            </div>
        </x-header-navigation>
    </div>

    <div class="shift-content pl-3.5 mt-8">
        @foreach ($this->resourceGroups as $group)
            <fieldset class="fieldset mb-8">
                <legend class="legend">{{ $group['title'] }}</legend>

                @forelse ($group['rows'] as $resource)
                    <div @class(['form-row-2', 'mt-4' => !$loop->first])>
                        <div class="form-group group flex flex-col">
                            <span class="label">{{ __('approval.data') }}</span>
                            <div class="input flex-1 h-auto min-h-10.5 py-2.5 wrap-break-word whitespace-normal text-sm">
                                {{ $resource['name'] }}

                                @if ($resource['date'] !== '')
                                    , {{ $resource['date'] }}
                                @endif
                            </div>
                        </div>

                        <div class="form-group group flex flex-col">
                            <span class="label">{{ __('approval.data_id') }}</span>
                            <div class="input flex-1 h-auto min-h-10.5 py-2.5 wrap-break-word whitespace-normal text-sm">
                                {{ $resource['uuid'] }}
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-sm text-gray-600 dark:text-gray-300">
                        {{ __('approval.all_records_of_type') }}
                    </div>
                @endforelse
            </fieldset>
        @endforeach

        <fieldset class="fieldset">
            <legend class="legend">{{ __('approval.additional_information') }}</legend>

            <div class="form-row-2">
                <div class="form-group group">
                    <span class="label">{{ __('approval.access_level') }}</span>
                    <div class="input h-auto min-h-10.5 py-2.5 wrap-break-word whitespace-normal text-sm">
                        {{ __('patients.'.$approval->accessLevel) }}
                    </div>
                </div>
                <div class="form-group group">
                    <span class="label">{{ __('approval.is_verified') }}</span>
                    <div class="input h-auto min-h-10.5 py-2.5 wrap-break-word whitespace-normal text-sm">
                        {{ $approval->getAttribute('is_verified') ? __('forms.yes') : __('forms.no') }}
                    </div>
                </div>
            </div>

            <div class="form-row-2 mt-4">
                <div class="form-group group">
                    <span class="label">{{ __('approval.granted_to') }}</span>
                    <div class="input h-auto min-h-10.5 py-2.5 wrap-break-word whitespace-normal text-sm">
                        {{ $approval->grantedTo?->displayValue ?? '-' }}
                    </div>
                </div>
                <div class="form-group group">
                    <span class="label">{{ __('approval.access_author') }}</span>
                    <div class="input h-auto min-h-10.5 py-2.5 wrap-break-word whitespace-normal text-sm">
                        {{ $approval->createdBy?->displayValue ?? '-' }}
                    </div>
                </div>
            </div>

            <div class="form-row-2 mt-4">
                <div class="form-group group">
                    <span class="label">{{ __('approval.access_expires') }}</span>
                    <div class="input h-auto min-h-10.5 py-2.5 wrap-break-word whitespace-normal text-sm flex items-center gap-2">
                        @if ($approval->expiresAt !== null && !$approval->expiresAt->isFuture())
                            <span class="badge-red rounded-lg">
                                {{ __('approval.expired_short') }}
                            </span>
                        @else
                            @icon('calendar', 'w-4 h-4 text-gray-400')
                            {{ $approval->expiresAt?->format('d.m.Y') ?? '-' }}
                        @endif
                    </div>
                </div>
                <div class="form-group group">
                    <span class="label">{{ __('forms.patient') }}</span>
                    <div class="input h-auto min-h-10.5 py-2.5 wrap-break-word whitespace-normal text-sm">
                        {{ $this->patient->fullName.' '.Carbon::parse($this->patient->birthDate)->format('d.m.Y').' р.н.' }}
                    </div>
                </div>
            </div>

            <div class="form-row-2 mt-4">
                <div class="form-group group">
                    <span class="label">{{ __('approval.reason') }}</span>
                    <div class="input h-auto min-h-10.5 py-2.5 wrap-break-word whitespace-normal text-sm">
                        {{ $approval->reason?->value ?? '-' }}
                    </div>
                </div>
            </div>
        </fieldset>

        <div class="mt-8">
            <a href="{{ route('data-access.index', [legalEntity()]) }}" class="button-minor inline-block">
                {{ __('forms.back') }}
            </a>
        </div>
    </div>
</section>
