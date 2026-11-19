<?php

use Livewire\Component;
use App\Support\GlosariumKeuangan;

/**
 * PANDUAN — ISTILAH KEUANGAN. Glosarium statis (tanpa mutasi), isi dari App\Support\GlosariumKeuangan.
 * Pencarian disaring di browser (Alpine) supaya tidak ada request tiap ketikan. Terbuka untuk semua user login.
 */
new class extends Component {
    public function bagian(): array
    {
        return GlosariumKeuangan::bagian();
    }
};
?>

<div>
    <x-page-title title="Istilah Keuangan" subtitle="Glosarium istilah akuntansi & laporan keuangan yang dipakai di SIKEU, dengan contoh rumah sakit" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900"
        x-data="{ cari: '', cocok(teks) { const kata = this.cari.trim().toLowerCase(); return kata === '' || teks.includes(kata); } }">
        <div class="grid grid-cols-1 gap-6 px-6 py-6 mx-auto max-w-7xl lg:grid-cols-4">

            {{-- DAFTAR ISI + CARI --}}
            <nav class="lg:sticky lg:top-24 lg:self-start">
                <div class="p-4 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <label for="cariIstilah" class="text-xs font-semibold tracking-wide uppercase text-muted">Cari istilah</label>
                    <x-text-input id="cariIstilah" x-model="cari" placeholder="mis. ekuitas, akrual, HPP" class="mt-1" />
                    <p class="mt-4 mb-2 text-xs font-semibold tracking-wide uppercase text-muted">Daftar isi</p>
                    <ul class="space-y-1 text-sm">
                        @foreach ($this->bagian() as $id => $bagian)
                            <li><a href="#{{ $id }}" class="block px-2 py-1 rounded-lg text-body hover:bg-surface-soft hover:text-brand-green dark:text-gray-300">{{ $bagian['judul'] }}</a></li>
                        @endforeach
                    </ul>
                    <p class="pt-3 mt-3 text-xs border-t border-hairline text-muted">
                        Rumus kunci: <b>Aset = Liabilitas + Ekuitas</b> · <b>Pendapatan − Beban = Laba</b> · setiap jurnal <b>Debit = Kredit</b>.
                    </p>
                </div>
            </nav>

            {{-- ISI --}}
            <article class="space-y-6 lg:col-span-3">
                @foreach ($this->bagian() as $id => $bagian)
                    <section id="{{ $id }}" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                        <h2 class="text-xl font-bold text-ink dark:text-gray-100">{{ $bagian['judul'] }}</h2>
                        <p class="mt-1 text-sm text-muted dark:text-gray-400">{{ $bagian['pengantar'] }}</p>
                        <dl class="mt-4 divide-y divide-hairline dark:divide-gray-700">
                            @foreach ($bagian['istilah'] as [$istilah, $arti, $contoh])
                                <div class="grid grid-cols-1 gap-1 py-3 md:grid-cols-12 md:gap-4"
                                    x-show="cocok(@js(mb_strtolower($istilah . ' ' . $arti . ' ' . $contoh)))">
                                    <dt class="font-semibold md:col-span-3 text-ink dark:text-gray-100">{{ $istilah }}</dt>
                                    <dd class="text-sm md:col-span-5 text-body dark:text-gray-300">{{ $arti }}</dd>
                                    <dd class="text-sm md:col-span-4 text-muted dark:text-gray-400"><span class="sr-only">Contoh: </span>{{ $contoh }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endforeach
            </article>
        </div>
    </div>
</div>
