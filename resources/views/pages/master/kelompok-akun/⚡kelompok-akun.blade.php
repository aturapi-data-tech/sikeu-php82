<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;

/**
 * KELOMPOK AKUN — setup akun Neraca vs Laba Rugi. Pembagiannya per kelompok (digit pertama kode akun):
 * semua akun ikut jenis_laporan kelompoknya. Kelompok N saldonya dibawa ke tahun berikutnya,
 * kelompok L dinolkan ke Saldo Laba saat tutup buku. Ubah lewat kelompok-akun-actions (gate akun.kelola).
 */
new class extends Component {
    public function openCreate(): void
    {
        $this->dispatch('master.kelompok-akun.openCreate');
    }

    public function requestDelete(string $kelompokId): void
    {
        $this->dispatch('master.kelompok-akun.requestDelete', kelompokId: $kelompokId);
    }

    public function openEdit(string $kelompokId): void
    {
        $this->dispatch('master.kelompok-akun.openEdit', kelompokId: $kelompokId);
    }

    #[On('master.kelompok-akun.saved')]
    public function refreshAfterSaved(): void
    {
        unset($this->rows);
    }

    #[Computed]
    public function rows()
    {
        return DB::table('sikeumst_akun_kelompoks as k')
            ->leftJoin(
                DB::raw("(SELECT kelompok_id, COUNT(*) jumlah, SUM(CASE WHEN tipe = 'D' THEN 1 ELSE 0 END) detail FROM sikeumst_akuns GROUP BY kelompok_id) a"),
                'a.kelompok_id', '=', 'k.kelompok_id'
            )
            ->orderBy('k.urut')->orderBy('k.kelompok_id')
            ->get(['k.kelompok_id', 'k.nama', 'k.saldo_normal', 'k.jenis_laporan', 'k.urut',
                DB::raw('NVL(a.jumlah, 0) AS jumlah_akun'), DB::raw('NVL(a.detail, 0) AS jumlah_detail')]);
    }
};
?>

<div>

    <x-page-title
        title="Kelompok Akun"
        subtitle="Setup akun Neraca & Laba Rugi — setiap akun ikut laporan kelompoknya (tingkat 1 kode akun)" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            <div class="px-4 py-3 text-sm rounded-xl bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200">
                <b>Neraca</b>: saldo akhir dibawa ke tahun berikutnya (aset, liabilitas, ekuitas).
                <b>Laba Rugi</b>: dihitung per periode dan dinolkan ke Saldo Laba saat tutup buku (pendapatan, beban, pajak).
                Kelompok yang akunnya sudah dijurnal tidak bisa dipindah laporan; kelompok hanya bisa dihapus selama masih kosong.
            </div>

            @can('akun.kelola')
                <div class="flex justify-end mt-3">
                    <x-primary-button type="button" wire:click="openCreate"><x-ikon nama="tambah" /> Tambah Kelompok</x-primary-button>
                </div>
            @endcan

            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto rounded-2xl">
                    <table class="ds-table">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th>Kode</th>
                                <th>Nama Kelompok</th>
                                <th class="ds-c">Laporan</th>
                                <th class="ds-c">Saldo Normal</th>
                                <th class="ds-c">Urut</th>
                                <th class="ds-c">Jumlah Akun</th>
                                @can('akun.kelola')
                                    <th class="ds-c">Aksi</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->rows as $row)
                                <tr wire:key="kelompok-{{ $row->kelompok_id }}">
                                    <td class="ds-td-token">{{ $row->kelompok_id }}</td>
                                    <td class="font-semibold uppercase text-ink dark:text-gray-100">{{ $row->nama }}</td>
                                    <td class="ds-c">
                                        <x-badge :variant="$row->jenis_laporan === 'N' ? 'info' : 'purple'">{{ $row->jenis_laporan === 'N' ? 'Neraca' : 'Laba Rugi' }}</x-badge>
                                    </td>
                                    <td class="ds-c">{{ $row->saldo_normal === 'D' ? 'Debit' : 'Kredit' }}</td>
                                    <td class="ds-c">{{ $row->urut }}</td>
                                    <td class="ds-c">
                                        <a href="{{ route('master.bagan-akun', ['kelompok' => $row->kelompok_id]) }}" wire:navigate
                                            class="text-xs font-medium text-brand-green hover:underline dark:text-brand-lime">{{ $row->jumlah_akun }} akun ({{ $row->jumlah_detail }} detail)</a>
                                    </td>
                                    @can('akun.kelola')
                                        <td class="ds-c">
                                            <div class="flex justify-end gap-2">
                                                <x-action-edit wire:click="openEdit('{{ $row->kelompok_id }}')" />
                                                <x-action-delete :action="'requestDelete(\'' . $row->kelompok_id . '\')'"
                                                    title="Hapus Kelompok"
                                                    message="Hapus kelompok {{ $row->kelompok_id }} {{ $row->nama }}? Hanya bisa bila kelompok belum berisi akun." />
                                            </div>
                                        </td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @can('akun.kelola')
                <livewire:pages::master.kelompok-akun.kelompok-akun-actions wire:key="kelompok-akun-actions" />
            @endcan

        </div>
    </div>
</div>
