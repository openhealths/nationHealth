@php
    use App\Enums\User\Role;

    $user = auth()->user();
    $isEmployee = $position instanceof \App\Models\Employee\Employee;
    $isRequest = $position instanceof \App\Models\Employee\EmployeeRequest;
    $status = $position->status?->value ?? null;

    // Checking if the employee is the owner
    // We use the camelCase attribute employeeType, as in your models
    $isOwner = $isEmployee && $position->employeeType === Role::OWNER->value;

    // QUICK CHECKS
    $canView = $isEmployee ? ($permissions['employee_view'] ?? false) : ($permissions['request_view'] ?? false);
    $canWrite = $isEmployee ? ($permissions['employee_write'] ?? false) : ($permissions['request_write'] ?? false);

    // User availability condition
    $hasUserLinked = $isEmployee ? !empty($position->userId) : true;

    $showView = $canView;

    $showEdit = $canWrite && !$isOwner && ($isEmployee ? $status !== 'DISMISSED' : $status === 'NEW');

    $showSync = $canWrite && ($isEmployee ? !empty($position->uuid) : in_array($status, ['NEW', 'SIGNED', 'APPROVED']));
    $showDelete = $isRequest && $canWrite && $status === 'NEW';

    // We also prohibit the dismissal of the owner through the interface, if necessary
    $showDismiss = $isEmployee && !$isOwner && $status === 'APPROVED' && ($permissions['employee_deactivate'] ?? false);

    $hasActions = $showView || $showEdit || $showSync || $showDelete || $showDismiss;
@endphp

@if ($hasActions)
    <div class="relative flex justify-center" x-data="{ open: false }" @click.outside="open = false">
        <button @click="open = !open" type="button"
                class="inline-flex items-center p-2 text-gray-500 hover:text-gray-800 rounded-lg">
            @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-white')
        </button>

        <div x-show="open" x-cloak x-transition
             class="absolute right-0 z-50 w-48 bg-white rounded shadow-lg dark:bg-gray-700" style="display: none;">
            <ul class="py-1 text-sm text-gray-700 dark:text-gray-200">
                @if($showSync)
                    <li>

                        <button type="button" wire:click="syncOne({{ $position->id }})"
                                class="flex w-full items-center gap-2 py-2 px-5 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                            @icon('refresh', 'w-5 h-5 text-blue-500') {{ __('general.sync') }}
                        </button>
                    </li>
                @endif

                @if($showView)
                    <li>
                        <a href="{{ $isEmployee ? route('employee.show', ['legalEntity' => legalEntity()->id, 'employee' => $position->id]) : route('employee-request.show', ['legalEntity' => legalEntity()->id, 'employee_request' => $position->id]) }}"
                           class="flex items-center gap-2 py-2 px-5 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                            @icon('eye', 'w-5 h-5') {{ __('forms.view') }}
                        </a>
                    </li>
                @endif

                @if($showEdit)
                    <li>
                        @if($isEmployee && !$hasUserLinked)
                            <button type="button" wire:click="tryEdit({{ $position->id }})"
                               class="flex w-full items-center gap-2 py-2 px-5 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors text-left">
                                @icon('edit', 'w-5 h-5') {{ __('forms.edit') }}
                            </button>
                        @else
                            <a href="{{ $isEmployee ? route('employee.edit', ['legalEntity' => legalEntity()->id, 'employee' => $position->id]) : route('employee-request.edit', ['legalEntity' => legalEntity()->id, 'employee_request' => $position->id]) }}"
                               class="flex items-center gap-2 py-2 px-5 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                                @icon('edit', 'w-5 h-5') {{ __('forms.edit') }}
                            </a>
                        @endif
                    </li>
                @endif

                @if($showDismiss || $showDelete)
                    <li class="border-t border-gray-100 dark:border-gray-600 mt-1 pt-1">
                        <button type="button"
                                wire:click="{{ $showDismiss ? 'showModalDeactivate('.$position->id.')' : 'confirmRequestDeletion('.$position->id.')' }}"
                                class="flex items-center gap-2 w-full py-2 px-5 text-red-600 hover:bg-gray-100 dark:hover:bg-gray-600 text-left transition-colors">
                            @icon ('close-circle')
                            <span>{{ $showDismiss ? __('forms.dismiss') : __('forms.delete') }}</span>
                        </button>
                    </li>
                @endif
            </ul>
        </div>
    </div>
@endif
