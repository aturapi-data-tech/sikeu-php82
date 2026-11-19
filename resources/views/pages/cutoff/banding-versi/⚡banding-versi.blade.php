<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Support\Format;
use App\Support\SnapshotJurnal;

/**
 * BANDINGKAN VERSI — dua snapshot jurnal satu periode dibandingkan sampai tingkat transaksi:
 * baru (ada sekarang, dulu tidak), hilang (dulu ada, sekarang tidak), berubah (nominal / tanggal / akun / shift).
 * Snapshot berstatus Selesai tidak pernah berubah → hasil banding di-cache file per pasangan versi.
 * Logika di App\Support\SnapshotJurnal::bandingkan().
 */
new class extends Component {
    #[Url]
    public string $periode = '';

    #[Url]
    public ?int $lama = null;

    #[Url]
    public ?int $baru = null;

    /** semua | baru | hilang | berubah */
    public string $filterJenis = 'semua';

    public function mount(): void
    {
        if (!SnapshotJurnal::periodeValid($this->periode)) {
            $this->periode = (string) (DB::table('sikeutxn_snap_hdrs')->where('status', SnapshotJurnal::STATUS_SELESAI)->max('periode') ?? now()->format('Ym'));
        }
        $this->pilihVersiBawaan();
    }

    public function updatedPeriode(): void
    {
        $this->lama = null;
        $this->baru = null;
        unset($this->versiList); // #[Computed] masih memegang daftar versi periode lama di request ini
        $this->pilihVersiBawaan();
    }

    /** Bawaan: dua versi selesai terakhir (lama = sebelum terakhir, baru = terakhir). */
    private function pilihVersiBawaan(): void
    {
        $idList = $this->versiList->pluck('snap_id')->all();
        if (!in_array($this->baru, $idList, true)) {
            $this->baru = $idList[0] ?? null;
        }
        if (!in_array($this->lama, $idList, true) || $this->lama === $this->baru) {
            $this->lama = collect($idList)->first(fn ($snapId) => $snapId !== $this->baru);
        }
    }

    #[Computed]
    public function periodeList(): array
    {
        return DB::table('sikeutxn_snap_hdrs')->where('status', SnapshotJurnal::STATUS_SELESAI)
            ->distinct()->orderByDesc('periode')->pluck('periode')->all();
    }

    #[Computed]
    public function versiList()
    {
        return DB::table('sikeutxn_snap_hdrs')->where('periode', $this->periode)->where('status', SnapshotJurnal::STATUS_SELESAI)
            ->orderByDesc('versi')->get(['snap_id', 'versi', 'selesai_at', 'jumlah_baris', 'total_debit', 'total_kredit', 'checksum'])
            ->map(function ($versi) { $versi->snap_id = (int) $versi->snap_id; return $versi; });
    }

    #[Computed]
    public function hasil(): ?array
    {
        if (!$this->lama || !$this->baru || $this->lama === $this->baru) {
            return null;
        }
        $versiLama = $this->versiList->firstWhere('snap_id', $this->lama);
        $versiBaru = $this->versiList->firstWhere('snap_id', $this->baru);
        if (!$versiLama || !$versiBaru) {
            return null;
        }
        if ($versiLama->checksum === $versiBaru->checksum) {
            return ['cabang' => [], 'baris' => [], 'jumlah_selisih' => 0, 'terpotong' => false];
        }

        return Cache::store('file')->remember("sikeu.banding.{$this->lama}.{$this->baru}", now()->addDay(),
            fn () => (new SnapshotJurnal())->bandingkan($this->lama, $this->baru));
    }

    public function tukar(): void
    {
        [$this->lama, $this->baru] = [$this->baru, $this->lama];
    }
};
?>

