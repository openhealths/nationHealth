@props(['cols' => 'xl:flex-row', 'gap' => 'gap-6', 'mb' => '4'])

<div {!! $attributes->merge(['class' => 'mb-' . $mb . ' flex max-lg:min-w-0 max-lg:flex-col ' . $cols . ' ' . $gap]) !!}>
    {{ $slot }}
</div>
