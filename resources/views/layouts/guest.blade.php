<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Styles -->
        @once
            @livewireStyles
        @endonce

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <script>
            // Flowbite's recommendation: On page load or when changing themes, best to add inline in `head` to avoid FOUC
            if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark')
            }
        </script>
    </head>
    <body>
        <div class="antialiased bg-white dark:bg-gray-800">
            <x-forms.loading :global="true" />
            <main class="bg-gray-50 dark:bg-gray-900">
                <div class="flex flex-col items-center justify-center px-6 mx-auto md:h-screen pt:mt-0 dark:bg-gray-900">
                    @hasSection('content')
                        @yield('content')
                    @else
                        {{ $slot ?? '' }}
                    @endif
                </div>
            </main>

            <!-- Scripts -->
            @once
                @livewireScripts
            @endonce

            @livewire('components.flash-message')

            @stack('scripts')

            @yield('scripts')
        </div>
    </body>
</html>
