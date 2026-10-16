<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8" />
    <title>Пам’ятка {{ $record['requisition'] }}</title>
    <style>
        @page {
            size: A5;
            margin: 10mm;
        }
        body {
            font: 11px sans-serif;
            max-width: 128mm;
            margin: auto;
        }
        h1 {
            font-size: 17px;
        }
        dt {
            font-weight: bold;
        }
        dd {
            margin: 0 0 5px;
        }
        img {
            max-width: 100%;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
        }
    </style>
</head>
<body>
    <h1>Інформаційна пам’ятка: е-запит на медичні вироби</h1>
    <p>Пацієнт: {{ $person->fullName }}</p>
    @if (!empty($record['identity']))
        <p>
            Ідентифікаційні дані:
            @include('livewire.device-request.record-value', ['value' => $record['identity']])
        </p>
    @endif
    <p>Номер е-запиту: <strong>{{ $record['requisition'] }}</strong></p>
    <img alt="Штрихкод номера е-запиту CODE128A" src="data:image/png;base64,{{ $barcode }}" />
    @include('livewire.device-request.record-details', ['record' => $record])
    @if (!empty($record['program']))
        <p>Програма: {{ data_get($record, 'program.display_value') }}</p>
        <p>
            Джерело фінансування: {{ is_array($program['funding_source'] ?? null) ? data_get($program, 'funding_source.display_value', '') : ($program['funding_source'] ?? '') }}
        </p>
    @endif
    @if (!empty($urgent['verification_code']))
        <p>Код погашення: <strong>{{ $urgent['verification_code'] }}</strong></p>
    @endif
</body>
</html>
