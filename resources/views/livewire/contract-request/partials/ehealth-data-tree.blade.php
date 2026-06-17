@php
    $nodeData = $data ?? [];
    $pathPrefix = $path ?? '';
@endphp

@if(is_array($nodeData))
    <div class="space-y-3">
        @foreach($nodeData as $key => $value)
            @php
                $fullPath = $pathPrefix === '' ? (string) $key : $pathPrefix . '.' . $key;
            @endphp

            @if(is_array($value))
                <div class="border border-gray-200 rounded-md">
                    <div class="px-3 py-2 bg-gray-50 text-xs font-semibold text-gray-700 break-all">
                        {{ $fullPath }}
                    </div>
                    <div class="p-3">
                        @include('livewire.contract-request.partials.ehealth-data-tree', ['data' => $value, 'path' => $fullPath])
                    </div>
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-3 gap-2 border-b border-gray-100 pb-2">
                    <div class="text-xs font-semibold text-gray-500 break-all">{{ $fullPath }}</div>
                    <div class="md:col-span-2 text-sm text-gray-900 break-all">
                        @if(is_bool($value))
                            {{ $value ? 'true' : 'false' }}
                        @elseif($value === null)
                            ---
                        @else
                            {{ (string) $value }}
                        @endif
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@else
    <div class="text-sm text-gray-500">---</div>
@endif
