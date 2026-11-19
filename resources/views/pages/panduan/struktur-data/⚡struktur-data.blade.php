<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Support\StrukturData;

/**
 * PANDUAN › STRUKTUR DATA — gambar relasi tabel SIKEU + rincian kolom (PK/FK) untuk cross-check.
 * Dibaca langsung dari kamus data Oracle lewat App\Support\StrukturData, jadi selalu sesuai database yang dipakai.
 * Hanya baca. Terbuka untuk semua pengguna login.
 */
new class extends Component {
    /** false = gambar hanya kolom kunci (PK/FK) */
    public bool $semuaKolom = true;

    #[Computed]
    public function data(): array
    {
        return StrukturData::baca();
    }

    #[Computed]
    public function gambar(): array
    {
        return StrukturData::tataLetak($this->data, $this->semuaKolom);
    }
};
?>

<div>
    <x-page-title title="Struktur Data"
        subtitle="Tabel SIKEU dan relasinya — dibaca langsung dari database, untuk cross-check" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="px-6 pt-2 pb-6 space-y-4">
            @php
                $data = $this->data;
                $gambar = $this->gambar;
                $jumlahTabel = count($data['tabel']);
                $jumlahRelasi = count(array_filter($data['relasi'], fn ($fk) => $fk['dari'] !== $fk['ke']));
            @endphp

            <div class="flex flex-wrap items-center gap-2 text-sm text-body dark:text-gray-300">
                <x-badge variant="info">{{ $jumlahTabel }} tabel</x-badge>
                <x-badge variant="info">{{ $jumlahRelasi }} relasi (foreign key)</x-badge>
                <span>Arahkan kursor ke tabel untuk menyorot relasinya; klik judul tabel untuk rincian kolomnya.</span>
                <div class="ml-auto"><x-toggle wire:model.live="semuaKolom" :trueValue="false" :falseValue="true" label="Kolom kunci saja" /></div>
            </div>

            {{-- GAMBAR RELASI --}}
            <div class="overflow-x-auto bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900" x-data="{ pilih: null }">
                <svg width="{{ $gambar['lebar'] }}" height="{{ $gambar['tinggi'] }}" viewBox="0 0 {{ $gambar['lebar'] }} {{ $gambar['tinggi'] }}"
                    class="block font-sans" role="img" aria-label="ER diagram tabel SIKEU">
                    {{-- judul kelompok --}}
                    @foreach ($gambar['kolom'] as $kolom)
                        <text x="{{ $kolom['x'] }}" y="28" class="text-xs font-semibold uppercase fill-current text-muted dark:text-gray-400">{{ $kolom['nama'] }}</text>
                    @endforeach

                    {{-- garis relasi: dari baris kolom FK → baris kolom PK tabel tujuan (titik bulat di ujung FK, seperti DBeaver) --}}
                    @foreach ($gambar['garis'] as $garis)
                        <g class="transition-opacity motion-reduce:transition-none"
                            :class="pilih && pilih !== '{{ $garis['dari'] }}' && pilih !== '{{ $garis['ke'] }}' ? 'opacity-5' : ''">
                            <path d="{{ $garis['d'] }}" fill="none" stroke="currentColor" stroke-width="1.25" stroke-dasharray="4 3"
                                class="text-brand-green dark:text-brand-lime" :class="pilih ? '' : 'opacity-70'">
                                <title>{{ strtolower($garis['dari']) }}.{{ strtolower($garis['kolom']) }} → {{ strtolower($garis['ke']) }}</title>
                            </path>
                            <circle cx="{{ $garis['xa'] }}" cy="{{ $garis['ya'] }}" r="3.5" class="fill-current text-brand-green dark:text-brand-lime" />
                        </g>
                    @endforeach

                    {{-- kotak tabel: judul + daftar kolom --}}
                    @foreach ($gambar['kotak'] as $namaTabel => $kotak)
                        <g x-on:mouseenter="pilih = '{{ $namaTabel }}'" x-on:mouseleave="pilih = null">
                            <rect x="{{ $kotak['x'] }}" y="{{ $kotak['y'] }}" width="{{ $gambar['lebarKotak'] }}" height="{{ $kotak['tinggi'] }}" rx="6" stroke-width="1.5"
                                class="stroke-current fill-canvas text-hairline dark:fill-gray-900 dark:text-gray-600"
                                :class="pilih === '{{ $namaTabel }}' ? 'text-brand-green dark:text-brand-lime' : ''" />
                            <a href="#tabel-{{ strtolower($namaTabel) }}">
                                <rect x="{{ $kotak['x'] + 1 }}" y="{{ $kotak['y'] + 1 }}" width="{{ $gambar['lebarKotak'] - 2 }}" height="{{ $gambar['tinggiJudul'] - 1 }}" rx="5"
                                    class="fill-current text-surface-strong dark:text-gray-800" />
                                <text x="{{ $kotak['x'] + 10 }}" y="{{ $kotak['y'] + 19 }}" class="font-mono text-xs font-bold fill-current text-ink dark:text-gray-100">{{ strtolower($namaTabel) }}</text>
                            </a>
                            <line x1="{{ $kotak['x'] }}" y1="{{ $kotak['y'] + $gambar['tinggiJudul'] }}" x2="{{ $kotak['x'] + $gambar['lebarKotak'] }}" y2="{{ $kotak['y'] + $gambar['tinggiJudul'] }}"
                                stroke="currentColor" class="text-hairline dark:text-gray-600" />
                            @foreach ($kotak['kolom'] as $urutan => $kolom)
                                @php $yTeks = $kotak['y'] + $gambar['tinggiJudul'] + $urutan * $gambar['tinggiBaris'] + 14; @endphp
                                <text x="{{ $kotak['x'] + 10 }}" y="{{ $yTeks }}"
                                    class="font-mono text-xs fill-current {{ $kolom['pk'] ? 'font-bold text-ink dark:text-gray-100' : 'text-body dark:text-gray-300' }}">{{ strtolower($kolom['nama']) }}</text>
                                <text x="{{ $kotak['x'] + $gambar['lebarKotak'] - 10 }}" y="{{ $yTeks }}" text-anchor="end"
                                    class="font-mono text-xs font-semibold fill-current {{ $kolom['pk'] ? 'text-fg-success-strong' : 'text-brand-green dark:text-brand-lime' }}">{{ $kolom['pk'] && $kolom['fk'] ? 'PK FK' : ($kolom['pk'] ? 'PK' : ($kolom['fk'] ? 'FK' : '')) }}</text>
                            @endforeach
                        </g>
                    @endforeach
                </svg>
                <p class="px-4 py-2 text-xs border-t text-muted border-hairline dark:text-gray-400 dark:border-gray-700">
                    Garis putus-putus = foreign key: titik bulat di kolom <b>FK</b> tabel penyimpan rujukan, ujung lain di kolom <b>PK</b> tabel yang dirujuk (mis. jurnal_dtls.jurnal_id → jurnal_hdrs.jurnal_id). Kolom tebal = PK.
                    Relasi ke tabel sendiri (akun induk, baris induk laporan, jurnal pembalik) tidak digambar — tercantum di rincian kolom.
                </p>
            </div>

            {{-- RINCIAN PER KELOMPOK --}}
            @foreach ($data['kelompok'] as $namaKelompok => $isi)
                <div class="bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900" wire:key="kel-{{ $loop->index }}">
                    <div class="px-4 py-3 border-b border-hairline dark:border-gray-700">
                        <p class="font-semibold text-ink dark:text-gray-100">{{ $namaKelompok }}</p>
                        <p class="text-xs text-muted dark:text-gray-400">{{ $isi['ket'] }}</p>
                    </div>
                    <div class="p-4 space-y-2">
                        @foreach ($isi['tabel'] as $namaTabel)
                            @php $tabel = $data['tabel'][$namaTabel]; @endphp
                            <details id="tabel-{{ strtolower($namaTabel) }}" class="border scroll-mt-24 rounded-xl border-hairline dark:border-gray-700">
                                <summary class="flex flex-wrap items-center gap-2 px-3 py-2 cursor-pointer">
                                    <span class="font-mono text-sm font-semibold text-ink dark:text-gray-100">{{ strtolower($namaTabel) }}</span>
                                    <x-badge variant="gray">{{ count($tabel['kolom']) }} kolom</x-badge>
                                    @if ($tabel['merujuk'] !== [])
                                        <span class="text-xs text-muted dark:text-gray-400">→ {{ strtolower(implode(', ', $tabel['merujuk'])) }}</span>
                                    @endif
                                </summary>
                                <div class="px-3 pb-3 space-y-2">
                                    @if ($tabel['dirujuk'] !== [])
                                        <p class="text-xs text-muted dark:text-gray-400">Dirujuk oleh: <span class="font-mono">{{ strtolower(implode(', ', $tabel['dirujuk'])) }}</span></p>
                                    @endif
                                    <div class="overflow-x-auto border rounded-lg border-hairline dark:border-gray-700">
                                        <table class="ds-table ds-table-entri">
                                            <thead><tr><th>Kolom</th><th>Tipe</th><th class="ds-c">Wajib</th><th>Kunci</th></tr></thead>
                                            <tbody>
                                                @foreach ($tabel['kolom'] as $kolom)
                                                    <tr wire:key="kol-{{ $namaTabel }}-{{ $kolom['nama'] }}">
                                                        <td class="font-mono {{ $kolom['pk'] ? 'font-semibold text-ink dark:text-gray-100' : '' }}">{{ strtolower($kolom['nama']) }}</td>
                                                        <td class="font-mono text-xs text-muted dark:text-gray-400">{{ $kolom['tipe'] }}</td>
                                                        <td class="ds-c">{{ $kolom['wajib'] ? 'Ya' : '' }}</td>
                                                        <td>
                                                            @if ($kolom['pk'])<x-badge variant="success">PK</x-badge>@endif
                                                            @if ($kolom['fk'])
                                                                <a href="#tabel-{{ strtolower(\Illuminate\Support\Str::before($kolom['fk'], '.')) }}" class="inline-flex items-center gap-1 font-mono text-xs underline text-brand-green dark:text-brand-lime">
                                                                    FK → {{ strtolower($kolom['fk']) }}</a>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </details>
                        @endforeach
                    </div>
                </div>
            @endforeach

            @if ($data['laravel'] !== [])
                <p class="text-xs text-muted dark:text-gray-400">Tabel bawaan Laravel (tidak digambar): <span class="font-mono">{{ strtolower(implode(', ', $data['laravel'])) }}</span>.</p>
            @endif
        </div>
    </div>
</div>
