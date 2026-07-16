<div x-data="{ showUpdateModal: false }"
     x-effect="showUpdateModal = $wire.showUpdateModal">

    
    <?php if (isset($component)) { $__componentOriginal66cfe0cbbf6c425a3bd889176e755171 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal66cfe0cbbf6c425a3bd889176e755171 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.header-navigation','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('header-navigation'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
         <?php $__env->slot('title', null, []); ?> 
            <?php echo e(__('party_verification.label')); ?> <?php echo e($party->fullName ?? ''); ?>

         <?php $__env->endSlot(); ?>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal66cfe0cbbf6c425a3bd889176e755171)): ?>
<?php $attributes = $__attributesOriginal66cfe0cbbf6c425a3bd889176e755171; ?>
<?php unset($__attributesOriginal66cfe0cbbf6c425a3bd889176e755171); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal66cfe0cbbf6c425a3bd889176e755171)): ?>
<?php $component = $__componentOriginal66cfe0cbbf6c425a3bd889176e755171; ?>
<?php unset($__componentOriginal66cfe0cbbf6c425a3bd889176e755171); ?>
<?php endif; ?>

    
    <?php if (isset($component)) { $__componentOriginal785c8021fd1a6e19eb80cad4b837cda0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal785c8021fd1a6e19eb80cad4b837cda0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.section','data' => ['class' => '-mt-8 form shift-content']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('section'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => '-mt-8 form shift-content']); ?>

        
        <div class="relative overflow-x-auto shadow-md sm:rounded-lg">
            <table class="w-full min-w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                <tr>
                    <th scope="col" class="px-6 py-3 w-1/5"><?php echo e(__('party_verification.label')); ?></th>
                    <th scope="col" class="px-6 py-3"><?php echo e(__('party_verification.status')); ?></th>
                    <th scope="col" class="px-6 py-3"><?php echo e(__('forms.reason_code')); ?></th>
                    <th scope="col" class="px-6 py-3 w-2/5"><?php echo e(__('forms.ehealth_comment_recommendation')); ?></th>
                </tr>
                </thead>
                <tbody>
                
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $verificationDetails['details'] ?? []; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $details): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <?php
                        $status = data_get($details, 'verification_status');
                        $reason = data_get($details, 'verification_reason');
                        $comment = data_get($details, 'verification_comment'); // Або 'reason' з API
                        $result = data_get($details, 'result');
                    ?>
                    <tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700 border-gray-200" wire:key="details-<?php echo e($key); ?>">
                        
                        <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-white align-top whitespace-normal">
                            <?php echo e(__('party_verification.types.' . $key)); ?>

                        </td>

                        
                        <td class="px-6 py-4 text-sm align-top whitespace-normal">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($status === 'VERIFIED'): ?>
                                <span class="badge-green"><?php echo e(__('party_verification.statuses.VERIFIED')); ?></span>
                            <?php elseif($status === 'NOT_VERIFIED'): ?>
                                <span class="badge-red"><?php echo e(__('party_verification.statuses.NOT_VERIFIED')); ?></span>
                            <?php elseif($status === 'VERIFICATION_NEEDED'): ?>
                                <span class="badge-yellow"><?php echo e(__('party_verification.statuses.VERIFICATION_NEEDED')); ?></span>
                            <?php elseif($status === 'VERIFICATION_NOT_NEEDED'): ?>
                                <span class="badge-gray"><?php echo e(__('party_verification.statuses.VERIFICATION_NOT_NEEDED')); ?></span>
                            <?php elseif($status): ?>
                                <span class="badge-red"><?php echo e($status); ?></span>
                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </td>

                        
                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400 align-top whitespace-normal">
                            <div>
                                <?php echo e($reason ? (__('party_verification.reasons.' . $reason) ?? $reason) : '-'); ?>

                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($result): ?>
                                <div class="text-xs text-gray-400">(<?php echo e(__('forms.code')); ?>: <?php echo e($result); ?>)</div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </td>

                        
                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400 align-top whitespace-normal">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($comment)): ?>
                                <span class="font-semibold text-gray-700 dark:text-gray-300"><?php echo e($comment); ?></span>
                            <?php elseif($status !== 'VERIFIED'): ?>
                                <?php echo e(__('party_verification.recommendations.' . $key, ['result' => $result])); ?>

                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700 border-gray-200">
                        <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">
                            <?php echo e(__('forms.verification_details_not_loaded')); ?>

                        </td>
                    </tr>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </tbody>
            </table>
        </div>

        
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(data_get($verificationDetails, 'details.dracs_death.verification_status') === 'NOT_VERIFIED'): ?>
            <div class="p-4 mt-6 mb-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400" role="alert">
                <h4 class="font-bold"><?php echo e(__('party_verification.warning.header')); ?></h4>
                <ul class="mt-2 list-disc list-inside space-y-1">
                    <li><?php echo e(__('party_verification.warning.dracs_death')); ?></li>
                </ul>
                <p class="mt-3"><?php echo e(__('party_verification.warning.footer')); ?></p>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        
        <div class="flex items-center justify-start gap-4 mt-8">
            <a href="<?php echo e($backUrl); ?>" class="button-minor">
                <?php echo e(__('forms.back')); ?>

            </a>

            
            <button type="button"
                    wire:click="checkAndOpenModal"
                    class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                        'button-primary-outline' => $this->canUpdateVerification,
                        'button-disabled' => !$this->canUpdateVerification
                    ]); ?>"
                    <?php if(!$this->canUpdateVerification): ?> disabled <?php endif; ?>
            >
                <?php echo e(__('forms.update_data')); ?>

            </button>
        </div>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal785c8021fd1a6e19eb80cad4b837cda0)): ?>
