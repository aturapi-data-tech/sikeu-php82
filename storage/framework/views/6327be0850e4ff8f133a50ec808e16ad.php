
<?php $logoUrl = \App\Support\Identitas::logoUrl(); $namaInstansi = \App\Support\Identitas::get()->nama; ?>
<div class="flex flex-col items-center gap-2">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($logoUrl): ?>
        <img src="<?php echo e($logoUrl); ?>" alt="<?php echo e($namaInstansi); ?>" class="object-contain w-auto h-20">
    <?php else: ?>
        <span class="text-sm font-semibold text-center text-ink dark:text-gray-100"><?php echo e($namaInstansi); ?></span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /home/avro/Desktop/LARAVEL/sikeu-php82/resources/views/components/application-logo.blade.php ENDPATH**/ ?>