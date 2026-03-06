@php
    use \Carbon\Carbon;
@endphp

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-semibold text-gray-800">
                Заявка на договір
                <span class="text-gray-500">#{{ $contract->contract_number ?? 'Чернетка' }}</span>
            </h2>
            <div class="text-sm text-gray-500 mt-1">
                ID: {{ $contract->uuid }}
            </div>
        </div>
        <div class="flex gap-3">
            <a href="{{ route('contract-request.index', legalEntity()) }}" class="button-secondary flex items-center gap-2" wire:navigate>
                @icon('arrow-left', 'w-4 h-4')
                {{ __('Назад') }}
            </a>

            {{-- Кнопка редагування також тут, якщо статус дозволяє --}}
            @if($contract->status === 'NEW' || (is_object($contract->status) && $contract->status->value === 'NEW'))
                <a href="{{ route('contract-request.edit', ['legalEntity' => legalEntity()->id, 'contract' => $contract->uuid]) }}"
                   class="button-primary flex items-center gap-2"
                   wire:navigate
                >
                    @icon('pencil', 'w-4 h-4')
                    {{ __('Редагувати') }}
                </a>
            @endif
        </div>
    </div>

    {{-- Main Content --}}
    <div class="bg-white shadow sm:rounded-lg border border-gray-200">
        <div class="px-4 py-5 sm:px-6 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg leading-6 font-medium text-gray-900">
                Основна інформація
            </h3>
        </div>
        <div class="px-4 py-5 sm:p-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-6">

                <div class="sm:col-span-1">
                    <dt class="text-sm font-medium text-gray-500">Статус</dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        @if(is_object($contract->status) && method_exists($contract->status, 'label'))
                            <x-status-badge :status="$contract->status"/>
                        @else
                            {{ $contract->status }}
                        @endif
                        
                        @if($contract->status_reason)
                            <div class="mt-2 p-2 text-xs bg-yellow-50 text-yellow-800 border border-yellow-200 rounded-md">
                                <strong>Причина:</strong> {{ $contract->status_reason }}
                            </div>
                        @endif
                    </dd>
                </div>

                <div class="sm:col-span-1">
                    <dt class="text-sm font-medium text-gray-500">Тип договору</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $contract->type }}</dd>
                </div>

                <div class="sm:col-span-1">
                    <dt class="text-sm font-medium text-gray-500">Період дії</dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{-- ФІКС: Безпечний парсинг дат --}}
                        {{ $contract->start_date ? Carbon::parse($contract->start_date)->format('d.m.Y') : '-' }}
                        —
                        {{ $contract->end_date ? Carbon::parse($contract->end_date)->format('d.m.Y') : '-' }}
                    </dd>
                </div>

                <div class="sm:col-span-1">
                    <dt class="text-sm font-medium text-gray-500">Дата створення</dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{-- ФІКС: Безпечний парсинг inserted_at / created_at --}}
                        {{ $contract->inserted_at
                            ? Carbon::parse($contract->inserted_at)->format('d.m.Y H:i')
                            : ($contract->created_at ? Carbon::parse($contract->created_at)->format('d.m.Y H:i') : '-')
                        }}
                    </dd>
                </div>

                <div class="sm:col-span-2 border-t border-gray-100 pt-4 mt-2">
                    <dt class="text-sm font-medium text-gray-500 mb-2">Підстава (Contractor Base)</dt>
                    <dd class="text-sm text-gray-900 bg-gray-50 p-3 rounded">
                        {{ $contract->contractor_base ?? '-' }}
                    </dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-sm font-medium text-gray-500 mb-2">Платіжні реквізити</dt>
                    <dd class="text-sm text-gray-900 border rounded p-3">
                        @php
                            $payment = $contract->contractor_payment_details;
                            // Якщо це масив, беремо дані, якщо об'єкт JSON - перетворюємо
                            if (is_string($payment)) $payment = json_decode($payment, true);
                        @endphp
                        <p><strong>Банк:</strong> {{ $payment['bank_name'] ?? ($payment['bankName'] ?? '-') }}</p>
                        <p><strong>Рахунок (IBAN):</strong> {{ $payment['payer_account'] ?? ($payment['payerAccount'] ?? '-') }}</p>
                        <p><strong>МФО:</strong> {{ $payment['MFO'] ?? '-' }}</p>
                    </dd>
                </div>

                @if(!empty($contract->medical_programs))
                    <div class="sm:col-span-2">
                        <dt class="text-sm font-medium text-gray-500 mb-2">Медичні програми (IDs)</dt>
                        <dd class="text-sm text-gray-900 bg-gray-50 p-3 rounded text-xs font-mono">
                            @if(is_array($contract->medical_programs))
                                {{ implode(', ', $contract->medical_programs) }}
                            @else
                                {{ $contract->medical_programs }}
                            @endif
                        </dd>
                    </div>
                @endif

            </dl>
        </div>
    </div>
</div>
