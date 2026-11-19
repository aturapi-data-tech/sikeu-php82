<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use App\Support\Format;
use App\Support\SnapshotJurnal;

/**
 * SNAPSHOT JURNAL — ambil jurnal SIRUS satu bulan sebagai versi baru (cutoff). Versi lama tidak pernah ditimpa.
 * Pengambilan berjalan per cabang jurnal (±200 panggilan API) digerakkan wire:poll: tiap poll memproses cabang
 * sampai ±5 detik, jadi tidak ada request panjang yang membekukan layar. Tab ditutup → versi tetap berstatus
 * Proses dan dilanjutkan saat halaman dibuka lagi (atau `php artisan sikeu:ambil-jurnal`).
 * Logika di App\Support\SnapshotJurnal.
 */
new class extends Component {
    public string $tahun = '';

    public string $bulan = '';

    /** Snapshot yang sedang diambil (wire:poll aktif selama tidak null). */
    public ?int $snapIdProses = null;

    public function mount(): void
    {
        $this->tahun = now()->format('Y');
        $this->bulan = now()->format('m');
        $this->cariYangSedangProses();
    }

    public function updatedTahun(): void
    {
        $this->cariYangSedangProses();
    }

    public function updatedBulan(): void
    {
        $this->cariYangSedangProses();
    }

    private function periode(): string
    {
        return $this->tahun . $this->bulan;
    }

    private function cariYangSedangProses(): void
    {
        $this->snapIdProses = null;
        if (!SnapshotJurnal::periodeValid($this->periode()) || !auth()->user()?->can('snapshot.ambil')) {
            return;
        }
        $snapId = DB::table('sikeutxn_snap_hdrs')->where('periode', $this->periode())
            ->where('status', SnapshotJurnal::STATUS_PROSES)->value('snap_id');
        $this->snapIdProses = $snapId ? (int) $snapId : null;
    }

    public function ambil(): void
    {
        abort_unless(auth()->user()?->can('snapshot.ambil'), 403);
        if (!SnapshotJurnal::periodeValid($this->periode())) {
            $this->dispatch('toast', type: 'error', message: 'Pilih tahun & bulan yang sah.');
            return;
        }

        try {
            $this->snapIdProses = (new SnapshotJurnal())->mulai($this->periode(), auth()->id());
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: 'Gagal memulai: ' . $exception->getMessage());
            return;
        }
        unset($this->versiList);
        $this->dispatch('toast', type: 'info', message: 'Pengambilan dimulai — jangan tutup halaman ini sampai selesai.');
    }

    public function lanjutkanProses(): void
    {
        if ($this->snapIdProses === null || !auth()->user()?->can('snapshot.ambil')) {
            return;
        }
        $kemajuan = (new SnapshotJurnal())->prosesBerikut($this->snapIdProses, 5);
        unset($this->versiList);

        if ($kemajuan['status'] === SnapshotJurnal::STATUS_SELESAI) {
            $this->snapIdProses = null;
            $this->dispatch('toast', type: 'success', message: 'Snapshot selesai.');
        } elseif ($kemajuan['status'] === SnapshotJurnal::STATUS_GAGAL) {
            $this->snapIdProses = null;
            $this->dispatch('toast', type: 'error', message: 'Snapshot gagal: ' . ($kemajuan['pesan'] ?? 'galat tak dikenal'));
        }
    }

    #[Computed]
    public function versiList()
    {
        if (!SnapshotJurnal::periodeValid($this->periode())) {
            return collect();
        }

        return DB::table('sikeutxn_snap_hdrs as sh')
            ->leftJoin('users as us', 'us.id', '=', 'sh.diambil_oleh')
            ->where('sh.periode', $this->periode())
            ->orderByDesc('sh.versi')
            ->get(['sh.snap_id', 'sh.versi', 'sh.status', 'sh.jumlah_cabang', 'sh.cabang_selesai', 'sh.jumlah_baris',
                'sh.total_debit', 'sh.total_kredit', 'sh.checksum', 'sh.keterangan', 'sh.mulai_at', 'sh.selesai_at', 'us.name as diambil_oleh_nama']);
    }

    /** Versi selesai sebelumnya (untuk tombol "Banding dgn versi sebelumnya" & penanda checksum sama). */
    #[Computed]
    public function versiSelesaiList()
    {
        return $this->versiList->where('status', SnapshotJurnal::STATUS_SELESAI)->values();
    }
};
?>

