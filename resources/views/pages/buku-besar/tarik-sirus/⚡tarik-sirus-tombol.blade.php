<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use App\Support\BukuBesar;
use App\Support\SnapshotJurnal;

/**
 * TOMBOL "TARIK DARI SIRUS" untuk satu periode — dipasang di layar yang menampilkan angka (Neraca Saldo, dst.) supaya
 * pemakai bisa menyegarkan data SIRUS bulan itu tanpa pindah ke Pembukuan › Tarik Data SIRUS.
 * Mesin SAMA dengan layar Tarik Data SIRUS (App\Support\SnapshotJurnal + BukuBesar), bukan logika kedua:
 *   1. tarik (snapshot versi baru, digerakkan wire:poll),
 *   2. checksum sama dengan versi yang sedang disahkan → tidak diposting ulang,
 *   3. berbeda → disahkan & diposting otomatis bila pemakai punya hak jurnal.posting & periode Buka,
 *   4. selesai → event `tarik-sirus.selesai` {periode} supaya induk menyegarkan angkanya.
 * Pasang dengan wire:key berisi periode supaya komponen dibuat ulang saat periode diganti.
 */
new class extends Component {
    public string $periode = '';

    public ?int $snapIdProses = null;

    public function mount(string $periode): void
    {
        $this->periode = $periode;
        if (SnapshotJurnal::periodeValid($periode) && auth()->user()?->can('snapshot.ambil')) {
            $tertinggal = DB::table('sikeutxn_snap_hdrs')->where('periode', $periode)->where('status', SnapshotJurnal::STATUS_PROSES)->value('snap_id');
            $this->snapIdProses = $tertinggal ? (int) $tertinggal : null;
        }
    }

    #[Computed]
    public function periodeRow(): ?object
    {
        return DB::table('sikeumst_periodes')->where('periode', $this->periode)->first();
    }

    /** Alasan tombol tidak bisa dipakai; null = bisa. */
    #[Computed]
    public function alasanNonaktif(): ?string
    {
        if (!SnapshotJurnal::periodeValid($this->periode) || $this->periode > now()->format('Ym')) {
            return 'Periode tidak sah / masih di masa depan.';
        }
        if (!$this->periodeRow) {
            return 'Tahun buku belum dibuka (Pembukuan › Tarik Data SIRUS).';
        }
        if ($this->periodeRow->status !== BukuBesar::STATUS_BUKA) {
            return 'Periode sudah dikunci — buka kunci dulu bila data SIRUS perlu ditarik ulang.';
        }

        return null;
    }

    #[Computed]
    public function kemajuan(): ?array
    {
        return $this->snapIdProses ? (new SnapshotJurnal())->kemajuan($this->snapIdProses) : null;
    }

    public function tarik(): void
    {
        abort_unless(auth()->user()?->can('snapshot.ambil'), 403);
        if ($this->snapIdProses !== null || $this->alasanNonaktif !== null) {
            return;
        }
        try {
            $this->snapIdProses = (new SnapshotJurnal())->mulai($this->periode, auth()->id());
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: 'Gagal menarik data SIRUS: ' . $exception->getMessage());
        }
    }

    public function lanjutkan(): void
    {
        if ($this->snapIdProses === null || !auth()->user()?->can('snapshot.ambil')) {
            return;
        }
        $kemajuan = (new SnapshotJurnal())->prosesBerikut($this->snapIdProses, 5);
        unset($this->kemajuan);
        if ($kemajuan['status'] === SnapshotJurnal::STATUS_GAGAL) {
            $this->snapIdProses = null;
            $this->dispatch('toast', type: 'error', message: 'Penarikan data SIRUS gagal: ' . ($kemajuan['pesan'] ?? 'galat tak dikenal'));
            return;
        }
        if ($kemajuan['status'] !== SnapshotJurnal::STATUS_SELESAI) {
            return;
        }

        $snapId = $this->snapIdProses;
        $this->snapIdProses = null;
        $label = SnapshotJurnal::labelPeriode($this->periode);
        $snapBaru = DB::table('sikeutxn_snap_hdrs')->where('snap_id', $snapId)->first(['versi', 'checksum']);
        $snapSah = $this->periodeRow?->snap_id_sah
            ? DB::table('sikeutxn_snap_hdrs')->where('snap_id', $this->periodeRow->snap_id_sah)->first(['versi', 'checksum'])
            : null;

        if ($snapSah && $snapSah->checksum === $snapBaru->checksum) {
            $this->dispatch('toast', type: 'info', message: "Data SIRUS {$label} tidak berubah sejak versi {$snapSah->versi} yang disahkan — tidak perlu diposting ulang.");
        } elseif (!auth()->user()?->can('jurnal.posting')) {
            $this->dispatch('toast', type: 'info', message: "Data SIRUS {$label} sudah ditarik (versi {$snapBaru->versi}). Minta pengesah untuk Sahkan & Posting di Tarik Data SIRUS.");
        } else {
            try {
                $hasil = (new BukuBesar())->sahkanDanPosting($snapId, auth()->id());
                $this->dispatch('toast', type: 'success', message: "Data SIRUS {$label} ditarik & diposting (versi {$snapBaru->versi}, {$hasil['jurnal']} jurnal)"
                    . ($snapSah ? " — menggantikan versi {$snapSah->versi}." : '.'));
            } catch (\Throwable $exception) {
                $this->dispatch('toast', type: 'error', message: "Data SIRUS {$label} sudah ditarik, tetapi gagal diposting: " . $exception->getMessage());
            }
        }
        unset($this->periodeRow);
        $this->dispatch('tarik-sirus.selesai', periode: $this->periode);
    }
};
?>

