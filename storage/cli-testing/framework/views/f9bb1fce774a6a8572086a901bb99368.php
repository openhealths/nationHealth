<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['title' => null, 'description' => null, 'breadcrumbs' => []]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter((['title' => null, 'description' => null, 'breadcrumbs' => []]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $crumbs = $breadcrumbs;

    if (empty($crumbs) || count($crumbs) <= 2) {
        $person = null;
        $route = request()->route();

        if ($route) {
            $personParam = $route->parameter('personId') ?? $route->parameter('person');
            if ($personParam) {
                $person = is_numeric($personParam)
                    ? \App\Models\Person\Person::find($personParam)
                    : ($personParam instanceof \App\Models\Person\Person ? $personParam : null);
            }

            if (!$person) {
                $declParam = $route->parameter('declaration') ?? $route->parameter('declarationRequest');
                if ($declParam) {
                    $decl = is_numeric($declParam)
                        ? (\App\Models\Declaration::find($declParam) ?? \App\Models\DeclarationRequest::find($declParam))
                        : $declParam;
                    $person = $decl?->person;
                }
            }

            if (!$person) {
                $cpParam = $route->parameter('carePlan');
                if ($cpParam) {
                    $cp = is_numeric($cpParam) ? \App\Models\CarePlan::find($cpParam) : $cpParam;
                    $person = $cp?->person;
                }
            }
        }

        $dashboardUrl = legalEntity() ? route('dashboard', [legalEntity()]) : url('/dashboard');

        if ($person) {
            $crumbs = [
                ['label' => __('forms.home'), 'url' => $dashboardUrl],
                ['label' => __('patients.patients'), 'url' => route('persons.index', [legalEntity()])]
            ];

            $patientName = $person->fullName;
            $cleanTitle = trim(str_replace([' - ' . $patientName, $patientName . ' - '], '', $title ?? ''));

            if ($cleanTitle && $cleanTitle !== $patientName) {
                $crumbs[] = ['label' => $patientName, 'url' => route('persons.patient-data', [legalEntity(), 'person' => $person->id])];
                $crumbs[] = ['label' => $cleanTitle];
            } else {
                $crumbs[] = ['label' => $patientName];
            }
        } else {
            $routeName = $route ? $route->getName() : '';
            if (str_starts_with($routeName, 'persons.') && $routeName !== 'persons.index') {
                $crumbs = [
                    ['label' => __('forms.home'), 'url' => $dashboardUrl],
                    ['label' => __('patients.patients'), 'url' => route('persons.index', [legalEntity()])],
                    $title ? ['label' => $title] : null
                ];
                $crumbs = array_filter($crumbs);
            } else {
                $crumbs = [
                    ['label' => __('forms.home'), 'url' => $dashboardUrl],
                    $title ? ['label' => $title] : null
                ];
                $crumbs = array_filter($crumbs);
            }
        }
    }
?>

<div <?php echo e($attributes->merge(['class' => 'section-card shift-content relative z-20'])); ?>>
    <div class="max-w-screen-xl w-full">
        <!-- Breadcrumbs at the very top -->
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <ol class="breadcrumb-list">
                <?php $crumbs = array_values($crumbs); ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $crumbs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $index => $crumb): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $isFirst = $index === 0;
                        $isLast = $index === count($crumbs) - 1;
                        $hasUrl = isset($crumb['url']) && filled($crumb['url']);
                    ?>
                    <li <?php if($isFirst): ?> class="breadcrumb-first" <?php endif; ?> <?php if($isLast): ?> aria-current="page" <?php endif; ?>>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isFirst): ?>
                            <a href="<?php echo e(legalEntity() ? route('dashboard', [legalEntity()]) :  url('/dashboard')); ?>" class="breadcrumb-link">
                                <svg class="breadcrumb-home-icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="m19.707 9.293-2-2-7-7a1 1 0 0 0-1.414 0l-7 7-2 2a1 1 0 0 0 1.414 1.414L2 10.414V18a2 2 0 0 0 2 2h3a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1h3a2 2 0 0 0 2-2v-7.586l.293.293a1 1 0 0 0 1.414-1.414Z"/>
                                </svg>
                                <?php echo e($crumb['label']); ?>

                            </a>
                        <?php else: ?>
                            <div class="breadcrumb-separator">
                                <svg class="breadcrumb-chevron" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/>
                                </svg>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($hasUrl && !$isLast): ?>
                                    <a href="<?php echo e($crumb['url']); ?>" class="breadcrumb-item-link"><?php echo e($crumb['label']); ?></a>
                                <?php else: ?>
                                    <span class="breadcrumb-item-text"><?php echo e($crumb['label']); ?></span>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </ol>
        </nav>

        <!-- Title row with page title and action buttons -->
        <header class="page-header">
            <div class="w-full flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="page-header-content min-w-0">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($title): ?>
                        <h1 class="page-title !mb-0"><?php echo e($title); ?></h1>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($description): ?>
                        <p class="page-description"><?php echo e($description); ?></p>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>

                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($actions) || trim($slot)): ?>
                    <div class="button-group shrink-0 flex items-center gap-2">
                        <?php echo e($slot); ?>

                        <?php echo e($actions ?? ''); ?>

                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </header>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($navigation)): ?>
            <div class="page-navigation mt-8">
                <?php echo e($navigation); ?>

            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    </div>
</div>
<?php /**PATH /var/www/html/resources/views/components/header-navigation.blade.php ENDPATH**/ ?>