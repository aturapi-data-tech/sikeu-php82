<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use App\Support\Format;
use App\Support\LaporanKeuangan;
use App\Support\SnapshotJurnal;

/**
 * LAPORAN LABA RUGI — susunan dari template (sikeumst_lap_*, DDL 05), angka dari jurnal terposting.
 * Kolom bulan berjalan & Januari s/d bulan berjalan. Baris jumlah akun bisa dibuka sampai akun → Buku Besar.
 * Pemeriksaan tampil terbuka: periode yang belum diposting, akun Laba Rugi yatim/dobel, laba bersih ≠ Σ akun.
 * Logika di App\Support\LaporanKeuangan::labaRugi (lihat skill keuangan-rs §2).
 */
new class extends Component {
    #[Url(as: 'periode', except: '')]
    public string $periode = '';

    public function mount(): void
    {
        if (!SnapshotJurnal::periodeValid($this->periode)) {
            $this->periode = (string) (DB::table('sikeumst_periodes')->whereNotNull('snap_id_sah')->max('periode')
                ?? DB::table('sikeumst_periodes')->max('periode') ?? now()->format('Ym'));
        }
    }

    #[Computed]
    public function periodeList(): array
    {
        return DB::table('sikeumst_periodes')->orderByDesc('periode')->get(['periode', 'status', 'snap_id_sah'])
            ->keyBy('periode')->all();
    }

    #[Computed]
    public function laporan(): array
    {
        return LaporanKeuangan::labaRugi($this->periode);
    }

    /** Periode Januari s/d periode terpilih yang belum diposting / belum dibuka — kolom kumulatif jadi tidak lengkap. */
    #[Computed]
    public function periodeBelumPosting(): array
    {
        $belumList = [];
        $tahun = substr($this->periode, 0, 4);
        $sampai = (int) substr($this->periode, 4, 2);
        for ($bulan = 1; $bulan <= $sampai; $bulan++) {
            $kode = sprintf('%s%02d', $tahun, $bulan);
            if (!isset($this->periodeList[$kode]) || !$this->periodeList[$kode]->snap_id_sah) {
                $belumList[] = SnapshotJurnal::labelPeriode($kode);
            }
        }

        return $belumList;
    }

    /** Nilai bertanda → teks; negatif dalam kurung (gaya laporan keuangan). */
    public function teksNilai(?float $nilai): string
    {
        if ($nilai === null) {
            return '';
        }

        return $nilai < 0 ? '(' . Format::uang(-$nilai) . ')' : Format::uang($nilai);
    }
};
?>

