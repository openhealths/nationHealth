@props(['title' => null, 'description' => null, 'breadcrumbs' => []])

@php
if (empty($breadcrumbs)) {
    // Fallback for legacy calls: show simple 'Головна → Title' format
    $crumbs = [
        ['label' => __('Головна')],
        $title ? ['label' => $title] : null
    ];
    $crumbs = array_filter($crumbs);
} else {
    $crumbs = $breadcrumbs;
}
@endphp

<div {{ $attributes->merge(['class' => 'section-card shift-content']) }}>
    <div class="max-w-screen-xl w-full">
        <!-- Breadcrumbs at the very top -->
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <ol class="breadcrumb-list">
                @php $crumbs = array_values($crumbs); @endphp
                @foreach($crumbs as $index => $crumb)
                    @php
                        $isFirst = $index === 0;
                        $isLast = $index === count($crumbs) - 1;
                        $hasUrl = isset($crumb['url']) && filled($crumb['url']);
                    @endphp
                    <li @if($isFirst) class="breadcrumb-first" @endif @if($isLast) aria-current="page" @endif>
                        @if($isFirst)
                            <a href="{{ legalEntity() ? route('dashboard', [legalEntity()]) :  url('/dashboard') }}" class="breadcrumb-link">
                                <svg class="breadcrumb-home-icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="m19.707 9.293-2-2-7-7a1 1 0 0 0-1.414 0l-7 7-2 2a1 1 0 0 0 1.414 1.414L2 10.414V18a2 2 0 0 0 2 2h3a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1h3a2 2 0 0 0 2-2v-7.586l.293.293a1 1 0 0 0 1.414-1.414Z"/>
                                </svg>
                                {{ $crumb['label'] }}
                            </a>
                        @else
                            <div class="breadcrumb-separator">
                                <svg class="breadcrumb-chevron" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/>
                                </svg>
                                @if($hasUrl && !$isLast)
                                    <a href="{{ $crumb['url'] }}" class="breadcrumb-item-link">{{ $crumb['label'] }}</a>
                                @else
                                    <span class="breadcrumb-item-text">{{ $crumb['label'] }}</span>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>

        <!-- Title row with page title and action buttons -->
        <header class="page-header">
            <div class="w-full flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="page-header-content min-w-0">
                    @if($title)
                        <h1 class="page-title !mb-0">{{ $title }}</h1>
                    @endif
                    @if($description)
                        <p class="page-description">{{ $description }}</p>
                    @endif
                </div>

                @if(trim($slot))
                    <div class="button-group shrink-0 flex items-center gap-2">
                        {{ $slot }}
                    </div>
                @endif
            </div>
        </header>

        @isset($navigation)
            <div class="page-navigation mt-8">
                {{ $navigation }}
            </div>
        @endisset

    </div>
</div>
