@props([
    'variant' => 'danger', // danger|danger-soft|warning-soft|primary|secondary|outline
    'action', // contoh: "delete(10)" atau "delete('10')"
    'title' => 'Konfirmasi',
    'message' => 'Apakah Anda yakin?',
    'confirmText' => 'Ya',
    'cancelText' => 'Batal',
    'disabled' => false,
    'wireTarget' => null, // default: nama method dari $action — override bila perlu target lain
])

@php
    // id unik supaya aman dipakai berulang di table
    $confirmId = 'confirm_' . md5($action . '|' . ($attributes->get('wire:key') ?? '') . '|' . uniqid('', true));

    // Target wire:loading — cukup nama method (tanpa argumen) supaya aman dari
    // tanda kutip di $action. Konsekuensi: semua trigger ber-method sama ikut
    // disabled selama proses; justru mencegah aksi ganda dari baris lain.
    $loadingTarget = $wireTarget ?? \Illuminate\Support\Str::before($action, '(');

    // class trigger button — disesuaikan dengan komponen button standar
    $base = 'inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium whitespace-nowrap transition-colors duration-150';

    // Warna tombol konfirmasi & ikon judul mengikuti pemicunya
    $jenisKonfirmasi = match ($variant) {
        'primary', 'secondary', 'outline' => 'primary',
        'warning-soft' => 'warning',
        default => 'danger',
    };
    $ikonJudul = $jenisKonfirmasi === 'danger'
        ? 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'
        : 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';

    $triggerButtonClass = match ($variant) {
        'primary'
            => $base . ' text-white bg-brand-green hover:bg-brand-green/90 focus:outline-none focus:ring-4 focus:ring-brand-lime/40 dark:bg-brand-lime dark:text-gray-900 dark:hover:bg-brand-lime/90',
        'secondary'
            => $base . ' text-gray-700 bg-gray-100 border border-gray-300 hover:bg-gray-200 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-700',
        'outline'
            => $base . ' text-brand-green bg-brand-green/10 border border-brand-green/30 hover:bg-brand-green hover:text-white hover:border-brand-green focus:outline-none focus:ring-4 focus:ring-brand-green/20 dark:text-brand-lime dark:bg-brand-lime/10 dark:border-brand-lime/30 dark:hover:bg-brand-lime dark:hover:text-gray-900',
        // Merah bertint (bukan solid) — tampilan tombol hapus ikon di dalam tabel/form,
        // seperti di e-resep. Dibuat jadi VARIAN supaya pemakai tak perlu override
        // `!important` (dilarang Aturan Umum standar-ui-komponen.md).
        // Kuning lembut — aksi KOREKSI (Buka Kunci, batal proses): berbeda dari merah hapus supaya
        // dua tombol berisiko di satu sel tidak tampak sama.
        'warning-soft'
            => $base . ' text-amber-700 bg-amber-50 border border-amber-200 hover:bg-amber-100 hover:text-amber-700 hover:border-amber-300 focus:outline-none focus:ring-4 focus:ring-amber-200 dark:text-amber-400 dark:bg-amber-900/20 dark:border-amber-800/30 dark:hover:bg-amber-900/30 dark:hover:text-amber-300 dark:focus:ring-amber-900',
        'danger-soft'
            => $base . ' text-red-600 bg-red-50 border border-red-200 hover:bg-red-100 hover:text-red-700 hover:border-red-300 focus:outline-none focus:ring-4 focus:ring-red-200 dark:text-red-400 dark:bg-red-900/20 dark:border-red-800/30 dark:hover:bg-red-900/30 dark:hover:text-red-300 dark:focus:ring-red-900',
        default
            => $base . ' text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-300 dark:bg-red-600 dark:hover:bg-red-700 dark:focus:ring-red-900',
    };
@endphp

<div x-data="{
    show: false,
    open() {
        this.show = true;
        document.body.classList.add('overflow-hidden');
        this.$nextTick(() => {
            // fokus ke tombol confirm biar enak keyboard
            this.$refs.confirmButton?.focus();
        });
    },
    close() {
        this.show = false;
        document.body.classList.remove('overflow-hidden');
    },
    confirm() {
        this.close();
        $wire.{{ $action }};
    }
}" x-on:keydown.escape.window="if (show) close()" class="inline-block">
    {{-- Trigger — disabled + spinner selama aksi $wire berjalan, supaya proses
         lama tidak terkesan "tidak jalan" setelah user menekan tombol konfirmasi --}}
    <button type="button" @disabled($disabled) x-on:click="open()" wire:loading.attr="disabled"
        wire:target="{{ $loadingTarget }}"
        {{ $attributes->merge(['class' => $triggerButtonClass . ' disabled:opacity-60 disabled:cursor-not-allowed']) }}>
        <span wire:loading.remove wire:target="{{ $loadingTarget }}" class="inline-flex items-center gap-2">
            {{ $slot }}
        </span>
        <span wire:loading wire:target="{{ $loadingTarget }}" class="inline-flex items-center gap-2">
            <x-loading class="w-4 h-4" />
            Memproses...
        </span>
    </button>

    {{-- Modal konfirmasi — STANDAR MODUL DOKUMEN versi kecil: panel seperti <x-modal size="md">, header
         <x-modul-dokumen.header>, isi pesan, footer Batal + tombol konfirmasi berwarna SAMA dengan pemicunya
         (hijau = langkah biasa, kuning = koreksi, merah = hapus/buang). Transisi disamakan dengan <x-modal>. --}}
    <div x-cloak x-show="show" data-dialog-anak class="fixed inset-0 z-[90]" aria-labelledby="{{ $confirmId }}_title" role="dialog"
        aria-modal="true">
        <div class="fixed inset-0 bg-gray-900/50 dark:bg-gray-900/70" x-on:click="close()"
            x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-120"
            x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>

        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="flex flex-col w-full max-w-md overflow-hidden border shadow-2xl bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700"
                x-on:click.stop x-transition:enter="ease-out duration-150"
                x-transition:enter-start="opacity-0 translate-y-2 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-120"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-2 sm:scale-95">
                <x-modul-dokumen.header :judul="$title" :ikon="$ikonJudul" tutupAlpine="close()" id="{{ $confirmId }}_title" />

                <div class="px-6 py-4 text-sm leading-relaxed text-body dark:text-gray-300">{{ $message }}</div>

                <div class="flex items-center justify-end gap-2 px-6 py-3 border-t bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                    <x-secondary-button type="button" x-on:click="close()"><x-ikon nama="batal" /> {{ $cancelText }}</x-secondary-button>
                    @switch($jenisKonfirmasi)
                        @case('primary')
                            <x-primary-button type="button" x-ref="confirmButton" x-on:click="confirm()"><x-ikon nama="setujui" /> {{ $confirmText }}</x-primary-button>
                            @break
                        @case('warning')
                            <x-warning-button type="button" x-ref="confirmButton" x-on:click="confirm()"><x-ikon nama="setujui" /> {{ $confirmText }}</x-warning-button>
                            @break
                        @default
                            <x-danger-button type="button" x-ref="confirmButton" x-on:click="confirm()"><x-ikon nama="setujui" /> {{ $confirmText }}</x-danger-button>
                    @endswitch
                </div>
            </div>
        </div>
    </div>
</div>
