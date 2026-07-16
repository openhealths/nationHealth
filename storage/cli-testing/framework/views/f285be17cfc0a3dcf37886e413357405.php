<div <?php echo e($attributes->merge(['class' => 'flex flex-col h-screen'])); ?>>
    <div>
        <div class="inline-block min-w-full align-middle">
            <div>
                <?php echo e($slot); ?>

            </div>
        </div>
    </div>
</div>
<?php if (isset($component)) { $__componentOriginala21f49a74cfebdbb98a47509c8a19010 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala21f49a74cfebdbb98a47509c8a19010 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.loading','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.loading'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala21f49a74cfebdbb98a47509c8a19010)): ?>
<?php $attributes = $__attributesOriginala21f49a74cfebdbb98a47509c8a19010; ?>
<?php unset($__attributesOriginala21f49a74cfebdbb98a47509c8a19010); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala21f49a74cfebdbb98a47509c8a19010)): ?>
<?php $component = $__componentOriginala21f49a74cfebdbb98a47509c8a19010; ?>
<?php unset($__componentOriginala21f49a74cfebdbb98a47509c8a19010); ?>
<?php endif; ?>
<?php /**PATH /var/www/html/resources/views/components/section.blade.php ENDPATH**/ ?>