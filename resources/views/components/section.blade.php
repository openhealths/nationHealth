<div {{ $attributes->merge(['class' => 'flex flex-col min-h-screen']) }}>
    <div>
        <div class="inline-block min-w-full align-middle">
            <div>
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
<x-forms.loading/>
