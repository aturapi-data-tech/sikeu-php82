<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'name',
    'show' => false,

    // width preset: md|lg|xl|2xl|3xl|4xl|full
    'size' => 'lg',

    // height preset: auto|full
    'height' => 'auto',

    // padding panel — null = otomatis (full → p-0 edge-to-edge, selain itu p-4 sm:p-6)
    'padding' => null,
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
    'name',
    'show' => false,

    // width preset: md|lg|xl|2xl|3xl|4xl|full
    'size' => 'lg',

    // height preset: auto|full
    'height' => 'auto',

    // padding panel — null = otomatis (full → p-0 edge-to-edge, selain itu p-4 sm:p-6)
    'padding' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $widthClass = match ($size) {
        'md' => 'sm:max-w-md',
        'lg' => 'sm:max-w-lg',
        'xl' => 'sm:max-w-xl',
        '2xl' => 'sm:max-w-2xl',
        '3xl' => 'sm:max-w-3xl',
        '4xl' => 'sm:max-w-4xl',
        'full' => 'sm:max-w-none',
        default => 'sm:max-w-lg',
    };

    // Safe space supaya panel tidak mepet sisi layar
    $safeMarginClass = 'm-3 sm:m-6';

    // Tinggi panel:
    // - auto: tinggi sesuai konten
    // - full: isi layar tapi masih punya safe margin (jadi tidak nempel)
    $heightClass = match ($height) {
        'full' => 'h-[calc(100dvh-2.5rem)] sm:h-[calc(100dvh-3rem)]',
        default => 'h-auto',
    };

    // Scroll internal:
    // kalau height full → panel dibuat scrollable internal (biar body tidak scroll)
    $scrollClass = $height === 'full' ? 'overflow-y-auto' : 'overflow-visible';

    // Padding panel: full → p-0 (edge-to-edge, header/footer sudah punya padding sendiri)
    $padding = $padding ?? ($height === 'full' ? 'p-0' : 'p-4 sm:p-6');
?>

<div x-data="{
    show: <?php echo \Illuminate\Support\Js::from($show)->toHtml() ?>,
    pemicu: null,
    focusables() {
        let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
        // hanya yang terlihat — elemen di bagian x-show tersembunyi tidak boleh menerima fokus
        return [...$el.querySelectorAll(selector)].filter(el => !el.hasAttribute('disabled') && el.getClientRects().length > 0)
    },
    // Escape / klik latar: abaikan bila dialog anak (konfirmasi hapus, peringatan belum disimpan) sedang terbuka;
    // bila isi modal dijaga x-dirty-modal-content, lewat tryClose() supaya perubahan tidak hilang diam-diam.
    mintaTutup() {
        const anakTerbuka = [...$el.querySelectorAll('[data-dialog-anak]')].some(d => getComputedStyle(d).display !== 'none');
        if (anakTerbuka) return;
        const penjaga = $el.querySelector('[data-penjaga-tutup]');
        if (penjaga) { Alpine.$data(penjaga).tryClose(); return; }
        this.show = false;
    },
    firstFocusable() { return this.focusables()[0] },
    lastFocusable() { return this.focusables().slice(-1)[0] },
    nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
    prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
    nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
    prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) - 1 },
}" x-init="$watch('show', value => {
    if (value) {
        pemicu = document.activeElement;
        document.body.classList.add('overflow-hidden');
        <?php echo e($attributes->has('focusable') ? 'setTimeout(() => firstFocusable()?.focus(), 100)' : ''); ?>

    } else {
        document.body.classList.remove('overflow-hidden');
        // fokus kembali ke tombol yang membuka modal (bila masih ada setelah render ulang Livewire)
        if (pemicu?.isConnected) pemicu.focus();
        pemicu = null;
    }
})"
    x-on:open-modal.window="$event.detail.name === '<?php echo e($name); ?>' ? show = true : null"
    x-on:close-modal.window="$event.detail.name === '<?php echo e($name); ?>' ? show = false : null"
    x-on:keydown.escape.window="show && mintaTutup()"
    
    x-on:keydown.tab.prevent.stop="$event.shiftKey || nextFocusable()?.focus()"
    x-on:keydown.shift.tab.prevent.stop="prevFocusable()?.focus()"
    x-show="show" class="fixed inset-0 z-50"
    style="display: <?php echo e($show ? 'block' : 'none'); ?>;">
    
    <div x-show="show" x-on:click="mintaTutup()" x-transition.opacity
        class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75"></div>

    
    <div class="fixed inset-0 flex items-center justify-center p-0">
        
        <div x-show="show" x-transition role="dialog" aria-modal="true"
            class="<?php echo e($safeMarginClass); ?> w-full <?php echo e($widthClass); ?> bg-surface-soft dark:bg-gray-900 rounded-2xl shadow-xl <?php echo e($heightClass); ?> <?php echo e($scrollClass); ?> <?php echo e($padding); ?>">
            <?php echo e($slot); ?>

        </div>
    </div>
</div>
<?php /**PATH /home/avro/Desktop/LARAVEL/sikeu-php82/resources/views/components/modal.blade.php ENDPATH**/ ?>