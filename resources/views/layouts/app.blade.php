<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <script>
            // Flowbite's recommendation: On page load or when changing themes, best to add inline in `head` to avoid FOUC
            (function() {
                const theme = localStorage.getItem('color-theme');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (theme === 'dark' || (!theme && prefersDark)) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            })();
        </script>

        @once
            @livewireStyles
        @endonce

        @vite(['resources/css/app.css'])

        <!-- Alpine plugins -->

        <script defer src="https://unpkg.com/@alpinejs/focus@3.x.x/dist/cdn.min.js"></script>
    </head>

    <body>
        <div class="antialiased bg-white dark:bg-gray-800">

            @livewire('components.header')

            @livewire('components.sidebar')

            <main id="main-content" class="p-4 md:ml-64 h-auto pt-20">
                @hasSection('content')
                    @yield('content')
                @else
                    {{ $slot ?? '' }}
                @endif
            </main>

            @once
                @livewireScripts
            @endonce

            @stack('modals')
            @stack('scripts')

            @livewire('components.flash-message')

            @vite(['resources/js/index.js', 'resources/js/app.js'])

            @yield('scripts')
        </div>

        <script>
            document.addEventListener('livewire:init', () => {
                Livewire.on('validation-failed-scroll', (event) => {
                    setTimeout(() => {
                        const firstErrorKey = event.firstErrorKey;
                        if (!firstErrorKey) return;

                        let elementToScrollTo = null;

                        elementToScrollTo = document.querySelector(`[wire\\:model\\.live='${firstErrorKey}']`) ||
                            document.querySelector(`[wire\\:model='${firstErrorKey}']`);

                        if (!elementToScrollTo) {
                            const baseKey = firstErrorKey.replace(/\.\d+\..*$/, '');

                            const sectionId = 'section-' + baseKey.replace('form.', '').replace(/\./g, '-');

                            elementToScrollTo = document.getElementById(sectionId);
                        }

                        if (elementToScrollTo) {
                            elementToScrollTo.scrollIntoView({ behavior: 'smooth', block: 'center' });

                            if (elementToScrollTo.tagName === 'INPUT' || elementToScrollTo.tagName === 'SELECT') {
                                elementToScrollTo.focus({ preventScroll: true });
                            }
                        }
                    }, 150);
                });
            });
        </script>
    </body>
</html>