<div class="flex items-center gap-2">
    @if ($snapIdProses !== null)
        @php
            $jumlahCabang = max(1, (int) ($this->kemajuan['jumlah_cabang'] ?? 1));
            $persen = (int) floor((int) ($this->kemajuan['cabang_selesai'] ?? 0) / $jumlahCabang * 100);
        @endphp
        <div wire:poll.2s="lanjutkan" role="status" aria-live="polite" class="flex items-center gap-2 text-xs text-blue-800 dark:text-blue-200">
            <x-ikon nama="muat-ulang" ukuran="w-3.5 h-3.5" class="animate-spin motion-reduce:animate-none" />
            <span class="whitespace-nowrap">Menarik data SIRUS {{ $persen }}%</span>
            <progress value="{{ $persen }}" max="100" aria-label="Kemajuan penarikan data SIRUS"
                class="block w-24 h-2 overflow-hidden rounded-full appearance-none bg-white dark:bg-gray-800 [&::-webkit-progress-bar]:bg-white [&::-webkit-progress-bar]:rounded-full [&::-webkit-progress-value]:bg-blue-600 [&::-webkit-progress-value]:rounded-full [&::-moz-progress-bar]:bg-blue-600 [&::-moz-progress-bar]:rounded-full dark:[&::-webkit-progress-bar]:bg-gray-800">{{ $persen }}%</progress>
        </div>
    @elseif (auth()->user()?->can('snapshot.ambil'))
        @if ($this->alasanNonaktif === null)
            <x-confirm-button variant="secondary" action="tarik()" class="!px-3 !py-2"
                title="Tarik Data SIRUS {{ SnapshotJurnal::labelPeriode($periode) }}"
                message="Jurnal SIRUS bulan ini ditarik ulang (kira-kira 40-70 detik). Bila ada perubahan dibanding versi yang disahkan, hasil tarikan baru langsung disahkan & diposting menggantikan yang lama. Biarkan halaman terbuka.">
                <x-ikon nama="muat-ulang" /> Tarik dari SIRUS
            </x-confirm-button>
        @else
            <x-secondary-button type="button" disabled title="{{ $this->alasanNonaktif }}" aria-label="Tarik dari SIRUS (tidak tersedia: {{ $this->alasanNonaktif }})">
                <x-ikon nama="muat-ulang" /> Tarik dari SIRUS
            </x-secondary-button>
        @endif
    @endif
</div>
