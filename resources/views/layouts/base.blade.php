<!-- resources/views/layouts/base.blade.php -->
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title', trans('oh.title'))</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="color-scheme" content="light only">
    <meta name="description" content="@yield('description', trans('oh.description'))">
    <meta name="robots" content="index, follow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta name="theme-color" content="#f4881b">
    <meta property="og:title" content="@yield('title', trans('oh.title'))">
    <meta property="og:description" content="@yield('description', trans('oh.description'))">
    {{-- <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet"> --}}
    <meta property="og:image" content="{{ Vite::asset('resources/images/photo.jpg') }}">
    <meta property="og:image:secure_url" content="{{ Vite::asset('resources/images/photo.jpg') }}">
    <link rel="shortcut icon" type="image/png" href="{{ Vite::asset('resources/images/logo-16x16.png') }}" sizes="16x16">
    <link rel="shortcut icon" type="image/png" href="{{ Vite::asset('resources/images/logo-32x32.png') }}" sizes="32x32">
    <link rel="shortcut icon" type="image/png" href="{{ Vite::asset('resources/images/logo-96x96.png') }}" sizes="96x96">
    <link rel="apple-touch-icon" sizes="120x120" href="{{ Vite::asset('resources/images/logo-120x120.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ Vite::asset('resources/images/logo-180x180.png') }}">
    <link rel="apple-touch-icon" sizes="152x152" href="{{ Vite::asset('resources/images/logo-152x152.png') }}">
    <link rel="apple-touch-icon" sizes="167x167" href="{{ Vite::asset('resources/images/logo-167x167.png') }}">
    @vite('resources/css/style.css')
{{--    @stack('styles')--}}
</head>
<body id="body">
<x-forms.loading :global="true" />
<header id="header" class="logo bg-white py-1 px-3.5">
    <div class="lg:container mx-auto sm:w-full flex justify-between items-center">
        <!-- Left-aligned logo -->
        <div class="flex items-center">
            <a href="/" class="text-black text-lg font-bold">
                <img
                    src="{{ Vite::asset('resources/images/nation_health_logo.png') }}"
                    alt="{{ trans('oh.title') }}"
                    width="400"
                    height="150"
                >
            </a>
        </div>

        <!-- Center-aligned menu (hidden on small screens) -->
        <nav class="hidden lg:block">
            <ul class="flex items-center">
                <li>
                    <a
                        href="#services"
                        class="p-4 hover:text-orange hover:underline"
                        style="color: #104475; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: 18px; font-weight: 700; line-height: 150%;"
                    >
                        {{ trans('Переваги') }}
                    </a>
                </li>
                <li>
                    <a
                        href="#offers"
                        class="p-4 hover:text-orange hover:underline"
                        style="color: #104475; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: 18px; font-weight: 700; line-height: 150%;"
                    >
                        {{ trans('Вартість') }}
                    </a>
                </li>
                <li>
                    <a
                        href="#footer"
                        class="p-4 hover:text-orange hover:underline"
                        style="color: #104475; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: 18px; font-weight: 700; line-height: 150%;"
                    >
                        {{ trans('Контакти') }}
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Right-aligned menu toggle button (visible on small screens) -->
        <button id="menuToggle" class="menu lg:hidden md:block text-black focus:outline-none p-2 w-10 h-10" aria-label="{{ trans('menu')}}">
            <!--<i class="fas fa-bars"></i>
            <i class="fas fa-times hidden"></i>-->

            <svg id="openIcon" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path>
            </svg>
            <svg id="closeIcon" class="hidden w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>

        <!-- Right-aligned action buttons (Login & Register) -->
        <div class="hidden lg:flex items-center gap-6">
            <a
                href="{{ route('login') }}"
                class="flex items-center gap-2 hover:opacity-80"
                style="color: #104475; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: 18px; font-weight: 700; line-height: 150%;"
            >
                @icon('qlementine-icons-use', 'w-[37px] h-[37px]')
                <span>{{ trans('Вхід') }}</span>
            </a>
            <a
                href="#consultation-form"
                class="inline-flex items-center justify-center text-white font-medium hover:opacity-90 transition-opacity"
                style="width: 220px; height: 39px; border-radius: 100px; background-color: #046C4E; color: #ffffff; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: 14px; font-weight: 700; line-height: 150%; letter-spacing: 0;"
            >
                {{ trans('Зареєструвати заклад') }}
            </a>
        </div>
    </div>

    <!-- Responsive menu (visible on small screens) -->
    <div id="responsiveMenu" class="hidden lg:hidden bg-white pb-6">
        <ul>
            <li class="py-2 px-4">
                <a
                    href="#services"
                    class="text-center block hover:text-orange"
                    style="color: #104475; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: 18px; font-weight: 700; line-height: 150%;"
                >
                    {{ trans('Переваги') }}
                </a>
            </li>
            <li class="py-2 px-4">
                <a
                    href="#offers"
                    class="text-center block hover:text-orange"
                    style="color: #104475; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: 18px; font-weight: 700; line-height: 150%;"
                >
                    {{ trans('Вартість') }}
                </a>
            </li>
            <li class="py-2 px-4">
                <a
                    href="#footer"
                    class="text-center block hover:text-orange"
                    style="color: #104475; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: 18px; font-weight: 700; line-height: 150%;"
                >
                    {{ trans('Контакти') }}
                </a>
            </li>
        </ul>

        <!-- Right-aligned action buttons for mobile -->
        <div class="flex flex-col items-center gap-4 mt-6">
            <a
                href="{{ route('login') }}"
                class="flex items-center gap-2 hover:opacity-80"
                style="color: #104475; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: 18px; font-weight: 700; line-height: 150%;"
            >
                @icon('qlementine-icons-use', 'w-[37px] h-[37px]')
                <span>{{ trans('Вхід') }}</span>
            </a>
            <a
                href="#consultation-form"
                class="inline-flex items-center justify-center text-white font-medium hover:opacity-90 transition-opacity"
                style="width: 220px; height: 39px; border-radius: 100px; background-color: #046C4E; color: #ffffff; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: 14px; font-weight: 700; line-height: 150%; letter-spacing: 0;"
            >
                {{ trans('Зареєструвати заклад') }}
            </a>
        </div>
    </div>
</header>

<main>
    @yield('content')
</main>

<footer id="footer" class="bg-gray-3 py-8 sm:py-10">
    <div class="container mx-auto px-5 text-black">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
            <div class="text-left">
                <h3 class="text-xl sm:text-2xl font-bold mb-1">
                    &copy; {{ date('Y') }} {{ trans('Nation Health') }}
                </h3>
                <p class="text-meta-10 font-bold">
                    {{ trans('Медична інформаційна система') }}
                </p>
            </div>

            <div class="text-center">
                <h3 class="text-xl font-bold mb-1">
                    {{ trans('Телефонуйте') }}
                </h3>
                <p>
                    <a
                        href="tel:{{ $phone ?? '+380506491244' }}"
                        class="hover:text-orange hover:underline"
                    >
                        {{ $phone ?? '+380506491244' }}
                    </a>
                </p>
            </div>

            <div class="text-right">
                <h3 class="text-xl font-bold mb-1">
                    {{ trans('Пишіть нам') }}
                </h3>
                <p>
                    <a
                        href="mailto:{{ $email ?? 'v@openhealths.com' }}"
                        class="hover:text-orange hover:underline"
                    >
                        {{ $email ?? 'v@openhealths.com' }}
                    </a>
                </p>
            </div>
        </div>

        <ul class="flex justify-center mt-6">
            <li>
                <a
                    href="https://www.facebook.com/openhealthmis"
                    class="icon facebook"
                    aria-label="facebook"
                >
                    @icon('facebook', 'w-10 h-10 icon hover:fill-orange')
                </a>
            </li>
            <li class="ml-4">
                <a
                    href="https://github.com/openhealths/nationHealth"
                    class="icon github"
                    aria-label="github"
                >
                    @icon('github', 'w-10 h-10 icon hover:fill-orange')
                </a>
            </li>
            <li class="ml-4">
                <a
                    href="https://www.youtube.com/@NationHealth-mis"
                    class="icon youtube"
                    aria-label="youtube"
                >
                    @icon('youtube', 'w-10 h-10 icon hover:fill-orange')
                </a>
            </li>
        </ul>
    </div>
</footer>

@stack('modals')

@vite('resources/js/app.js')
@vite('resources/js/base.js')
@stack('scripts')
</body>
</html>
