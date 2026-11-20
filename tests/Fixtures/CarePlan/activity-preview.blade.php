<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('care-plan.activities') }}</title>
    <style>{!! file_get_contents(resource_path('css/care-plan.css')) !!}
        body { max-width: 72rem; margin: 2rem auto; padding: 0 1rem; font: 1rem/1.5 system-ui, sans-serif; }
        fieldset { min-width: 0; } summary { cursor: pointer; }
    </style>
</head>
<body>
    <h1>{{ __('care-plan.activities') }}</h1>
    <p>{{ __('care-plan.preview_notice') }}</p>
    @foreach ($cards as $card)
        <fieldset class="care-plan-activity-section">
            <legend>{{ __('care-plan.activity_kind.'.$card['activity']->resolvedKind()) }}</legend>
            @include('livewire.care-plan.parts.activity.detail-card', $card)
        </fieldset>
    @endforeach
    <details>
        <summary>{{ __('dictionaries.service_catalog.title') }}</summary>
        @foreach ($services as $group)
            <details>
                <summary>{{ $group['code'] }} — {{ $group['name'] }}</summary>
                @foreach ($group['services'] as $service)
                    <p><label><input type="radio" name="fixture-service" value="{{ $service['id'] }}"> {{ $service['code'] }} — {{ $service['name'] }}</label></p>
                @endforeach
            </details>
        @endforeach
    </details>
</body>
</html>
