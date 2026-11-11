@use(Illuminate\Support\Str)
<dl class="space-y-2">
    @foreach ($fields as $field => $value)
        @if ($value !== null && $value !== '' && $value !== [])
            <div class="border-b border-gray-200 pb-2 dark:border-gray-700">
                <dt class="text-sm font-medium">
                    {{ is_numeric($field) ? (int) $field + 1 : (__('referrals.resource_fields.'.$field) === 'referrals.resource_fields.'.$field ? Str::headline($field) : __('referrals.resource_fields.'.$field)) }}
                </dt>
                <dd class="pl-3 text-sm break-words">
                    @if (is_array($value))
                        @include('livewire.person.records.parts.clinical-resource-fields', ['fields' => $value])
                    @else
                        {{ is_bool($value) ? __($value ? 'forms.yes' : 'forms.no') : $value }}
                    @endif
                </dd>
            </div>
        @endif
    @endforeach
</dl>
