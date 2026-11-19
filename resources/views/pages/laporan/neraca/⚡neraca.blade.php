<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use App\Support\Format;
use App\Support\LaporanKeuangan;
use App\Support\SaldoAwal;
use App\Support\SnapshotJurnal;

/**
 * NERACA (Laporan Posisi Keuangan) — susunan dari template (sikeumst_lap_*, DDL 07), saldo dari jurnal terposting
 * termasuk saldo awal sah. Kolom: akhir periode terpilih & 1 Januari. Laba tahun berjalan = akun Laba Rugi sejak
 * Januari (sama dengan Laba Rugi YTD). Pemeriksaan: Aset = Liabilitas + Ekuitas, akun yatim/dobel, periode belum
 * diposting, saldo awal belum sah. Logika di App\Support\LaporanKeuangan::neraca (skill keuangan-rs §2).
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
        return DB::table('sikeumst_periodes')->orderByDesc('periode')->get(['periode', 'status', 'snap_id_sah'])->keyBy('periode')->all();
    }

    #[Computed]
    public function laporan(): array
    {
        return LaporanKeuangan::neraca($this->periode);
    }

    #[Computed]
    public function periodeBelumPosting(): array
    {
        $belumList = [];
        $tahun = substr($this->periode, 0, 4);
        for ($bulan = 1; $bulan <= (int) substr($this->periode, 4, 2); $bulan++) {
            $kode = sprintf('%s%02d', $tahun, $bulan);
            if (!isset($this->periodeList[$kode]) || !$this->periodeList[$kode]->snap_id_sah) {
                $belumList[] = SnapshotJurnal::labelPeriode($kode);
            }
        }

        return $belumList;
    }

    #[Computed]
    public function saldoAwalSah(): bool
    {
        return (bool) (new SaldoAwal())->jurnal((int) substr($this->periode, 0, 4))['sah'];
    }

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
        title="Neraca"
        subtitle="Laporan posisi keuangan dari jurnal terposting & saldo awal sah — susunan baris diatur di template laporan" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="px-6 pt-2 pb-6 space-y-4">

            {{-- TOOLBAR --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between print:hidden">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="w-44">
                        <x-input-label for="periode" value="Per akhir periode" />
                        <x-select-input id="periode" wire:model.live="periode" class="mt-1">
                            @forelse ($this->periodeList as $kodePeriode => $infoPeriode)
                                <option value="{{ $kodePeriode }}">{{ SnapshotJurnal::labelPeriode((string) $kodePeriode) }}{{ $infoPeriode->status === 'K' ? ' (kunci)' : '' }}</option>
                            @empty
                                <option value="{{ $periode }}">{{ SnapshotJurnal::labelPeriode($periode) }}</option>
                            @endforelse
                        </x-select-input>
                    </div>
                    @php
                        $statusPeriode = $this->periodeList[$periode]->status ?? null;
                        $pemeriksaan = $this->laporan['pemeriksaan'];
                        $seimbangSemua = collect($pemeriksaan['seimbang'] ?? [])->every(fn ($cek) => ($cek['selisih_laporan'] ?? 0) == 0.0 && $cek['selisih_buku'] == 0.0);
                    @endphp
                    @if ($statusPeriode === 'K' || $statusPeriode === 'T')
                        <x-badge variant="success" class="mb-2">Final — periode dikunci</x-badge>
                    @else
                        <x-badge variant="warning" class="mb-2">Sementara — periode belum dikunci</x-badge>
                    @endif
                    @if ($seimbangSemua)
                        <x-badge variant="success" class="mb-2">Aset = Liabilitas + Ekuitas</x-badge>
                    @else
                        <x-badge variant="danger" class="mb-2">TIDAK seimbang</x-badge>
                    @endif
                </div>
                <x-secondary-button type="button" x-on:click="window.print()">Cetak</x-secondary-button>
            </div>

            {{-- PEMERIKSAAN --}}
            <div class="space-y-2 print:hidden">
                @foreach ($pemeriksaan['galat'] as $galat)
                    <div role="alert" class="px-4 py-2 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">{{ $galat }}</div>
                @endforeach
                @if (!$this->saldoAwalSah)
                    <div role="status" class="px-4 py-2 text-sm rounded-lg bg-warning-soft text-fg-warning">
                        Saldo awal {{ substr($periode, 0, 4) }} belum disahkan — Neraca hanya berisi mutasi, bukan posisi keuangan sebenarnya.
                        <a href="{{ route('buku-besar.saldo-awal', ['tahun' => substr($periode, 0, 4)]) }}" wire:navigate class="font-medium underline">Saldo Awal</a>
                    </div>
                @endif
                @if ($this->periodeBelumPosting !== [])
                    <div role="status" class="px-4 py-2 text-sm rounded-lg bg-warning-soft text-fg-warning">
                        Belum diposting: {{ implode(', ', $this->periodeBelumPosting) }} — saldo akhir {{ SnapshotJurnal::labelPeriode($periode) }} belum lengkap.
                        <a href="{{ route('buku-besar.periode', ['tahun' => substr($periode, 0, 4)]) }}" wire:navigate class="font-medium underline">Periode & Posting</a>
                    </div>
                @endif
                @if (($pemeriksaan['yatim'] ?? []) !== [])
                    <div role="alert" class="px-4 py-2 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">
                        {{ count($pemeriksaan['yatim']) }} akun tidak masuk baris Neraca mana pun:
                        {{ collect($pemeriksaan['yatim'])->map(fn ($akun) => $akun['akun_id'] . ($akun['bernilai'] ? ' (ada saldo!)' : ''))->implode(', ') }}.
                    </div>
                @endif
                @if (($pemeriksaan['dobel'] ?? []) !== [])
                    <div role="alert" class="px-4 py-2 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">Akun terhitung di lebih dari satu baris: {{ implode(', ', $pemeriksaan['dobel']) }}.</div>
                @endif
                @foreach ($pemeriksaan['seimbang'] ?? [] as $kunciKolom => $cek)
                    @if (($cek['selisih_laporan'] ?? 0) != 0.0 || $cek['selisih_buku'] != 0.0)
                        <div role="alert" class="px-4 py-2 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">
                            {{ $this->laporan['kolom'][$kunciKolom]['judul'] }}: Jumlah Aset {{ $this->teksNilai($cek['aset']) }} vs Liabilitas + Ekuitas {{ $this->teksNilai($cek['pasiva']) }}
                            @if ($cek['selisih_buku'] != 0.0) — buku besar sendiri tidak seimbang ({{ $this->teksNilai($cek['selisih_buku']) }}) @else — periksa rumus / tautan template @endif
                        </div>
                    @endif
                @endforeach
                @if (($pemeriksaan['laba_tahun_lalu'] ?? 0) != 0.0)
                    <div role="status" class="px-4 py-2 text-sm rounded-lg bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200">
                        Laba (rugi) tahun lalu yang belum ditutup buku {{ $this->teksNilai($pemeriksaan['laba_tahun_lalu']) }} sementara ditampilkan di Saldo laba.
                    </div>
                @endif
            </div>

            {{-- LAPORAN --}}
            <div class="overflow-x-auto bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900 print:border-0 print:shadow-none">
                <div class="px-6 pt-5 pb-2 text-center">
                    <p class="text-sm font-semibold uppercase text-ink dark:text-gray-100">{{ \App\Support\Identitas::get()->nama ?: config('app.name') }}</p>
                    <p class="text-base font-bold text-ink dark:text-gray-100">LAPORAN POSISI KEUANGAN (NERACA)</p>
                    <p class="text-sm text-muted dark:text-gray-400">Per akhir {{ SnapshotJurnal::labelPeriode($periode) }}</p>
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
                            <tr wire:key="nr-{{ $baris['kode'] }}" x-data="{ buka: false }"
                                class="{{ $baris['tipe'] === 'R' && $baris['tebal'] === '1' ? 'bg-surface-card dark:bg-gray-800' : '' }}">
                                <td class="{{ $baris['induk_baris_id'] ? 'pl-8' : '' }} {{ $baris['tebal'] === '1' ? 'font-bold text-ink dark:text-gray-100' : 'text-body dark:text-gray-300' }}">
                                    @if ($baris['tipe'] === 'A' && $baris['rincian'] !== [])
                                        <button type="button" x-on:click="buka = !buka" :aria-expanded="buka"
                                            class="inline-flex items-center gap-1 text-left rounded hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">
                                            <svg class="w-3 h-3 transition-transform print:hidden" :class="buka && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                                            {{ $baris['label'] }}
                                        </button>
                                        <div x-show="buka" x-cloak class="mt-2 space-y-1">
                                            @foreach ($baris['rincian'] as $akunId => $rincian)
                                                <div class="text-xs">
                                                    <a href="{{ route('buku-besar.buku-besar', ['akun' => $akunId, 'dari' => substr($periode, 0, 4) . '01', 'sampai' => $periode]) }}" wire:navigate
                                                        class="text-brand-green hover:underline dark:text-brand-lime"><span class="font-mono">{{ $akunId }}</span> {{ $rincian['nama'] }}</a>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        {{ $baris['label'] }}
                                    @endif
                                </td>
                                @foreach ($this->laporan['kolom'] as $kunciKolom => $kolom)
                                    <td class="text-right align-top tabular-nums whitespace-nowrap {{ $baris['tebal'] === '1' ? 'font-bold text-ink dark:text-gray-100' : '' }} {{ $baris['tipe'] === 'A' && ($baris['nilai'][$kunciKolom] ?? 0) < 0 ? 'text-fg-danger-strong' : '' }}">
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
                            <tr><td colspan="3" class="px-6 py-10 text-center text-muted">Template Neraca kosong.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <p class="px-6 py-3 text-xs text-muted dark:text-gray-400">
                    Angka dalam kurung = negatif; pos aset bernilai negatif ditandai merah (mis. persediaan bersaldo kredit) — telusuri lewat rincian akun.
                    "Akun sementara" harus nol sebelum laporan dianggap final.
                </p>
            </div>
        </div>
    </div>
</div>