<div>
    <x-page-title title="Bandingkan Versi"
        subtitle="Transaksi yang baru, hilang, atau berubah antara dua snapshot jurnal satu periode" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="px-6 pt-4 pb-6 space-y-4">

            {{-- TOOLBAR --}}
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <x-input-label for="pilihPeriode" value="Periode" />
                    <div class="w-32 mt-1">
                        <x-select-input id="pilihPeriode" wire:model.live="periode">
                            @forelse ($this->periodeList as $pilihanPeriode)
                                <option value="{{ $pilihanPeriode }}">{{ SnapshotJurnal::labelPeriode($pilihanPeriode) }}</option>
                            @empty
                                <option value="{{ $periode }}">{{ SnapshotJurnal::labelPeriode($periode) }}</option>
                            @endforelse
                        </x-select-input>
                    </div>
                </div>
                <div>
                    <x-input-label for="pilihLama" value="Versi lama" />
                    <div class="w-64 mt-1">
                        <x-select-input id="pilihLama" wire:model.live="lama">
                            @foreach ($this->versiList as $versi)
                                <option value="{{ $versi->snap_id }}">v{{ $versi->versi }} — {{ Format::waktu($versi->selesai_at) }}</option>
                            @endforeach
                        </x-select-input>
                    </div>
                </div>
                <x-secondary-button type="button" wire:click="tukar" title="Tukar versi lama & baru" aria-label="Tukar versi lama dan baru">⇄</x-secondary-button>
                <div>
                    <x-input-label for="pilihBaru" value="Versi baru" />
                    <div class="w-64 mt-1">
                        <x-select-input id="pilihBaru" wire:model.live="baru">
                            @foreach ($this->versiList as $versi)
                                <option value="{{ $versi->snap_id }}">v{{ $versi->versi }} — {{ Format::waktu($versi->selesai_at) }}</option>
                            @endforeach
                        </x-select-input>
                    </div>
                </div>
            </div>

            <div wire:loading.flex wire:target="periode, lama, baru, tukar" class="items-center gap-2 text-sm text-muted">
                Membandingkan…
            </div>

            @if ($this->versiList->count() < 2)
                <div class="p-4 text-sm border rounded-xl border-hairline bg-surface-card text-body dark:bg-gray-800 dark:border-gray-700 dark:text-gray-300">
                    Periode ini baru punya {{ $this->versiList->count() }} versi selesai. Ambil satu versi lagi di
                    <a href="{{ route('cutoff.snapshot-jurnal') }}" wire:navigate class="font-semibold underline">Snapshot Jurnal</a> untuk bisa membandingkan.
                </div>
            @elseif ($this->hasil === null)
                <div class="p-4 text-sm border rounded-xl border-hairline bg-surface-card text-body dark:bg-gray-800 dark:border-gray-700">
                    Pilih dua versi yang berbeda.
                </div>
            @else
                @php
                    $hasil = $this->hasil;
                    $versiLama = $this->versiList->firstWhere('snap_id', $lama);
                    $versiBaru = $this->versiList->firstWhere('snap_id', $baru);
                    $jumlahPerJenis = collect($hasil['baris'])->countBy('jenis');
                    $barisTampil = $filterJenis === 'semua' ? $hasil['baris'] : array_values(array_filter($hasil['baris'], fn ($baris) => $baris['jenis'] === $filterJenis));
                    $rupiah = fn ($nilai) => $nilai === null ? '—' : number_format((float) $nilai, 2, ',', '.');
                @endphp

                {{-- RINGKASAN TOTAL --}}
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach ([['Baris jurnal', 'jumlah_baris', 0], ['Total debit', 'total_debit', 2], ['Total kredit', 'total_kredit', 2]] as [$judul, $kolom, $desimal])
                        @php $selisih = (float) $versiBaru->{$kolom} - (float) $versiLama->{$kolom}; @endphp
                        <div class="p-4 border rounded-xl border-hairline bg-surface-card dark:bg-gray-800 dark:border-gray-700">
                            <p class="text-xs font-semibold uppercase text-muted">{{ $judul }}</p>
                            <p class="mt-1 text-sm ds-td-token">v{{ $versiLama->versi }}: {{ number_format((float) $versiLama->{$kolom}, $desimal, ',', '.') }}</p>
                            <p class="text-sm ds-td-token">v{{ $versiBaru->versi }}: {{ number_format((float) $versiBaru->{$kolom}, $desimal, ',', '.') }}</p>
                            <p @class(['mt-1 text-sm font-semibold', 'text-fg-success-strong' => $selisih == 0, 'text-fg-danger-strong' => $selisih != 0])>
                                Selisih {{ $selisih > 0 ? '+' : '' }}{{ number_format($selisih, $desimal, ',', '.') }}
                            </p>
                        </div>
                    @endforeach
                </div>

                @if ($hasil['jumlah_selisih'] === 0)
                    <div class="p-4 text-sm font-semibold border rounded-xl border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-900/20 dark:text-emerald-200">
                        Tidak ada perbedaan — kedua versi identik sampai tingkat transaksi.
                    </div>
                @else
                    {{-- CABANG BERBEDA --}}
                    <details class="border rounded-xl border-hairline bg-surface-card dark:bg-gray-800 dark:border-gray-700">
                        <summary class="px-4 py-3 text-sm font-semibold cursor-pointer text-ink dark:text-white">
                            {{ count($hasil['cabang']) }} cabang jurnal berbeda
                        </summary>
                        <div class="overflow-x-auto">
                            <table class="ds-table">
                                <thead>
                                    <tr><th>No</th><th>Cabang</th><th class="text-right">Baris lama → baru</th><th class="text-right">Debit lama → baru</th><th class="text-right">Kredit lama → baru</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($hasil['cabang'] as $cabang)
                                        <tr wire:key="cabang-{{ $cabang->cabang_kunci }}">
                                            <td class="ds-td-meta">{{ $cabang->cabang_no }}</td>
                                            <td class="max-w-md text-xs break-all ds-td-meta">{{ \Illuminate\Support\Str::limit($cabang->label_cabang, 120) }}</td>
                                            <td class="text-right ds-td-token">{{ $cabang->baris_lama ?? '—' }} → {{ $cabang->baris_baru ?? '—' }}</td>
                                            <td class="text-right ds-td-token">{{ $rupiah($cabang->debit_lama) }} → {{ $rupiah($cabang->debit_baru) }}</td>
                                            <td class="text-right ds-td-token">{{ $rupiah($cabang->kredit_lama) }} → {{ $rupiah($cabang->kredit_baru) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>

                    {{-- FILTER JENIS --}}
                    <div class="flex flex-wrap gap-1.5" role="group" aria-label="Saring jenis perbedaan">
                        @foreach (['semua' => 'Semua', 'baru' => 'Baru', 'hilang' => 'Hilang', 'berubah' => 'Berubah'] as $jenis => $labelJenis)
                            <button type="button" wire:click="$set('filterJenis', '{{ $jenis }}')" aria-pressed="{{ $filterJenis === $jenis ? 'true' : 'false' }}"
                                @class([
                                    'inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold border transition',
                                    'bg-brand-green text-white border-brand-green dark:bg-brand-lime dark:text-ink dark:border-brand-lime' => $filterJenis === $jenis,
                                    'bg-surface-card text-body border-hairline hover:bg-surface-strong dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600' => $filterJenis !== $jenis,
                                ])>
                                {{ $labelJenis }} ({{ $jenis === 'semua' ? count($hasil['baris']) : ($jumlahPerJenis[$jenis] ?? 0) }})
                            </button>
                        @endforeach
                    </div>

                    @if ($hasil['terpotong'])
                        <p class="text-sm text-fg-warning">Perbedaan lebih dari {{ number_format(SnapshotJurnal::BATAS_BARIS_SELISIH, 0, ',', '.') }} baris — yang tampil hanya sebagian pertama.</p>
                    @endif

                    {{-- BARIS SELISIH --}}
                    <div class="overflow-x-auto border rounded-xl border-hairline bg-surface-card dark:bg-gray-800 dark:border-gray-700">
                        <table class="ds-table">
                            <thead>
                                <tr>
                                    <th>Jenis</th>
                                    <th>Tanggal</th>
                                    <th>Transaksi</th>
                                    <th>Akun / Lawan</th>
                                    <th class="text-right">Debit</th>
                                    <th class="text-right">Kredit</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($barisTampil as $nomorBaris => $baris)
                                    <tr wire:key="selisih-{{ $filterJenis }}-{{ $nomorBaris }}">
                                        <td>
                                            <x-badge :variant="match ($baris['jenis']) { 'baru' => 'success', 'hilang' => 'danger', default => 'warning' }">{{ ucfirst($baris['jenis']) }}</x-badge>
                                            @if ($baris['jenis'] === 'berubah')
                                                <p class="mt-1 text-xs text-muted">{{ implode(', ', SnapshotJurnal::perubahan($baris)) }}</p>
                                            @endif
                                        </td>
                                        <td class="ds-td-meta">
                                            @if ($baris['jenis'] === 'berubah' && $baris['tanggal_lama'] !== $baris['tanggal_baru'])
                                                <span class="line-through">{{ Format::waktu($baris['tanggal_lama']) }}</span><br>{{ Format::waktu($baris['tanggal_baru']) }}
                                            @else
                                                {{ Format::waktu($baris['tanggal_baru'] ?? $baris['tanggal_lama']) }}
                                            @endif
                                        </td>
                                        <td class="max-w-md text-sm break-words text-ink dark:text-white">
                                            {{ $baris['txn_name'] }}
                                            @if ((int) ($baris['jumlah_lama'] ?? 1) > 1 || (int) ($baris['jumlah_baru'] ?? 1) > 1)
                                                <span class="block text-xs text-muted">{{ $baris['jumlah_lama'] ?? 0 }} → {{ $baris['jumlah_baru'] ?? 0 }} baris berlabel sama</span>
                                            @endif
                                        </td>
                                        <td class="ds-td-token">
                                            @if ($baris['jenis'] === 'berubah' && ($baris['acc_lama'] !== $baris['acc_baru'] || $baris['lawan_lama'] !== $baris['lawan_baru']))
                                                <span class="line-through">{{ $baris['acc_lama'] }} / {{ $baris['lawan_lama'] }}</span><br>
                                            @endif
                                            {{ $baris['acc_baru'] ?? $baris['acc_lama'] }} / {{ $baris['lawan_baru'] ?? $baris['lawan_lama'] }}
                                        </td>
                                        @foreach (['debit', 'kredit'] as $sisi)
                                            <td class="text-right ds-td-token">
                                                @if ($baris['jenis'] === 'berubah' && (float) $baris[$sisi . '_lama'] !== (float) $baris[$sisi . '_baru'])
                                                    <span class="line-through text-muted">{{ $rupiah($baris[$sisi . '_lama']) }}</span><br>{{ $rupiah($baris[$sisi . '_baru']) }}
                                                @elseif ($baris['jenis'] === 'hilang')
                                                    <span class="line-through">{{ $rupiah($baris[$sisi . '_lama']) }}</span>
                                                @else
                                                    {{ $rupiah($baris[$sisi . '_baru'] ?? $baris[$sisi . '_lama']) }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
