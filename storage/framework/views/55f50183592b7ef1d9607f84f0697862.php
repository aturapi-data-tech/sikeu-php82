<?php
use Livewire\Component;
use Illuminate\Support\Facades\DB;
?>

<div>
    <?php if (isset($component)) { $__componentOriginal8b54caccbdedc8030792c13949386bbd = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8b54caccbdedc8030792c13949386bbd = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.page-title','data' => ['title' => 'Sambungan SIRUS','subtitle' => 'Cek koneksi baca-saja SIKEU ke skema SIRUS — tersambung, hak baca, dan tulis wajib ditolak']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('page-title'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Sambungan SIRUS','subtitle' => 'Cek koneksi baca-saja SIKEU ke skema SIRUS — tersambung, hak baca, dan tulis wajib ditolak']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8b54caccbdedc8030792c13949386bbd)): ?>
<?php $attributes = $__attributesOriginal8b54caccbdedc8030792c13949386bbd; ?>
<?php unset($__attributesOriginal8b54caccbdedc8030792c13949386bbd); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8b54caccbdedc8030792c13949386bbd)): ?>
<?php $component = $__componentOriginal8b54caccbdedc8030792c13949386bbd; ?>
<?php unset($__componentOriginal8b54caccbdedc8030792c13949386bbd); ?>
<?php endif; ?>

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="max-w-3xl px-6 pt-4 pb-6 mx-auto space-y-4">

            <div class="p-4 text-sm border rounded-xl border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-800/40 dark:bg-blue-900/20 dark:text-blue-100">
                SIKEU membaca data keuangan langsung dari tabel SIRUS lewat koneksi <code>sirus</code>.
                Login memakai user <b>sikeu</b> yang hanya punya hak <b>SELECT</b>, jadi tulis ke SIRUS ditolak Oracle.
                Skema tujuan: <b><?php echo e(config('database.connections.sirus.schema')); ?></b>.
            </div>

            <div class="flex items-center gap-3">
                <?php if (isset($component)) { $__componentOriginald411d1792bd6cc877d687758b753742c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald411d1792bd6cc877d687758b753742c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.primary-button','data' => ['type' => 'button','wire:click' => 'periksa','wire:loading.attr' => 'disabled','wire:target' => 'periksa']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('primary-button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','wire:click' => 'periksa','wire:loading.attr' => 'disabled','wire:target' => 'periksa']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                    <span wire:loading.remove wire:target="periksa">Periksa Sambungan</span>
                    <span wire:loading wire:target="periksa">Memeriksa…</span>
                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald411d1792bd6cc877d687758b753742c)): ?>
<?php $attributes = $__attributesOriginald411d1792bd6cc877d687758b753742c; ?>
<?php unset($__attributesOriginald411d1792bd6cc877d687758b753742c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald411d1792bd6cc877d687758b753742c)): ?>
<?php $component = $__componentOriginald411d1792bd6cc877d687758b753742c; ?>
<?php unset($__componentOriginald411d1792bd6cc877d687758b753742c); ?>
<?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($waktuPeriksa): ?>
                    <span class="text-xs text-muted dark:text-gray-400">Diperiksa <?php echo e($waktuPeriksa); ?></span>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($hasilPeriksa): ?>
                <ul class="overflow-hidden border divide-y rounded-xl border-hairline divide-hairline bg-surface-card dark:bg-gray-800 dark:border-gray-700 dark:divide-gray-700">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $hasilPeriksa; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $hasil): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <li class="flex items-start gap-3 px-4 py-3">
                            <span class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                                'mt-0.5 inline-flex shrink-0 items-center justify-center w-6 h-6 rounded-full text-xs font-bold',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $hasil['lulus'],
                                'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' => !$hasil['lulus'],
                            ]); ?>" aria-hidden="true"><?php echo e($hasil['lulus'] ? '✓' : '!'); ?></span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink dark:text-white">
                                    <?php echo e($hasil['judul']); ?>

                                    <span class="sr-only"><?php echo e($hasil['lulus'] ? '(lulus)' : '(gagal)'); ?></span>
                                </p>
                                <p class="text-sm break-words text-body dark:text-gray-300"><?php echo e($hasil['keterangan']); ?></p>
                            </div>
                        </li>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                </ul>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </div>
</div><?php /**PATH /home/avro/Desktop/LARAVEL/sikeu-php82/storage/framework/views/livewire/views/90152f60.blade.php ENDPATH**/ ?>