<?php $attributes = $__attributesOriginal785c8021fd1a6e19eb80cad4b837cda0; ?>
<?php unset($__attributesOriginal785c8021fd1a6e19eb80cad4b837cda0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal785c8021fd1a6e19eb80cad4b837cda0)): ?>
<?php $component = $__componentOriginal785c8021fd1a6e19eb80cad4b837cda0; ?>
<?php unset($__componentOriginal785c8021fd1a6e19eb80cad4b837cda0); ?>
<?php endif; ?>

    
    <div x-show="showUpdateModal"
         class="fixed inset-0 z-50 flex items-center justify-center"
         style="display: none;"
         x-cloak>

        
        <div x-show="showUpdateModal"
             x-transition.opacity
             class="fixed inset-0 bg-black/75"
             @click="$wire.closeUpdateModal()">
        </div>

        
        <div x-show="showUpdateModal"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             class="relative w-full max-w-2xl m-4 bg-white rounded-lg shadow dark:bg-gray-800 z-50">

            <form wire:submit.prevent="updateStatus">
                
                <div class="flex items-center justify-between p-4 border-b border-gray-200 rounded-t dark:border-gray-600">
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                        <?php echo e(__('forms.update_data')); ?>

                    </h3>

                    <button type="button"
                            @click="$wire.closeUpdateModal()"
                            class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center dark:hover:bg-gray-600 dark:hover:text-white">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                    </button>
                </div>

                <div class="p-6 space-y-6">
                    
                    <div class="form-group group">
                        <select wire:model.live="verificationStream" id="verificationStream" class="input peer px-4 py-2">
                            <option value="dracs_death"><?php echo e(__('party_verification.types.dracs_death')); ?></option>
                        </select>
                        <label for="verificationStream" class="label"><?php echo e(__('party_verification.subject_verification')); ?></label>
                    </div>

                    
                    <div class="form-group group">
                        <select wire:model.live="status" id="status" class="input peer px-4 py-2">
                            <option value=""><?php echo e(__('forms.select_status')); ?></option>
                            <option value="VERIFIED"><?php echo e(__('party_verification.statuses.VERIFIED')); ?></option>
                            <option value="NOT_VERIFIED"><?php echo e(__('party_verification.statuses.NOT_VERIFIED')); ?></option>
                        </select>
                        <label for="status" class="label"><?php echo e(__('party_verification.status')); ?></label>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['status'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-red-500 text-sm mt-1"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>

                    
                    <div class="form-group group">
                        <select wire:model="reason" id="reason" class="input peer px-4 py-2" <?php if(empty($status)): ?> disabled <?php endif; ?>>
                            <option value=""><?php echo e(__('forms.choose_reason')); ?></option>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($status === 'VERIFIED'): ?>
                                <option value="MANUAL_NOT_CONFIRMED">
                                    <?php echo e(__('party_verification.reasons.MANUAL_NOT_CONFIRMED')); ?>

                                </option>
                            <?php elseif($status === 'NOT_VERIFIED'): ?>
                                <option value="MANUAL_CONFIRMED">
                                    <?php echo e(__('party_verification.reasons.MANUAL_CONFIRMED')); ?>

                                </option>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </select>
                        <label for="reason" class="label"><?php echo e(__('forms.reason_code')); ?></label>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['reason'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-red-500 text-sm mt-1"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>

                    
                    <div class="form-group">
                        <label for="comment" class="peer appearance-none bg-white"><?php echo e(__('forms.comment')); ?></label>
                        <textarea
                            id="comment"
                            wire:model.defer="comment"
                            class="textarea !text-gray-500 dark:!text-gray-400 mt-1 px-4"
                            placeholder=" ">
                        </textarea>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['comment'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-red-500 text-sm mt-1"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>

                
                <div class="flex items-center justify-start gap-4 p-6 border-t border-gray-200 dark:border-gray-600">
                    <button type="button"
                            @click="$wire.closeUpdateModal()"
                            class="button-minor">
                        <?php echo e(__('forms.cancel')); ?>

                    </button>

                    <button type="submit"
                            class="button-primary-outline"
                            wire:loading.attr="disabled">
                        <?php echo e(__('forms.update_data')); ?>

                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php /**PATH /var/www/html/resources/views/livewire/party/party-verify.blade.php ENDPATH**/ ?>