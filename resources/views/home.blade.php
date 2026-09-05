@extends('layouts.base')

@section('title', trans('oh.title'))
@section('description', trans('oh.description'))

@section('content')
<script>
    if (window.location.hash) {
        history.replaceState(null, null, window.location.pathname + window.location.search);
    }
</script>

<section
    class="relative flex items-center min-h-screen bg-cover bg-center bg-no-repeat"
    style="background-image: url('{{ Vite::asset('resources/images/BG 1.png') }}');"
>
    <div class="container mx-auto px-6 sm:px-12 lg:px-16 flex flex-col justify-center">
        <div class="text-left w-full lg:w-3/4 max-w-[896px]">
            <h1 class="hero-title text-white font-bold uppercase mb-6 text-4xl sm:text-6xl lg:text-7xl leading-tight max-w-4xl tracking-normal">
                {{ trans('forms.first_mis_without_subscription') }}
            </h1>
            <p class="hero-subtitle text-white font-bold uppercase mb-10 text-xl sm:text-3xl lg:text-4xl leading-snug tracking-normal">
                {{ trans('forms.why_pay_monthly') }}
            </p>
            <a
                href="javascript:void(0)"
                onclick="document.getElementById('consultation-form')?.scrollIntoView({ behavior: 'smooth' });"
                class="hero-register-btn inline-flex items-center justify-center text-sm sm:text-base font-medium uppercase tracking-wider cursor-pointer rounded-full border-2 border-white bg-transparent text-white w-64 h-12 transition-all duration-300 hover:bg-white hover:text-[#104475] hover:border-white focus:bg-white focus:text-[#104475] active:bg-white active:text-[#104475]"
            >
                {{ trans('forms.register_institution') }}
            </a>
        </div>
    </div>
</section>

<section
    class="relative flex items-center min-h-screen bg-cover bg-no-repeat"
    style="background-image: url('{{ Vite::asset('resources/images/BG-2.jpg') }}'); background-position: right top;"
>
    <div
        class="container mx-auto px-6 sm:px-12 lg:px-16 flex flex-col justify-center relative z-10"
        style="padding-top: 80px; padding-bottom: 140px;"
    >
        <div class="text-left w-full lg:w-1/2">
            <h2
                class="font-bold uppercase text-[#104475]"
                style="color: #104475; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: 48px; font-weight: 700; line-height: 120%; letter-spacing: 0; max-width: 625px; margin-bottom: 32px;"
            >
                {{ trans('forms.own_medical') }}<br>
                {{ trans('forms.informational') }}<br>
                {{ trans('forms.system') }}
            </h2>
            <p
                class="font-bold uppercase text-[#104475]"
                style="color: #104475; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: 38px; font-weight: 700; line-height: 120%; letter-spacing: 0; margin-bottom: 48px;"
            >
                {{ trans('forms.for_your_institution') }}
            </p>

            <style>
                @media (max-width: 1023px) {
                    .mobile-pill {
                        background-color: rgba(232, 236, 246, 0.95); /* #E8ECF6 */
                        padding: 16px;
                        border-radius: 24px;
                        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
                    }
                }
            </style>
            <ul style="display: flex; flex-direction: column; gap: 24px; width: 100%;" class="lg:gap-[48px]">
                <li class="flex items-center gap-5 mobile-pill">
                    <div
                        class="flex-shrink-0 rounded-full flex items-center justify-center text-white bg-[#104475]"
                        style="width: 64px; height: 64px; background-color: #104475;"
                    >
                        @icon('carbon_security', 'w-7 h-7 text-white')
                    </div>
                    <span
                        class="text-[#104475] font-semibold"
                        style="font-size: 20px; line-height: 120%; font-weight: 600; color: #104475; letter-spacing: 0;"
                    >
                        {{ trans('forms.full_control_and') }}<br>
                        {{ trans('forms.data_security') }}
                    </span>
                </li>
                <li class="flex items-center gap-5 mobile-pill">
                    <div
                        class="flex-shrink-0 rounded-full flex items-center justify-center text-white bg-[#104475]"
                        style="width: 64px; height: 64px; background-color: #104475;"
                    >
                        @icon('streamline_subscription-cashflow', 'w-7 h-7 text-white')
                    </div>
                    <span
                        class="text-[#104475] font-semibold"
                        style="font-size: 20px; line-height: 120%; font-weight: 600; color: #104475; letter-spacing: 0;"
                    >
                        {{ trans('forms.no_monthly') }}<br>
                        {{ trans('forms.payments_and_subscriptions') }}
                    </span>
                </li>
                <li class="flex items-center gap-5 mobile-pill">
                    <div
                        class="flex-shrink-0 rounded-full flex items-center justify-center text-white bg-[#104475]"
                        style="width: 64px; height: 64px; background-color: #104475;"
                    >
                        @icon('tdesign_system-2', 'w-7 h-7 text-white')
                    </div>
                    <span
                        class="text-[#104475] font-semibold"
                        style="font-size: 20px; line-height: 120%; font-weight: 600; color: #104475; letter-spacing: 0;"
                    >
                        {{ trans('forms.independence_from') }}<br>
                        {{ trans('forms.mis_providers') }}
                    </span>
                </li>
                <li class="flex items-center gap-5 mobile-pill">
                    <div
                        class="flex-shrink-0 rounded-full flex items-center justify-center text-white bg-[#104475]"
                        style="width: 64px; height: 64px; background-color: #104475;"
                    >
                        @icon('bx_customize', 'w-7 h-7 text-white')
                    </div>
                    <span
                        class="text-[#104475] font-semibold"
                        style="font-size: 20px; line-height: 120%; font-weight: 600; color: #104475; letter-spacing: 0;"
                    >
                        {{ trans('forms.system_customization') }}<br>
                        {{ trans('forms.for_institution_needs') }}
                    </span>
                </li>
            </ul>
        </div>
    </div>