<div>
    <x-page-title title="Snapshot Jurnal"
        subtitle="Ambil jurnal SIRUS satu bulan sebagai versi baru (cutoff) — versi lama tetap tersimpan untuk dibandingkan" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="px-6 pt-4 pb-6 space-y-4">

            {{-- TOOLBAR --}}
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <x-input-label for="pilihTahun" value="Tahun" />
                    <div class="w-28 mt-1">
                        <x-select-input id="pilihTahun" wire:model.live="tahun" :disabled="$snapIdProses !== null">
                            @foreach (range((int) now()->format('Y'), (int) now()->format('Y') - 5) as $pilihanTahun)
                                <option value="{{ $pilihanTahun }}">{{ $pilihanTahun }}</option>
                            @endforeach
                        </x-select-input>
                    </div>
                </div>
                <div>
                    <x-input-label for="pilihBulan" value="Bulan" />
                    <div class="w-40 mt-1">
                        <x-select-input id="pilihBulan" wire:model.live="bulan" :disabled="$snapIdProses !== null">
                            @foreach (['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'] as $kodeBulan => $namaBulan)
                                <option value="{{ $kodeBulan }}">{{ $namaBulan }}</option>
                            @endforeach
                        </x-select-input>
                    </div>
                </div>

                @can('snapshot.ambil')
                    <x-primary-button type="button" wire:click="ambil" wire:loading.attr="disabled" wire:target="ambil"
                        :disabled="$snapIdProses !== null">
                        <span wire:loading.remove wire:target="ambil">Ambil dari SIRUS</span>
                        <span wire:loading wire:target="ambil">Memulai…</span>
                    </x-primary-button>
                @endcan

                @if ($this->versiSelesaiList->count() >= 2)
                    <a href="{{ route('cutoff.banding-versi', ['periode' => $tahun . $bulan, 'lama' => $this->versiSelesaiList[1]->snap_id, 'baru' => $this->versiSelesaiList[0]->snap_id]) }}"
                        wire:navigate
                        class="inline-flex items-center px-4 py-2 text-sm font-semibold border rounded-lg text-brand-green border-brand-green/40 hover:bg-surface-strong dark:text-brand-lime dark:border-brand-lime/40">
                        Bandingkan 2 versi terakhir
                    </a>
                @endif
            </div>

            {{-- KEMAJUAN --}}
            @if ($snapIdProses !== null)
                @php $versiProses = $this->versiList->firstWhere('snap_id', $snapIdProses); @endphp
                <div wire:poll.2s="lanjutkanProses"
                    class="p-4 border rounded-xl border-blue-200 bg-blue-50 dark:border-blue-800/40 dark:bg-blue-900/20" role="status" aria-live="polite">
                    @php
                        $selesai = (int) ($versiProses->cabang_selesai ?? 0);
                        $jumlah = max(1, (int) ($versiProses->jumlah_cabang ?? 1));
                        $persen = (int) floor($selesai / $jumlah * 100);
                    @endphp
                    <p class="text-sm font-semibold text-blue-900 dark:text-blue-100">
                        Mengambil versi {{ $versiProses->versi ?? '…' }} — {{ $selesai }} dari {{ $jumlah }} cabang jurnal ({{ $persen }}%)
                    </p>
                    <div class="w-full h-2 mt-2 overflow-hidden bg-white rounded-full dark:bg-gray-800">
                        <div class="h-2 transition-all bg-blue-600 rounded-full" style="width: {{ $persen }}%"></div>
                    </div>
                    <p class="mt-2 text-xs text-blue-800 dark:text-blue-200">Biarkan halaman terbuka. Bila tertutup, buka lagi halaman ini untuk melanjutkan.</p>
                </div>
            @endif

            {{-- DAFTAR VERSI --}}
            <div class="overflow-x-auto border rounded-xl border-hairline bg-surface-card dark:bg-gray-800 dark:border-gray-700">
                <table class="ds-table">
                    <thead>
                        <tr>
                            <th>Versi</th>
                            <th>Status</th>
                            <th>Diambil</th>
                            <th class="text-right">Baris</th>
                            <th class="text-right">Total Debit</th>
                            <th class="text-right">Total Kredit</th>
                            <th>Checksum</th>
                            <th class="ds-c">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->versiList as $versi)
                            @php
                                $versiSebelum = $this->versiSelesaiList->first(fn ($kandidat) => $kandidat->versi < $versi->versi);
                                $samaDenganSebelum = $versiSebelum && $versi->status === 'S' && $versi->checksum === $versiSebelum->checksum;
                            @endphp
                            <tr wire:key="versi-{{ $versi->snap_id }}">
                                <td class="ds-td-strong">v{{ $versi->versi }}</td>
                                <td>
                                    <x-badge :variant="match ($versi->status) { 'S' => 'success', 'G' => 'danger', default => 'info' }">
                                        {{ SnapshotJurnal::LABEL_STATUS[$versi->status] ?? $versi->status }}
                                    </x-badge>
                                    @if ($versi->status === 'G' && $versi->keterangan)
                                        <p class="mt-1 text-xs text-fg-danger-strong max-w-xs break-words">{{ $versi->keterangan }}</p>
                                    @elseif ($versi->status === 'P')
                                        <p class="mt-1 text-xs text-muted">{{ $versi->cabang_selesai }}/{{ $versi->jumlah_cabang }} cabang</p>
                                    @endif
                                </td>
                                <td>
                                    <div class="ds-td-meta">{{ Format::waktu($versi->selesai_at ?? $versi->mulai_at) }}</div>
                                    <div class="text-xs text-muted">{{ $versi->diambil_oleh_nama ?? '—' }}</div>
                                </td>
                                <td class="text-right ds-td-token">{{ number_format((int) $versi->jumlah_baris, 0, ',', '.') }}</td>
                                <td class="text-right ds-td-token">{{ number_format((float) $versi->total_debit, 2, ',', '.') }}</td>
                                <td class="text-right ds-td-token">{{ number_format((float) $versi->total_kredit, 2, ',', '.') }}</td>
                                <td>
                                    <span class="ds-td-meta">{{ $versi->checksum ? substr($versi->checksum, 0, 10) : '—' }}</span>
                                    @if ($samaDenganSebelum)
                                        <p class="text-xs text-fg-success-strong">sama dengan v{{ $versiSebelum->versi }}</p>
                                    @endif
                                </td>
                                <td class="ds-c">
                                    @if ($versiSebelum && $versi->status === 'S')
                                        <a href="{{ route('cutoff.banding-versi', ['periode' => $tahun . $bulan, 'lama' => $versiSebelum->snap_id, 'baru' => $versi->snap_id]) }}"
                                            wire:navigate class="text-sm font-semibold underline text-brand-green dark:text-brand-lime">
                                            Banding v{{ $versiSebelum->versi }}
                                        </a>
                                    @else
                                        <span class="text-xs text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-8 text-center text-muted">Belum ada snapshot untuk {{ $bulan }}/{{ $tahun }}.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