<div>

    <x-page-title
        title="Laba Rugi"
        subtitle="Laporan laba rugi dari jurnal terposting — susunan baris diatur di template laporan, bukan di program" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="px-6 pt-2 pb-6 space-y-4">

            {{-- TOOLBAR --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between print:hidden">
                <div class="flex items-end gap-3">
                    <div class="w-44">
                        <x-input-label for="periode" value="Periode" />
                        <x-select-input id="periode" wire:model.live="periode" class="mt-1">
                            @forelse ($this->periodeList as $kodePeriode => $infoPeriode)
                                <option value="{{ $kodePeriode }}">{{ SnapshotJurnal::labelPeriode((string) $kodePeriode) }}{{ $infoPeriode->status === 'K' ? ' (kunci)' : '' }}</option>
                            @empty
                                <option value="{{ $periode }}">{{ SnapshotJurnal::labelPeriode($periode) }}</option>
                            @endforelse
                        </x-select-input>
                    </div>
                    @php $statusPeriode = $this->periodeList[$periode]->status ?? null; @endphp
                    @if ($statusPeriode === 'K' || $statusPeriode === 'T')
                        <x-badge variant="success" class="mb-2">Final — periode dikunci</x-badge>
                    @else
                        <x-badge variant="warning" class="mb-2">Sementara — periode belum dikunci</x-badge>
                    @endif
                </div>
                <x-secondary-button type="button" x-on:click="window.print()">Cetak</x-secondary-button>
            </div>

            {{-- PEMERIKSAAN --}}
            @php $pemeriksaan = $this->laporan['pemeriksaan']; @endphp
            <div class="space-y-2 print:hidden">
                @foreach ($pemeriksaan['galat'] as $galat)
                    <div role="alert" class="px-4 py-2 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">{{ $galat }}</div>
                @endforeach
                @if ($this->periodeBelumPosting !== [])
                    <div role="status" class="px-4 py-2 text-sm rounded-lg bg-warning-soft text-fg-warning">
                        Belum diposting: {{ implode(', ', $this->periodeBelumPosting) }} — kolom Januari s/d {{ SnapshotJurnal::labelPeriode($periode) }} belum lengkap.
                        <a href="{{ route('buku-besar.periode', ['tahun' => substr($periode, 0, 4)]) }}" wire:navigate class="font-medium underline">Periode & Pengesahan</a>
                    </div>
                @endif
                @if (($pemeriksaan['yatim'] ?? []) !== [])
                    <div role="alert" class="px-4 py-2 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">
                        {{ count($pemeriksaan['yatim']) }} akun Laba Rugi tidak masuk baris mana pun:
                        {{ collect($pemeriksaan['yatim'])->map(fn ($akun) => $akun['akun_id'] . ($akun['bernilai'] ? ' (ada nilai!)' : ''))->implode(', ') }}.
                        Tambahkan ke template laporan supaya angkanya tidak hilang.
                    </div>
                @endif
                @if (($pemeriksaan['dobel'] ?? []) !== [])
                    <div role="alert" class="px-4 py-2 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">
                        Akun terhitung di lebih dari satu baris: {{ implode(', ', $pemeriksaan['dobel']) }} — angkanya ganda.
                    </div>
                @endif
                @foreach ($this->laporan['kolom'] as $kunciKolom => $kolom)
                    @if (isset($pemeriksaan['laba_bersih_laporan'][$kunciKolom]) && round($pemeriksaan['laba_bersih_laporan'][$kunciKolom] - $pemeriksaan['laba_bersih_akun'][$kunciKolom], 2) != 0.0)
                        <div role="alert" class="px-4 py-2 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">
                            {{ $kolom['judul'] }}: laba bersih laporan {{ $this->teksNilai($pemeriksaan['laba_bersih_laporan'][$kunciKolom]) }}
                            ≠ jumlah semua akun Laba Rugi {{ $this->teksNilai($pemeriksaan['laba_bersih_akun'][$kunciKolom]) }} — periksa rumus template.
                        </div>
                    @endif
                @endforeach
            </div>

            {{-- LAPORAN --}}
            <div class="overflow-x-auto bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900 print:border-0 print:shadow-none">
                <div class="px-6 pt-5 pb-2 text-center">
                    <p class="text-sm font-semibold uppercase text-ink dark:text-gray-100">{{ \App\Support\Identitas::get()->nama ?: config('app.name') }}</p>
                    <p class="text-base font-bold text-ink dark:text-gray-100">LAPORAN LABA RUGI</p>
                    <p class="text-sm text-muted dark:text-gray-400">Periode {{ SnapshotJurnal::labelPeriode($periode) }}</p>
                </div>
                <table class="ds-table ds-table-entri">
                    <thead>
                        <tr>
                            <th>Keterangan</th>
                            @foreach ($this->laporan['kolom'] as $kolom)
                                <th class="text-right w-48">{{ $kolom['judul'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->laporan['baris'] as $baris)
                            <tr wire:key="lr-{{ $baris['kode'] }}" x-data="{ buka: false }"
                                class="{{ $baris['tipe'] === 'R' && $baris['tebal'] === '1' ? 'bg-surface-card dark:bg-gray-800' : '' }}">
                                <td class="{{ $baris['induk_baris_id'] ? 'pl-8' : '' }} {{ $baris['tebal'] === '1' ? 'font-bold text-ink dark:text-gray-100' : 'text-body dark:text-gray-300' }}">
                                    @if ($baris['tipe'] === 'A' && $baris['rincian'] !== [])
                                        <button type="button" x-on:click="buka = !buka" :aria-expanded="buka"
                                            class="inline-flex items-center gap-1 text-left hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green rounded">
                                            <svg class="w-3 h-3 transition-transform print:hidden" :class="buka && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                                            {{ $baris['label'] }}
                                        </button>
                                        <div x-show="buka" x-cloak class="mt-2 space-y-1">
                                            @foreach ($baris['rincian'] as $akunId => $rincian)
                                                <div class="flex justify-between gap-4 text-xs">
                                                    <button type="button" wire:click="$dispatch('buku-besar.buka-mutasi', { akunId: '{{ $akunId }}', dari: '{{ $periode }}', sampai: '{{ $periode }}' })"
                                                        class="text-left text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green rounded dark:text-brand-lime"><span class="font-mono">{{ $akunId }}</span> {{ $rincian['nama'] }}</button>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        {{ $baris['label'] }}
                                    @endif
                                </td>
                                @foreach ($this->laporan['kolom'] as $kunciKolom => $kolom)
                                    <td class="text-right align-top tabular-nums whitespace-nowrap {{ $baris['tebal'] === '1' ? 'font-bold text-ink dark:text-gray-100' : '' }}">
                                        {{ $this->teksNilai($baris['nilai'][$kunciKolom] ?? null) }}
                                        @if ($baris['tipe'] === 'A' && $baris['rincian'] !== [])
                                            <div x-show="buka" x-cloak class="mt-2 space-y-1 font-normal">
                                                @foreach ($baris['rincian'] as $rincian)
                                                    <div class="text-xs text-muted dark:text-gray-400">{{ $this->teksNilai($rincian[$kunciKolom] ?? 0) }}</div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-6 py-10 text-center text-muted">Template Laba Rugi kosong.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <p class="px-6 py-3 text-xs text-muted dark:text-gray-400">
                    Angka dalam kurung = negatif. Klik nama pos untuk melihat akun penyusunnya; klik akun untuk melihat mutasinya.
                    Pendapatan rawat inap SIRUS diakui saat pasien pulang dan selisih tarif BPJS belum dijurnal — lihat panduan keuangan (skill keuangan-rs §4).
                </p>
            </div>
        </div>
    </div>
    <livewire:pages::buku-besar.mutasi-akun.mutasi-akun-modal wire:key="mutasi-akun-modal" />
</div>

