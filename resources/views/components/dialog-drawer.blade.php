@props([
    'maxWidth' => null,
    'noBackdrop' => false,
    'backdropClickThrough' => false,
    'stopClickPropagation' => false,
    'noTeleport' => false,
    'topClass' => 'top-0',
    'heightClass' => null,
    'zIndex' => '40',
    'panelZIndex' => null,
    'customWidth' => null,
    'overlayWidth' => null,
    'hasClose' => false,
    'onCloseClick' => null,
    'title' => null,
])

@php
    $resolvedWidth = $customWidth;
    if (!$resolvedWidth) {
        $resolvedWidth = [
            'sm' => 'w-72 sm:w-80',
            'md' => 'w-80 sm:w-96',
            'lg' => 'w-96 sm:w-[28rem]',
            'xl' => 'w-full sm:w-[32rem]',
            '2xl' => 'w-full sm:w-[40rem]',
            '3xl' => 'w-full sm:w-[48rem]',
            '4/5' => 'w-full sm:w-4/5',
            '3/5' => 'w-full sm:w-[68%]',
        ][$maxWidth ?? '4/5'];
    }

    $zVal = (int)$zIndex;
    $pZVal = $panelZIndex ?? ($zVal + 1);
@endphp

@if($noTeleport)
    @include('components.dialog-drawer-inner')
@else
    <template x-teleport="body">
        @include('components.dialog-drawer-inner')
    </template>
@endif
