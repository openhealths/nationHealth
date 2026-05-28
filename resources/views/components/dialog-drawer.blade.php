@props(['maxWidth' => null])

@php
    $maxWidth = [
        'sm' => 'w-72 sm:w-80',
        'md' => 'w-80 sm:w-96',
        'lg' => 'w-96 sm:w-[28rem]',
        'xl' => 'w-full sm:w-[32rem]',
        '2xl' => 'w-full sm:w-[40rem]',
        '3xl' => 'w-full sm:w-[48rem]',
        '4/5' => 'w-full sm:w-4/5',
    ][$maxWidth ?? '4/5'];
@endphp

<template x-teleport="body">
    <div
        x-dialog
        x-cloak
        class="fixed inset-0 overflow-hidden z-40"
        {{ $attributes }}
    >
        <!-- Overlay backdrop -->
        <div x-dialog:overlay x-transition.opacity class="fixed inset-0 bg-black/25"></div>

        <!-- Panel Container -->
        <div class="fixed inset-y-0 right-0 max-h-screen min-h-screen flex {{ $maxWidth }}">
            <div
                x-dialog:panel
                x-transition:enter="transition ease-out duration-300 transform"
                x-transition:enter-start="translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-in duration-300 transform"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full"
                class="h-full w-full"
            >
                <div class="h-full flex flex-col bg-white dark:bg-gray-800 shadow-xl overflow-y-auto pt-20 p-8">

                    <!-- Header -->
                    @isset($title)
                        <h3 class="modal-header mb-6" x-dialog:title>
                            {{ $title }}
                        </h3>
                    @endisset

                    <!-- Slot Content -->
                    <div class="flex-1">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
