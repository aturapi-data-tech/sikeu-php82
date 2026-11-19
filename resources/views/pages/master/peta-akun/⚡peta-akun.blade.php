<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use App\Support\BaganAkun;

/**
 * PETA AKUN SIRUS — pasangan acmst_accounts SIRUS (lewat API /akun) -> akun detail SIKEU.
 * Posting snapshot ditolak bila ada akun SIRUS yang belum dipetakan, jadi layar ini menonjolkan:
 *   BELUM    akun SIRUS baru, belum punya peta
 *   TUJUAN   peta menunjuk akun SIKEU yang nonaktif / bukan detail
 *   HILANG   peta untuk akun yang sudah tidak ada di SIRUS (aman dihapus)
 * Daftar SIRUS di-cache 10 menit (BaganAkun::akunSirusList); tombol Muat Ulang mengambil yang terbaru.
 */
new class extends Component {
    public const STATUS_OK = 'OK';
    public const STATUS_BELUM = 'BELUM';
    public const STATUS_TUJUAN = 'TUJUAN';
    public const STATUS_HILANG = 'HILANG';

    #[Url(as: 'cari', except: '')]
    public string $searchKeyword = '';

    #[Url(as: 'status', except: '')]
    public string $filterStatus = '';

    public ?string $galatSirus = null;

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword', 'filterStatus']);
    }

    public function muatUlangSirus(): void
    {
        $this->galatSirus = null;
        try {
            BaganAkun::akunSirusList(true);
            $this->dispatch('toast', type: 'success', message: 'Daftar akun SIRUS diperbarui.');
        } catch (\Throwable $exception) {
            $this->galatSirus = $exception->getMessage();
        }
        unset($this->semuaRows);
    }

    public function openEdit(string $accIdSirus): void
    {
        $this->dispatch('master.peta-akun.openEdit', accIdSirus: $accIdSirus);
    }

    public function requestDelete(string $accIdSirus): void
    {
        $this->dispatch('master.peta-akun.requestDelete', accIdSirus: $accIdSirus);
    }

    #[On('master.peta-akun.saved')]
    public function refreshAfterSaved(): void
    {
        unset($this->semuaRows);
    }

    /** Gabungan daftar SIRUS + tabel peta, lengkap dengan status tiap baris. */
    #[Computed]
    public function semuaRows(): array
    {
        $akunSirusList = [];
        $this->galatSirus = null;
        try {
            $akunSirusList = BaganAkun::akunSirusList();
        } catch (\Throwable $exception) {
            $this->galatSirus = $exception->getMessage();
        }

        $petaList = DB::table('sikeumst_peta_akun_sirus as p')
            ->leftJoin('sikeumst_akuns as a', 'a.akun_id', '=', 'p.akun_id')
            ->get(['p.acc_id_sirus', 'p.nama_sirus', 'p.akun_id', 'a.nama as nama_akun', 'a.aktif as aktif_akun', 'a.tipe as tipe_akun'])
            ->keyBy('acc_id_sirus');

        $rows = [];
        $sirusTerbaca = $this->galatSirus === null;
        foreach (array_unique(array_merge(array_keys($akunSirusList), $petaList->keys()->all())) as $accId) {
            $accId = (string) $accId;
            $sirus = $akunSirusList[$accId] ?? null;
            $peta = $petaList[$accId] ?? null;
            $status = self::STATUS_OK;
            if ($peta === null) {
                $status = self::STATUS_BELUM;
            } elseif ($peta->aktif_akun !== '1' || $peta->tipe_akun !== 'D') {
                $status = self::STATUS_TUJUAN;
            } elseif ($sirusTerbaca && $sirus === null) {
                $status = self::STATUS_HILANG;
            }
            $rows[] = [
                'acc_id' => $accId,
                'nama_sirus' => $sirus['acc_name'] ?? ($peta->nama_sirus ?? ''),
                'grup_sirus' => $sirus['acc_group'] ?? '',
                'dk_sirus' => $sirus['dk_status'] ?? '',
                'aktif_sirus' => $sirus === null ? null : $sirus['active_status'] === '1',
                'akun_id' => $peta->akun_id ?? null,
                'nama_akun' => $peta->nama_akun ?? null,
                'status' => $status,
            ];
        }
        usort($rows, fn ($rowA, $rowB) => strcmp($rowA['acc_id'], $rowB['acc_id']));

        return $rows;
    }

    #[Computed]
    public function rows(): array
    {
        $keyword = mb_strtoupper(trim($this->searchKeyword));

        return array_values(array_filter($this->semuaRows, function (array $row) use ($keyword) {
            if ($this->filterStatus === 'MASALAH' && $row['status'] === self::STATUS_OK) {
                return false;
            }
            if (!in_array($this->filterStatus, ['', 'MASALAH'], true) && $row['status'] !== $this->filterStatus) {
                return false;
            }
            if ($keyword === '') {
                return true;
            }
            return str_starts_with(mb_strtoupper($row['acc_id']), $keyword)
                || str_starts_with(mb_strtoupper((string) $row['akun_id']), $keyword)
                || str_contains(mb_strtoupper($row['nama_sirus']), $keyword)
                || str_contains(mb_strtoupper((string) $row['nama_akun']), $keyword);
        }));
    }

    #[Computed]
    public function jumlahPerStatus(): array
    {
        return array_count_values(array_column($this->semuaRows, 'status'));
    }
};
?>