</section>

<section
    id="offers"
    class="pt-20 sm:pt-30 pb-20 sm:pb-30 pl-5 pr-5 flex items-center bg-cover bg-no-repeat"
    style="background-image: linear-gradient(rgba(240, 247, 249, 0.8), rgba(240, 247, 249, 0.8)), url('{{ Vite::asset('resources/images/BG-4.jpg') }}'); background-position: center top;"
>
    <div class="container mx-auto flex flex-row flex-wrap items-start justify-between gap-8">
        <div style="flex: 1 1 559px; max-width: 559px; display: flex; flex-direction: column; justify-content: flex-start;">
            <h2
                class="uppercase mb-4 text-left font-bold text-[#104475]"
                style="color: #104475; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: clamp(32px, 4vw, 48px); font-weight: 700; line-height: 120%; letter-spacing: 0;"
            >
                {{ trans('forms.stop') }}<br>
                {{ trans('forms.overpaying') }}
            </h2>
            <div
                class="mb-10 bg-[#104475]"
                style="width: 483px; max-width: 100%; height: 4px; background-color: #104475;"
            ></div>

            <h2
                class="uppercase mt-10 mb-6 text-left font-bold text-[#104475]"
                style="color: #104475; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: clamp(32px, 4vw, 48px); font-weight: 700; line-height: 120%; letter-spacing: 0;"
            >
                {{ trans('forms.other_mis') }}
            </h2>

            <div
                class="bg-white rounded-xl shadow-sm border-none mb-10 overflow-hidden"
                style="width: 559px; max-width: 100%; min-height: 272px;"
            >
                <table
                    class="text-left border-none w-full"
                    style="width: 559px; table-layout: fixed;"
                >
                    <thead>
                        <tr
                            class="border-none bg-gray-2"
                            style="height: 50px; background-color: #f9fafb;"
                        >
                            <th
                                class="px-4 text-[12px] font-semibold uppercase tracking-wider align-middle border-none whitespace-nowrap bg-gray-2 text-[#1e1e1e]"
                                style="width: 225px; background-color: #f9fafb; color: #1e1e1e; font-size: 12px; font-weight: 600; line-height: 150%; letter-spacing: 0; white-space: nowrap;"
                            >
                                {{ trans('forms.number_of_medical_workers') }}
                            </th>
                            <th
                                class="px-4 text-[12px] font-semibold uppercase tracking-wider align-middle border-none whitespace-nowrap bg-gray-2 text-[#1e1e1e]"
                                style="width: 179px; background-color: #f9fafb; color: #1e1e1e; font-size: 12px; font-weight: 600; line-height: 150%; letter-spacing: 0; white-space: nowrap;"
                            >
                                {{ trans('forms.cost_per_month') }}
                            </th>
                            <th
                                class="px-4 text-[12px] font-semibold uppercase tracking-wider align-middle border-none whitespace-nowrap bg-gray-2 text-[#1e1e1e]"
                                style="width: 155px; background-color: #f9fafb; color: #1e1e1e; font-size: 12px; font-weight: 600; line-height: 150%; letter-spacing: 0; white-space: nowrap;"
                            >
                                {{ trans('forms.cost_per_year') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="text-[14px] font-medium bg-white text-[#1e1e1e]">
                        <tr style="height: 54px;">
                            <td class="px-4 align-middle border-none text-[#1e1e1e]" style="width: 225px; color: #1e1e1e;">30</td>
                            <td class="px-4 align-middle border-none text-[#1e1e1e]" style="width: 179px; color: #1e1e1e;">18000</td>
                            <td class="px-4 align-middle border-none text-[#1e1e1e]" style="width: 155px; color: #1e1e1e;">216000</td>
                        </tr>
                        <tr style="height: 54px;">
                            <td class="px-4 align-middle border-none text-[#1e1e1e]" style="width: 225px; color: #1e1e1e;">50</td>
                            <td class="px-4 align-middle border-none text-[#1e1e1e]" style="width: 179px; color: #1e1e1e;">30000</td>
                            <td class="px-4 align-middle border-none text-[#1e1e1e]" style="width: 155px; color: #1e1e1e;">360000</td>
                        </tr>
                        <tr style="height: 54px;">
                            <td class="px-4 align-middle border-none text-[#1e1e1e]" style="width: 225px; color: #1e1e1e;">100</td>
                            <td class="px-4 align-middle border-none text-[#1e1e1e]" style="width: 179px; color: #1e1e1e;">60000</td>
                            <td class="px-4 align-middle border-none text-[#1e1e1e]" style="width: 155px; color: #1e1e1e;">720000</td>
                        </tr>
                        <tr style="height: 54px;">
                            <td class="px-4 align-middle border-none text-[#1e1e1e]" style="width: 225px; color: #1e1e1e;">200</td>
                            <td class="px-4 align-middle border-none text-[#1e1e1e]" style="width: 179px; color: #1e1e1e;">120000</td>
                            <td class="px-4 align-middle border-none text-[#1e1e1e]" style="width: 155px; color: #1e1e1e;">1440000</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="flex: 0 0 420px; max-width: 100%; display: flex; flex-direction: column; justify-content: flex-start; padding-top: 0px;">
            <div
                class="flex flex-col items-start mb-12"
                style="width: 361px; max-width: 100%; margin-bottom: 48px;"
            >
                <img
                    src="{{ Vite::asset('resources/images/nation_health_logo.png') }}"
                    alt="{{ trans('oh.title') }}"
                    class="object-contain"
                    style="width: 361px; max-width: 100%;"
                >
            </div>

            <div style="display: flex; flex-direction: column; gap: 48px; width: 100%;">
                <div class="flex items-start gap-6">
                    <div
                        class="rounded-full flex items-center justify-center text-white shrink-0 bg-[#104475]"
                        style="width: 64px; height: 64px; background-color: #104475;"
                    >
                        <span class="text-white text-2xl font-bold leading-none" style="color: #ffffff; font-size: 28px; font-weight: 700; line-height: 1;">1</span>
                    </div>
                    <span
                        class="pt-1.5 text-[#104475] font-medium"
                        style="color: #104475; font-size: 20px; line-height: 120%; font-weight: 500; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; max-width: 337px;"
                    >
                        {{ trans('forms.system_installation_on_server') }}
                    </span>
                </div>

                <div class="flex items-start gap-6">
                    <div
                        class="rounded-full flex items-center justify-center text-white shrink-0 bg-[#104475]"
                        style="width: 64px; height: 64px; background-color: #104475;"
                    >
                        <span class="text-white text-2xl font-bold leading-none" style="color: #ffffff; font-size: 28px; font-weight: 700; line-height: 1;">2</span>
                    </div>
                    <span
                        class="pt-1.5 text-[#104475] font-medium"
                        style="color: #104475; font-size: 20px; line-height: 120%; font-weight: 500; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; max-width: 337px;"
                    >
                        {{ trans('forms.certification_and_ehealth_connection') }}
                    </span>
                </div>

                <div class="flex items-start gap-6">
                    <div
                        class="rounded-full flex items-center justify-center text-white shrink-0 bg-[#104475]"
                        style="width: 64px; height: 64px; background-color: #104475;"
                    >
                        @icon('pajamas-repeat', 'w-8 h-8 text-white')
                    </div>
                    <div
                        class="flex flex-col pt-1.5 text-[#104475] font-medium"
                        style="color: #104475; font-size: 20px; line-height: 120%; font-weight: 500; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; max-width: 337px;"
                    >
                        <span>
                            {{ trans('forms.server_technical_support_and_updates') }}
                        </span>
                        <span class="mt-1.5 font-medium text-[#104475]" style="font-size: 20px;">
                            {{ trans('forms.approx_up_to_120000_uah_year') }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="services" class="bg-white pt-20 sm:pt-30 pb-20 sm:pb-30 pl-5 pr-5">
    <div class="container mx-auto">
        <h2
            class="font-bold uppercase mb-10 text-left text-[#104475]"
            style="color: #104475; font-size: clamp(32px, 4vw, 48px); line-height: 120%; letter-spacing: 0;"
        >
            {{ trans('forms.nation_health_mis_advantages') }}
        </h2>

        <div class="grid grid-cols-1 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-y-4 gap-y-10">
            <div class="bg-white p-6 rounded-lg shadow-card hover:shadow-lg transition-all h-[230px] flex flex-col justify-start">
                <div
                    class="mb-4 text-[#104475]"
                    style="color: #104475;"
                >
                    @icon('code', 'w-10 h-10')
                </div>

                <h3
                    class="text-[20px] font-bold leading-[125%] mb-2 text-[#104475]"
                    style="color: #104475;"
                >
                    {{ trans('forms.open_source_code') }}
                </h3>
                <p class="text-[#64748B] text-sm font-normal leading-relaxed">
                    {{ trans('forms.open_source_description') }}
                </p>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-card hover:shadow-lg transition-all h-[230px] flex flex-col justify-start">
                <div
                    class="mb-4 text-[#104475]"
                    style="color: #104475;"
                >
                    @icon('cloud', 'w-10 h-10')
                </div>

                <h3
                    class="text-[20px] font-bold leading-[125%] mb-2 text-[#104475]"
                    style="color: #104475;"
                >
                    {{ trans('forms.cloud_technologies') }}
                </h3>
                <p class="text-[#64748B] text-sm font-normal leading-relaxed">
                    {{ trans('forms.cloud_technologies_description') }}
                </p>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-card hover:shadow-lg transition-all h-[230px] flex flex-col justify-start">
                <div
                    class="mb-4 text-[#104475]"
                    style="color: #104475;"
                >
                    @icon('codesandbox', 'w-10 h-10')
                </div>

                <h3
                    class="text-[20px] font-bold leading-[125%] mb-2 text-[#104475]"
                    style="color: #104475;"
                >
                    {{ trans('forms.ehealth_integration') }}
                </h3>
                <p class="text-[#64748B] text-sm font-normal leading-relaxed">
                    {{ trans('forms.ehealth_integration_description') }}
                </p>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-card hover:shadow-lg transition-all h-[230px] flex flex-col justify-start">
                <div
                    class="mb-4 text-[#104475]"
                    style="color: #104475;"
                >
                    @icon('grid', 'w-10 h-10')
                </div>

                <h3
                    class="text-[20px] font-bold leading-[125%] mb-2 text-[#104475]"
                    style="color: #104475;"
                >
                    {{ trans('forms.intuitive_interface') }}
                </h3>
                <p class="text-[#64748B] text-sm font-normal leading-relaxed">
                    {{ trans('forms.intuitive_interface_description') }}
                </p>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-card hover:shadow-lg transition-all h-[230px] flex flex-col justify-start">
                <div
                    class="mb-4 text-[#104475]"
                    style="color: #104475;"
                >
                    @icon('message-circle', 'w-10 h-10')
                </div>

                <h3
                    class="text-[20px] font-bold leading-[125%] mb-2 text-[#104475]"
                    style="color: #104475;"
                >
                    {{ trans('forms.human_support_service') }}
                </h3>
                <p class="text-[#64748B] text-sm font-normal leading-relaxed">
                    {{ trans('forms.human_support_service_description') }}
                </p>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-card hover:shadow-lg transition-all h-[230px] flex flex-col justify-start">
                <div
                    class="mb-4 text-[#104475]"
                    style="color: #104475;"
                >
                    @icon('server', 'w-10 h-10')
                </div>

                <h3
                    class="text-[20px] font-bold leading-[125%] mb-2 text-[#104475]"
                    style="color: #104475;"
                >
                    {{ trans('forms.mis_on_your_server') }}
                </h3>
                <p class="text-[#64748B] text-sm font-normal leading-relaxed">
                    {{ trans('forms.system_installation_on_server') }}
                </p>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-card hover:shadow-lg transition-all h-[230px] flex flex-col justify-start">
                <div
                    class="mb-4 text-[#104475]"
                    style="color: #104475;"
                >
                    @icon('cpu', 'w-10 h-10')
                </div>

                <h3
                    class="text-[20px] font-bold leading-[125%] mb-2 text-[#104475]"
                    style="color: #104475;"
                >
                    {{ trans('forms.use_of_ai') }}
                </h3>
                <p class="text-[#64748B] text-sm font-normal leading-relaxed">
                    {{ trans('forms.use_of_ai_description') }}
                </p>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-card hover:shadow-lg transition-all h-[230px] flex flex-col justify-start">
                <div
                    class="mb-4 text-[#104475]"
                    style="color: #104475;"
                >
                    @icon('shield', 'w-10 h-10')
                </div>

                <h3
                    class="text-[20px] font-bold leading-[125%] mb-2 text-[#104475]"
                    style="color: #104475;"
                >
                    {{ trans('forms.security_and_transparency') }}
                </h3>
                <p class="text-[#64748B] text-sm font-normal leading-relaxed">
                    {{ trans('forms.security_and_transparency_description') }}
                </p>
            </div>
        </div>
    </div>
</section>

<section
    id="custom-development"
    class="pt-20 sm:pt-30 pb-20 sm:pb-30 pl-5 pr-5 flex items-center bg-cover bg-no-repeat relative"
    style="background-image: url('{{ Vite::asset('resources/images/BG-6-clean.jpg') }}'); background-position: center center;"
>
    <div class="container mx-auto">
        <h2
            class="text-white font-bold uppercase text-left"
            style="color: #ffffff; font-family: 'e-Ukraine', 'Noto Sans', sans-serif; font-size: 48px; font-weight: 700; line-height: 120%; letter-spacing: 0; margin-bottom: 64px;"
        >
            {{ trans('forms.custom_development') }}
        </h2>

        <div
            class="grid grid-cols-1 lg:grid-cols-3 relative"
            style="column-gap: 48px; row-gap: 32px; align-items: start;"
        >
            @php
                $offers = [
                    [
                        'image' => '4.png',
                        'imageAlt' => 'forms.mis_integration_alt',
                        'question' => 'forms.offer_1_question',
                        'answer' => 'forms.offer_1_answer',
                    ],
                    [
                        'image' => '2.png',
                        'imageAlt' => 'forms.acquiring_and_fiscalization_alt',
                        'question' => 'forms.offer_2_question',
                        'answer' => 'forms.offer_2_answer',
                    ],
                    [
                        'image' => '3.png',
                        'imageAlt' => 'forms.analytical_reports_alt',
                        'question' => 'forms.offer_3_question',
                        'answer' => 'forms.offer_3_answer',
                    ],
                ];
            @endphp

            <style>
                @media (min-width: 1024px) {
                    .custom-dev-col-1 { grid-column: 1; }
                    .custom-dev-col-2 { grid-column: 2; }
                    .custom-dev-col-3 { grid-column: 3; }
                    .custom-dev-row-1 { grid-row: 1; }
                    .custom-dev-row-2 { grid-row: 2; }
                    .custom-dev-row-3 { grid-row: 3; }
                }
            </style>

            @foreach ($offers as $i => $offer)
                <div class="flex justify-center lg:justify-start custom-dev-col-{{ $i + 1 }} custom-dev-row-1">
                    <div class="overflow-hidden w-full" style="max-width: 385px; height: 125px; border-radius: 20px; border: 1px solid #ffffff;">
                        <img src="{{ Vite::asset('resources/images/' . $offer['image']) }}" alt="{{ trans($offer['imageAlt']) }}" class="w-full h-full object-cover">
                    </div>
                </div>

                <div class="flex items-start justify-center lg:justify-start gap-3 custom-dev-col-{{ $i + 1 }} custom-dev-row-2">
                    <img src="{{ Vite::asset('resources/images/502.png') }}" alt="{{ trans('forms.director_of_medical_institution') }}" class="rounded-full object-cover flex-shrink-0" style="width: 81px; height: 81px;">
                    <div class="bg-white p-4 text-black shadow-md w-full" style="max-width: 292px; border-radius: 0px 20px 20px 20px;">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="font-bold text-gray-900 text-[13px]">{{ trans('forms.director_of_medical_institution') }}</span>
                            <span class="text-gray-400 text-xs font-normal">11:46</span>
                        </div>
                        <p class="text-[13px] leading-snug text-gray-800 font-normal">{!! trans($offer['question']) !!}</p>
                    </div>
                </div>

                <div class="flex items-end justify-center lg:justify-start gap-3 custom-dev-col-{{ $i + 1 }} custom-dev-row-3">
                    <div class="bg-white p-4 text-black shadow-md w-full" style="max-width: 292px; border-radius: 20px 0px 20px 20px;">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="font-bold text-[#104475] text-[13px]" style="color: #104475;">МІС Nation Health</span>
                            <span class="text-gray-400 text-xs font-normal">11:48</span>
                        </div>
                        <p class="text-[13px] leading-snug text-gray-800 font-normal">{{ trans($offer['answer']) }}</p>
                    </div>
                    <img src="{{ Vite::asset('resources/images/logo-152x152.png') }}" alt="МІС Nation Health" class="rounded-full object-contain p-3 bg-white flex-shrink-0 shadow-md" style="width: 81px; height: 81px;">
                </div>
            @endforeach

            <div
                class="hidden lg:block"
                style="position: absolute; left: 33.33%; top: 0; bottom: 0; width: 2px; background-color: #ffffff; z-index: 10;"
            ></div>
            <div
                class="hidden lg:block"
                style="position: absolute; left: 66.66%; top: 0; bottom: 0; width: 2px; background-color: #ffffff; z-index: 10;"
            ></div>
        </div>
    </div>
</section>

<section
    class="pt-20 sm:pt-30 pb-20 sm:pb-30 pl-5 pr-5 flex items-center bg-cover bg-no-repeat"
    style="background-image: linear-gradient(rgba(240, 247, 249, 0.8), rgba(240, 247, 249, 0.8)), url('{{ Vite::asset('resources/images/BG-4.jpg') }}'); background-position: center top;"
>
    <div id="consultation-form" class="container mx-auto flex flex-row flex-wrap items-start justify-between gap-8">
        <div style="flex: 1 1 500px; max-width: 600px; display: flex; flex-direction: column; justify-content: flex-start;">
            <h2
                class="font-bold uppercase text-[#104475]"
                style="color: #104475; font-family: inherit; font-size: 48px; line-height: 120%; letter-spacing: 0; margin: 0 0 16px 0;"
            >
                {{ trans('forms.contact_us') }}
            </h2>
            <p style="font-family: inherit; font-size: 20px; line-height: 150%; letter-spacing: 0; color: #6B7280; font-weight: 400; margin: 0;">
                {{ trans('forms.fill_form_contact_prompt') }}
            </p>
        </div>

        <div style="flex: 0 0 448px; width: 448px; max-width: 100%; background: #ffffff; border-radius: 8px; padding: 32px; box-shadow: 0 4px 6px 0 rgba(0, 0, 0, 0.05), 0 10px 15px -3px rgba(0, 0, 0, 0.10); display: flex; flex-direction: column; justify-content: center;">
            <form id="consultation-form" method="POST" action="{{ route('send.email') }}">
                @csrf
                <div style="margin-bottom: 16px;">
                    <label
                        for="contact-phone"
                        style="font-family: inherit; display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: #374151;"
                    >
                        {{ trans('forms.your_phone_number') }}
                    </label>
                    <input
                        id="contact-phone"
                        type="tel"
                        name="phone"
                        placeholder="+380"
                        style="font-family: inherit; width: 100%; border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px 16px; font-size: 14px; color: #374151; background: #F8FAFC; outline: none; box-sizing: border-box;"
                        title="{{ trans('forms.enter_valid_phone_hint') }}"
                    >
                </div>
                <div style="margin-bottom: 24px;">
                    <label
                        for="contact-email"
                        style="font-family: inherit; display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: #374151;"
                    >
                        {{ trans('forms.your_email') }}
                    </label>
                    <input
                        id="contact-email"
                        type="email"
                        name="email"
                        placeholder="test@gmail.com"
                        style="font-family: inherit; width: 100%; border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px 16px; font-size: 14px; color: #374151; background: #F8FAFC; outline: none; box-sizing: border-box;"
                    >
                </div>
                <button
                    type="submit"
                    class="bg-[#104475] text-white hover:bg-blue-700"
                    style="font-family: inherit; width: 100%; background: #104475; color: #fff; font-size: 15px; font-weight: 600; padding: 12px 0; border: none; border-radius: 8px; cursor: pointer; transition: opacity 0.2s;"
                    onmouseover="this.style.opacity='0.9'"
                    onmouseout="this.style.opacity='1'"
                >
                    {{ trans('forms.send') }}
                </button>
            </form>
        </div>
    </div>
</section>
@endsection

@push('modals')
    <div id="successModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center hidden ml-3 mr-3">
        <div class="bg-white border-black rounded-lg overflow-hidden shadow-card hover:shadow-lg transform transition-all max-w-lg w-full p-6">
            <div class="text-center">
                <h3 class="text-lg font-medium text-gray-900">
                    {{ trans('forms.message_sent_successfully') }}
                </h3>
                <div class="mt-2">
                    <p class="text-sm text-gray-500">
                        {{ trans('forms.thank_you_contact_message') }}
                    </p>
                </div>
            </div>
            <div class="mt-4">
                <button
                    id="closeModal"
                    type="button"
                    class="bg-[#104475] inline-flex justify-center w-full rounded-md border border-transparent shadow-sm hover:shadow-lg px-4 py-2 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#104475] sm:text-sm"
                    style="background-color: #104475;"
                >
                    {{ trans('forms.close') }}
                </button>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
    @vite(['resources/css/home.css', 'resources/js/home.js'])
@endpush
