<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'type' => 'button',
    'disabled' => false,
]));

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

foreach (array_filter(([
    'type' => 'button',
    'disabled' => false,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    // Loading otomatis: tombol ber-wire:click → nonaktif + spinner x-loading selama AKSI ITU berjalan.
    // Dilewati bila pemakai sudah mengatur wire:loading sendiri (atribut / isi slot) — tidak ada indikator ganda.
    $aksiServer = $attributes->whereStartsWith('wire:click')->first();
    $targetLoading = $attributes->get('wire:target') ?? $aksiServer;
    $pasangNonaktif = $aksiServer && $attributes->whereStartsWith('wire:loading')->isEmpty();
    $pasangSpinner = $aksiServer && !str_contains((string) $slot, 'wire:loading');
?>

<button type="<?php echo e($type); ?>" <?php echo e($disabled ? 'disabled' : ''); ?>

    <?php if($pasangNonaktif): ?> wire:loading.attr="disabled" <?php if (! ($attributes->has('wire:target'))): ?> wire:target="<?php echo e($targetLoading); ?>" <?php endif; ?> <?php endif; ?>
    <?php echo e($attributes->class([
        // Flowbite v4 (warna = token brand di tailwind.config.cjs / app.css --fb-*)
        'inline-flex items-center justify-center gap-2 box-border border',
        'px-4 py-2.5 rounded-base shadow-xs',
        'text-sm font-medium leading-5',
        'transition-colors duration-150',
        'focus:outline-none focus:ring-4',

        // Secondary — cincin fokus brand (Flowbite: neutral-tertiary, terlalu pucat untuk keyboard)
        'text-body bg-neutral-secondary-medium border-default-medium hover:bg-neutral-tertiary-medium hover:text-heading focus:ring-brand-medium',
        'dark:text-gray-200 dark:hover:text-white',

        // disabled
        'disabled:opacity-50',
        'disabled:cursor-not-allowed',
        'disabled:pointer-events-none',
    ])); ?>>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($pasangSpinner): ?>
        <?php if (isset($component)) { $__componentOriginal84bc13f46ede078ae58666238de3da00 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal84bc13f46ede078ae58666238de3da00 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.loading','data' => ['wire:loading.delay' => true,'wire:target' => ''.e($targetLoading).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('loading'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:loading.delay' => true,'wire:target' => ''.e($targetLoading).'']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal84bc13f46ede078ae58666238de3da00)): ?>
<?php $attributes = $__attributesOriginal84bc13f46ede078ae58666238de3da00; ?>
<?php unset($__attributesOriginal84bc13f46ede078ae58666238de3da00); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal84bc13f46ede078ae58666238de3da00)): ?>
<?php $component = $__componentOriginal84bc13f46ede078ae58666238de3da00; ?>
<?php unset($__componentOriginal84bc13f46ede078ae58666238de3da00); ?>
<?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php echo e($slot); ?>

</button>
<?php /**PATH /home/avro/Desktop/LARAVEL/sikeu-php82/resources/views/components/secondary-button.blade.php ENDPATH**/ ?>