<div>

    <x-page-title
        title="Peta Akun SIRUS"
        subtitle="Setiap akun SIRUS harus punya pasangan akun detail SIKEU — snapshot tidak bisa diposting bila ada yang belum dipetakan" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="w-full sm:w-80">
                            <x-input-label for="searchKeyword" value="Cari" class="sr-only" />
                            <x-text-input id="searchKeyword" type="text" wire:model.live.debounce.300ms="searchKeyword"
                                placeholder="Cari kode / nama akun SIRUS atau SIKEU..." class="block w-full" />
                        </div>
                        <div class="w-full sm:w-64">
                            <x-input-label for="filterStatus" value="Status" class="sr-only" />
                            <x-select-input id="filterStatus" wire:model.live="filterStatus">
                                <option value="">Semua status</option>
                                <option value="MASALAH">Perlu perhatian (selain OK)</option>
                                <option value="BELUM">Belum dipetakan</option>
                                <option value="TUJUAN">Tujuan nonaktif / bukan detail</option>
                                <option value="HILANG">Sudah tak ada di SIRUS</option>
                                <option value="OK">OK</option>
                            </x-select-input>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        @php $jumlah = $this->jumlahPerStatus; @endphp
                        <x-badge variant="success">OK {{ $jumlah['OK'] ?? 0 }}</x-badge>
                        <x-badge :variant="($jumlah['BELUM'] ?? 0) > 0 ? 'danger' : 'gray'">Belum {{ $jumlah['BELUM'] ?? 0 }}</x-badge>
                        <x-badge :variant="($jumlah['TUJUAN'] ?? 0) > 0 ? 'danger' : 'gray'">Tujuan {{ $jumlah['TUJUAN'] ?? 0 }}</x-badge>
                        <x-badge :variant="($jumlah['HILANG'] ?? 0) > 0 ? 'warning' : 'gray'">Hilang {{ $jumlah['HILANG'] ?? 0 }}</x-badge>
                        <x-secondary-button type="button" wire:click="muatUlangSirus" wire:loading.attr="disabled" wire:target="muatUlangSirus">
                            <span wire:loading.remove wire:target="muatUlangSirus" class="inline-flex items-center gap-2"><x-ikon nama="muat-ulang" /> Muat Ulang dari SIRUS</span>
                            <span wire:loading wire:target="muatUlangSirus">Memuat...</span>
                        </x-secondary-button>
                        <x-toolbar-refresh-reset :label="null" />
                    </div>
                </div>
                @if ($galatSirus)
                    <div class="px-3 py-2 mt-3 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">
                        API SIRUS tidak terbaca ({{ $galatSirus }}). Yang tampil hanya peta tersimpan — akun SIRUS baru belum terlihat.
                    </div>
                @endif
            </div>

            {{-- TABLE --}}
            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto rounded-2xl">
                    <table class="ds-table ds-table-entri">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th>Akun SIRUS</th>
                                <th>Nama di SIRUS</th>
                                <th class="ds-c">D/K</th>
                                <th>Akun SIKEU</th>
                                <th class="ds-c">Status</th>
                                @can('akun.kelola')
                                    <th class="ds-c">Aksi</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                <tr wire:key="peta-{{ $row['acc_id'] }}">
                                    <td class="ds-td-token">{{ $row['acc_id'] }}</td>
                                    <td>
                                        <div class="text-ink dark:text-gray-200">{{ $row['nama_sirus'] ?: '-' }}</div>
                                        @if ($row['aktif_sirus'] === false)
                                            <span class="text-[11px] text-muted dark:text-gray-400">nonaktif di SIRUS</span>
                                        @endif
                                    </td>
                                    <td class="ds-c font-mono text-xs">{{ $row['dk_sirus'] ?: '-' }}</td>
                                    <td>
                                        @if ($row['akun_id'])
                                            <span class="font-mono text-xs text-ink dark:text-gray-200">{{ $row['akun_id'] }}</span>
                                            <span class="text-body dark:text-gray-300">{{ $row['nama_akun'] ?? '(akun tidak ada)' }}</span>
                                        @else
                                            <span class="italic text-muted-soft">belum dipetakan</span>
                                        @endif
                                    </td>
                                    <td class="ds-c">
                                        @switch($row['status'])
                                            @case('OK') <x-badge variant="success">OK</x-badge> @break
                                            @case('BELUM') <x-badge variant="danger">Belum</x-badge> @break
                                            @case('TUJUAN') <x-badge variant="danger">Tujuan nonaktif</x-badge> @break
                                            @case('HILANG') <x-badge variant="warning">Tak ada di SIRUS</x-badge> @break
                                        @endswitch
                                    </td>
                                    @can('akun.kelola')
                                        <td class="ds-c">
                                            <div class="flex justify-end gap-2">
                                                <x-action-edit wire:click="openEdit('{{ $row['acc_id'] }}')">{{ $row['akun_id'] ? 'Ubah' : 'Petakan' }}</x-action-edit>
                                                @if ($row['status'] === 'HILANG')
                                                    <x-action-delete :action="'requestDelete(\'' . $row['acc_id'] . '\')'"
                                                        title="Hapus Peta"
                                                        message="Akun {{ $row['acc_id'] }} sudah tidak ada di SIRUS. Hapus petanya?" />
                                                @endif
                                            </div>
                                        </td>
                                    @endcan
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-10">
                                        <p class="text-base font-medium text-center text-muted dark:text-gray-400">Tidak ada baris yang cocok.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @can('akun.kelola')
                <livewire:pages::master.peta-akun.peta-akun-actions wire:key="peta-akun-actions" />
            @endcan

        </div>
    </div>
</div>
