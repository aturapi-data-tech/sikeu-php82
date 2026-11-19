{{--
    Satu baris laporan Neraca (dipakai bentuk Bertingkat & Kanan-kiri di pages/laporan/neraca).
    Props:
      baris         satu baris hasil LaporanKeuangan::neraca()['baris'] (kode, label, tipe, tebal, induk_baris_id, nilai, rincian)
      kolomList     ['akhir' => [...], 'awal' => [...]] — urutan kolom angka
      periode       YYYYMM, untuk membuka mutasi akun (Buku Besar)
      merahKolom    [kunciKolom => true] kolom yang TIDAK seimbang — baris TA / TLE ditandai merah di kolom itu
--}}
@props(['baris', 'kolomList', 'periode', 'merahKolom' => []])

@php
    $teks = fn (?float $nilai) => $nilai === null ? '' : ($nilai < 0 ? '(' . \App\Support\Format::uang(-$nilai) . ')' : \App\Support\Format::uang($nilai));
    $barisTotal = in_array($baris['kode'], ['TA', 'TLE'], true);
@endphp

<tr wire:key="nr-{{ $baris['kode'] }}" x-data="{ buka: false }"
    class="{{ $baris['tipe'] === 'R' && $baris['tebal'] === '1' ? 'bg-surface-card dark:bg-gray-800' : '' }}">
    <td class="{{ $baris['induk_baris_id'] ? 'pl-8' : '' }} {{ $baris['tebal'] === '1' ? 'font-bold text-ink dark:text-gray-100' : 'text-body dark:text-gray-300' }}">
        @if ($baris['tipe'] === 'A' && $baris['rincian'] !== [])
            <button type="button" x-on:click="buka = !buka" :aria-expanded="buka"
                class="inline-flex items-center gap-1 text-left rounded hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">
                <x-ikon nama="rinci" ukuran="w-3 h-3" class="transition-transform print:hidden motion-reduce:transition-none" x-bind:class="buka && 'rotate-90'" />
                {{ $baris['label'] }}
            </button>
            <div x-show="buka" x-cloak class="mt-2 space-y-1">
                @foreach ($baris['rincian'] as $akunId => $rincian)
                    <div class="text-sm">
                        <button type="button" wire:click="$dispatch('buku-besar.buka-mutasi', { akunId: '{{ $akunId }}', dari: '{{ substr($periode, 0, 4) }}01', sampai: '{{ $periode }}' })"
                            class="text-left rounded text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green dark:text-brand-lime"><span class="font-mono">{{ $akunId }}</span> {{ $rincian['nama'] }}</button>
                    </div>
                @endforeach
            </div>
        @else
            {{ $baris['label'] }}
        @endif
    </td>
    @foreach ($kolomList as $kunciKolom => $kolom)
        @php
            $minus = $baris['tipe'] === 'A' && ($baris['nilai'][$kunciKolom] ?? 0) < 0;
            $tidakSeimbang = $barisTotal && !empty($merahKolom[$kunciKolom]);
            // satu set warna saja per sel (tanpa !important): merah menggantikan warna tebal biasa
            $kelasWarna = match (true) {
                $minus || $tidakSeimbang => 'bg-danger-soft text-fg-danger-strong ' . ($baris['tebal'] === '1' ? 'font-bold' : 'font-semibold'),
                $baris['tebal'] === '1' => 'font-bold text-ink dark:text-gray-100',
                default => '',
            };
        @endphp
        <td class="text-right align-top tabular-nums whitespace-nowrap {{ $kelasWarna }}">
            @if ($minus)
                <x-ikon-peringatan label="Saldo berlawanan dengan saldo normal" />
            @elseif ($tidakSeimbang)
                <x-ikon-peringatan label="Aset tidak sama dengan Liabilitas + Ekuitas" />
            @endif{{ $teks($baris['nilai'][$kunciKolom] ?? null) }}
            @if ($baris['tipe'] === 'A' && $baris['rincian'] !== [])
                <div x-show="buka" x-cloak class="mt-2 space-y-1 font-normal">
                    @foreach ($baris['rincian'] as $rincian)
                        <div class="text-sm text-muted dark:text-gray-400">{{ $teks($rincian[$kunciKolom] ?? 0) }}</div>
                    @endforeach
                </div>
            @endif
        </td>
    @endforeach
</tr>
