<?php
use Livewire\Component;
?>



<?php $__env->startSection('content'); ?>
    
    <div class="relative w-full h-[calc(100dvh-5rem)] overflow-y-auto bg-surface-soft dark:bg-gray-900">
        <?php
            $identitas = \App\Support\Identitas::get();
            $logoIdentitas = \App\Support\Identitas::logoUrl();
            $slides = collect(range(1, 9))->map(fn($n) => asset("images/landing/slide-{$n}.png"))->all();
            // Semua modul lewat halaman Masuk.
            $modules = [
                ['label' => 'Buku Besar', 'desc' => 'Mutasi & saldo per akun dari jurnal SIRUS.', 'href' => route('login'), 'publik' => false, 'icon' => 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25'],
                ['label' => 'Laporan Keuangan', 'desc' => 'Neraca, laba rugi & arus kas per periode.', 'href' => route('login'), 'publik' => false, 'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z'],
                ['label' => 'Tutup Buku', 'desc' => 'Kunci periode & tutup buku tahunan.', 'href' => route('login'), 'publik' => false, 'icon' => 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z'],
                ['label' => 'Jurnal Umum', 'desc' => 'Jurnal memorial / penyesuaian manual.', 'href' => route('login'), 'publik' => false, 'icon' => 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10'],
            ];
        ?>

        
        <section class="flex flex-col h-full">
            <div class="flex flex-col flex-1 min-h-0 px-4 pt-4 sm:px-6 sm:pt-6">

                
                <div x-data="{
                        i: 0,
                        jumlah: <?php echo e(count($slides)); ?>,
                        t: null,
                        jalan: true,
                        start() {
                            if (this.t || !this.jalan || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                            this.t = setInterval(() => this.i = (this.i + 1) % this.jumlah, 5000);
                        },
                        stop() { clearInterval(this.t); this.t = null },
                        alihJalan() { this.jalan = !this.jalan; this.jalan ? this.start() : this.stop() },
                        destroy() { this.stop() },
                    }" x-init="start()" x-on:mouseenter="stop()" x-on:mouseleave="start()"
                    x-on:focusin="stop()" x-on:focusout="start()"
                    class="relative flex flex-col justify-center flex-1 w-full mx-auto overflow-hidden min-h-[17rem] max-w-7xl rounded-3xl bg-surface-strong dark:bg-surface-dark">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $slides; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k => $src): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <img src="<?php echo e($src); ?>" alt="" loading="<?php echo e($k === 0 ? 'eager' : 'lazy'); ?>" decoding="async"
                            x-show="i === <?php echo e($k); ?>" <?php if($k > 0): ?> x-cloak <?php endif; ?>
                            x-transition:enter="transition-opacity duration-700 ease-out motion-reduce:transition-none"
                            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                            class="absolute inset-0 object-cover w-full h-full">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                    
                    <div class="absolute inset-0 bg-white/85 lg:bg-transparent lg:bg-gradient-to-r lg:from-white lg:via-white/85 lg:to-white/0
                                dark:bg-surface-dark/80 dark:lg:bg-transparent dark:lg:from-surface-dark/95 dark:lg:via-surface-dark/75 dark:lg:to-surface-dark/10"></div>

                    <div class="relative max-w-2xl px-6 pt-10 pb-16 sm:px-10 lg:pb-20 lg:px-14">
                        <p class="inline-flex items-center self-start gap-2 px-3 py-1.5 text-caption font-semibold uppercase tracking-wide rounded-full
                                  text-brand-green bg-surface-strong ring-1 ring-brand-green/20
                                  dark:text-brand-lime dark:bg-white/10 dark:ring-white/20">
                            <span class="w-2 h-2 rounded-full bg-brand-lime"></span>
                            Sistem Informasi Keuangan
                        </p>

                        <h1 class="mt-4 text-3xl font-bold leading-tight tracking-tight text-ink sm:text-5xl lg:text-6xl dark:text-white">
                            Selamat Datang di <span class="text-brand-green whitespace-nowrap dark:text-brand-lime">SIKEU</span>
                        </h1>

                        <p class="max-w-xl mt-3 text-base leading-relaxed sm:text-lg text-body dark:text-white/85">
                            Buku besar, laporan keuangan, dan tutup buku <b class="text-ink dark:text-white"><?php echo e($identitas->nama); ?></b> dalam satu aplikasi.
                        </p>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($identitas->tagline)): ?>
                            <p class="max-w-xl mt-2 italic text-muted dark:text-white/70">“<?php echo e($identitas->tagline); ?>”</p>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                        
                        <div class="flex flex-wrap items-center gap-3 mt-6 sm:mt-8">
                            <a href="<?php echo e(route('login')); ?>" wire:navigate
                                class="inline-flex items-center justify-center px-6 py-3 text-base font-semibold text-white transition shadow-sm rounded-xl bg-brand-green hover:bg-brand-green-active
                                       focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2
                                       dark:bg-brand-lime dark:text-ink dark:hover:bg-brand-lime/90 dark:focus-visible:ring-brand-lime dark:focus-visible:ring-offset-surface-dark">
                                Masuk
                            </a>
                        </div>
                    </div>

                    
                    <div class="absolute flex items-center gap-1 py-1 pl-2 pr-1 rounded-full right-4 bottom-14 bg-surface-dark/45 backdrop-blur-sm sm:right-6 lg:top-6 lg:bottom-auto">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $slides; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k => $src): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                            <button type="button" x-on:click="i = <?php echo e($k); ?>" aria-label="Foto <?php echo e($k + 1); ?>"
                                :aria-current="i === <?php echo e($k); ?> ? 'true' : 'false'"
                                class="grid w-6 h-6 rounded-full place-items-center focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                                <span class="block h-1.5 rounded-full transition-all motion-reduce:transition-none"
                                    :class="i === <?php echo e($k); ?> ? 'w-5 bg-brand-lime' : 'w-1.5 bg-white/60'"></span>
                            </button>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                        <button type="button" x-on:click="alihJalan()" :aria-label="jalan ? 'Jeda slide foto' : 'Putar slide foto'"
                            class="grid ml-1 rounded-full w-8 h-8 place-items-center text-white bg-white/15 hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                            <svg x-show="jalan" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6 4h3v12H6zM11 4h3v12h-3z" /></svg>
                            <svg x-show="!jalan" x-cloak class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6 4l10 6-10 6z" /></svg>
                        </button>
                    </div>
                </div>

                
                <nav aria-label="Modul SIKEU" class="relative grid w-full grid-cols-2 gap-3 mx-auto -mt-10 shrink-0 max-w-6xl lg:grid-cols-4 sm:px-6">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $modules; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $modul): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <a href="<?php echo e($modul['href']); ?>" <?php if (! ($modul['publik'])): ?> wire:navigate <?php endif; ?>
                            class="flex flex-col gap-2 p-4 transition border shadow-sm sm:gap-3 sm:p-5 group rounded-2xl bg-canvas border-hairline hover:border-brand-green hover:shadow-md
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2
                                   dark:bg-gray-800 dark:border-gray-700 dark:hover:border-brand-lime dark:focus-visible:ring-brand-lime dark:focus-visible:ring-offset-gray-900">
                            <span class="grid w-10 h-10 rounded-xl place-items-center <?php echo e($modul['publik'] ? 'bg-brand-lime text-ink' : 'bg-surface-strong text-brand-green dark:bg-brand-lime/10 dark:text-brand-lime'); ?>">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="<?php echo e($modul['icon']); ?>" />
                                </svg>
                            </span>
                            <span>
                                <span class="block text-sm font-semibold leading-snug sm:text-base text-ink dark:text-gray-100"><?php echo e($modul['label']); ?></span>
                                <span class="hidden mt-1 text-sm leading-snug sm:block text-muted"><?php echo e($modul['desc']); ?></span>
                            </span>
                            <span class="items-center hidden gap-1 mt-auto text-sm font-semibold sm:inline-flex text-brand-green dark:text-brand-lime">
                                <?php echo e($modul['publik'] ? 'Buka tanpa login' : 'Masuk untuk membuka'); ?>

                                <svg class="w-4 h-4 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                            </span>
                        </a>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                </nav>
            </div>

            
            <footer class="px-4 py-3 shrink-0">
                <p class="text-center text-caption text-muted">
                    © <?php echo e(date('Y')); ?> SIKEU — <?php echo e($identitas->nama); ?> · Powered by Aturapi Data Technology ·
                    <button type="button" x-data x-on:click="$dispatch('open-modal', { name: 'tentang' })"
                        class="font-semibold underline rounded text-brand-green dark:text-brand-lime focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Tentang <?php echo e(\App\Support\Identitas::namaPendek()); ?></button>
                </p>
            </footer>
        </section>

    
    <?php if (isset($component)) { $__componentOriginal9f64f32e90b9102968f2bc548315018c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9f64f32e90b9102968f2bc548315018c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.modal','data' => ['name' => 'tentang','size' => '3xl','focusable' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('modal'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'tentang','size' => '3xl','focusable' => true]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

        <div class="flex justify-end -mt-1 -mr-1">
            <button type="button" x-on:click="$dispatch('close-modal', { name: 'tentang' })" aria-label="Tutup"
                class="grid w-9 h-9 rounded-lg place-items-center text-muted hover:bg-surface-card focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green dark:hover:bg-gray-800">
                <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" /></svg>
            </button>
        </div>
        <div class="grid items-start grid-cols-1 gap-8 mx-auto max-w-7xl lg:grid-cols-3">
            <div class="flex flex-col items-center text-center lg:items-start lg:text-left">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($logoIdentitas): ?>
                    <img src="<?php echo e($logoIdentitas); ?>" alt="<?php echo e($identitas->nama); ?>" class="object-contain w-auto h-20 max-w-full p-3 bg-white rounded-2xl">
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                
                <h2 class="<?php echo e($logoIdentitas ? 'sr-only' : 'text-2xl font-bold text-ink dark:text-white'); ?>"><?php echo e($identitas->nama); ?></h2>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($identitas->tagline)): ?>
                    <p class="mt-1 italic text-muted dark:text-gray-400"><?php echo e($identitas->tagline); ?></p>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <div class="lg:col-span-2">
                <p class="text-xs font-semibold tracking-[0.12em] uppercase text-brand-green dark:text-brand-lime">Tentang kami</p>
                <p class="mt-2 leading-relaxed whitespace-pre-line text-body dark:text-gray-300"><?php echo e($identitas->deskripsi ?: 'Profil instansi belum diisi — atur di menu Sistem › Identitas Instansi.'); ?></p>

                <dl class="grid grid-cols-1 gap-4 mt-6 text-sm sm:grid-cols-2">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = [
                        ['Alamat', \App\Support\Identitas::alamatLengkap()],
                        ['Jam Operasional', $identitas->jam_operasional ?? null],
                        ['Telepon', collect([$identitas->telp, $identitas->fax ? 'Fax ' . $identitas->fax : null])->filter()->implode(' · ')],
                        ['Email', $identitas->email],
                        ['Website', $identitas->website],
                        [$identitas->jabatan_pimpinan ?: 'Pimpinan', $identitas->pimpinan],
                    ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as [$label, $nilai]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($nilai)): ?>
                            <div>
                                <dt class="font-semibold text-ink dark:text-gray-100"><?php echo e($label); ?></dt>
                                <dd class="mt-0.5 text-body dark:text-gray-300"><?php echo e($nilai); ?></dd>
                            </div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                </dl>
            </div>
        </div>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9f64f32e90b9102968f2bc548315018c)): ?>
<?php $attributes = $__attributesOriginal9f64f32e90b9102968f2bc548315018c; ?>
<?php unset($__attributesOriginal9f64f32e90b9102968f2bc548315018c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9f64f32e90b9102968f2bc548315018c)): ?>
<?php $component = $__componentOriginal9f64f32e90b9102968f2bc548315018c; ?>
<?php unset($__componentOriginal9f64f32e90b9102968f2bc548315018c); ?>
<?php endif; ?>
    </div>
<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.app-welcome', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/avro/Desktop/LARAVEL/sikeu-php82/storage/framework/views/livewire/views/f0599b3c.blade.php ENDPATH**/ ?>