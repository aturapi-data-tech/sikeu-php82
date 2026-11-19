<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use App\Support\BaganAkun;
use App\Support\FormatKodeAkun;

/**
 * BAGAN AKUN — daftar akun buku besar SIKEU (sikeumst_akuns) berbentuk pohon; format kode dari pengaturan (FormatKodeAkun).
 * Seluruh bagan (±300 akun) tampil sekaligus tanpa halaman, supaya hierarkinya terbaca utuh.
 * Ubah/tambah/nonaktif/hapus lewat komponen bagan-akun-actions (gate akun.kelola).
 */
new class extends Component {
    #[Url(as: 'cari', except: '')]
    public string $searchKeyword = '';

    #[Url(as: 'kelompok', except: '')]
    public string $filterKelompok = '';

    #[Url(as: 'laporan', except: '')]
    public string $filterLaporan = '';

    public bool $tampilkanNonaktif = true;

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword', 'filterKelompok', 'filterLaporan']);
        $this->tampilkanNonaktif = true;
    }

    public function openCreate(?string $indukId = null): void
    {
        $this->dispatch('master.bagan-akun.openCreate', indukId: $indukId);
    }

    public function openEdit(string $akunId): void
    {
        $this->dispatch('master.bagan-akun.openEdit', akunId: $akunId);
    }

    public function toggleAktif(string $akunId): void
    {
        $this->dispatch('master.bagan-akun.toggleAktif', akunId: $akunId);
    }

    public function requestDelete(string $akunId): void
    {
        $this->dispatch('master.bagan-akun.requestDelete', akunId: $akunId);
    }

    #[On('master.bagan-akun.saved')]
    public function refreshAfterSaved(): void
    {
        unset($this->rows, $this->ringkasan);
    }

    /**
     * Filter berjenjang: Laporan -> Kelompok. Pilih laporan = daftar kelompok ikut menyempit; kelompok yang tidak
     * termasuk laporan terpilih dilepas. Pilih kelompok saat laporan masih "semua" = laporan ikut kelompoknya.
     */
    public function updatedFilterLaporan(): void
    {
        if (!in_array($this->filterLaporan, ['', 'N', 'L'], true)) {
            $this->filterLaporan = '';
        }
        if ($this->filterKelompok !== '' && !$this->kelompokList->contains('kelompok_id', $this->filterKelompok)) {
            $this->filterKelompok = '';
        }
    }

    public function updatedFilterKelompok(): void
    {
        $jenisLaporan = $this->semuaKelompok->firstWhere('kelompok_id', $this->filterKelompok)?->jenis_laporan;
        if ($jenisLaporan !== null && $this->filterLaporan === '') {
            $this->filterLaporan = $jenisLaporan;
        }
    }

    #[Computed]
    public function semuaKelompok()
    {
        return DB::table('sikeumst_akun_kelompoks')->orderBy('urut')->orderBy('kelompok_id')->get();
    }

    /** Kelompok yang boleh dipilih untuk laporan terpilih. */
    #[Computed]
    public function kelompokList()
    {
        return $this->semuaKelompok
            ->filter(fn ($kelompok) => $this->filterLaporan === '' || $kelompok->jenis_laporan === $this->filterLaporan)
            ->values();
    }

    #[Computed]
    public function rows()
    {
        $query = DB::table('sikeumst_akuns as a')
            ->leftJoin(
                DB::raw('(SELECT akun_id, COUNT(*) jumlah FROM sikeumst_peta_akun_sirus GROUP BY akun_id) p'),
                'p.akun_id', '=', 'a.akun_id'
            )
            ->join('sikeumst_akun_kelompoks as k', 'k.kelompok_id', '=', 'a.kelompok_id')
            ->select('a.akun_id', 'a.nama', 'a.kelompok_id', 'k.jenis_laporan', 'a.induk_id', 'a.tingkat', 'a.tipe', 'a.saldo_normal',
                'a.kas_status', 'a.kategori_arus_kas', 'a.kontrol_hp', 'a.pusat_biaya_wajib', 'a.aktif', 'a.keterangan',
                DB::raw('NVL(p.jumlah, 0) AS jumlah_peta'))
            ->orderBy('a.akun_id');

        if ($this->filterKelompok !== '' && $this->kelompokList->contains('kelompok_id', $this->filterKelompok)) {
            $query->where('a.kelompok_id', $this->filterKelompok);
        }
        if (in_array($this->filterLaporan, ['N', 'L'], true)) {
            $query->where('k.jenis_laporan', $this->filterLaporan);
        }
        if (!$this->tampilkanNonaktif) {
            $query->where('a.aktif', '1');
        }
        $keyword = trim($this->searchKeyword);
        if ($keyword !== '') {
            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->where('a.akun_id', 'like', $keyword . '%')
                    ->orWhereRaw('UPPER(a.nama) LIKE ?', ['%' . mb_strtoupper($keyword) . '%']);
            });
        }

        return $query->get();
    }

    #[Computed]
    public function ringkasan(): object
    {
        return DB::table('sikeumst_akuns')->selectRaw(
            "COUNT(*) AS jumlah, SUM(CASE WHEN tipe = 'D' THEN 1 ELSE 0 END) AS detail, SUM(CASE WHEN aktif = '0' THEN 1 ELSE 0 END) AS nonaktif"
        )->first();
    }
};
?>

<div>

    <x-page-title
        title="Bagan Akun"
        subtitle="Akun buku besar — format kode {{ FormatKodeAkun::contoh() }} ({{ FormatKodeAkun::jumlahTingkat() }} tingkat), hanya akun Detail yang boleh dijurnal" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="w-full sm:w-80">
                            <x-input-label for="searchKeyword" value="Cari Akun" class="sr-only" />
                            <x-text-input id="searchKeyword" type="text"
                                wire:model.live.debounce.300ms="searchKeyword"
                                placeholder="Cari kode (awalan) atau nama akun..." class="block w-full" />
                        </div>
                        <div class="w-full sm:w-48">
                            <x-input-label for="filterLaporan" value="Laporan" class="sr-only" />
                            <x-select-input id="filterLaporan" wire:model.live="filterLaporan" aria-label="Filter laporan">
                                <option value="">Neraca & Laba Rugi</option>
                                <option value="N">Neraca</option>
                                <option value="L">Laba Rugi</option>
                            </x-select-input>
                        </div>
                        <div class="w-full sm:w-64">
                            <x-input-label for="filterKelompok" value="Kelompok" class="sr-only" />
                            <x-select-input id="filterKelompok" wire:model.live="filterKelompok" aria-label="Filter kelompok akun">
                                <option value="">{{ ['N' => 'Semua kelompok Neraca', 'L' => 'Semua kelompok Laba Rugi'][$filterLaporan] ?? 'Semua kelompok' }}</option>
                                @foreach (['N' => 'Neraca', 'L' => 'Laba Rugi'] as $jenisLaporan => $namaLaporan)
                                    @php $kelompokLaporan = $this->kelompokList->where('jenis_laporan', $jenisLaporan); @endphp
                                    @if ($kelompokLaporan->isNotEmpty())
                                        <optgroup label="{{ $namaLaporan }}">
                                            @foreach ($kelompokLaporan as $kelompok)
                                                <option value="{{ $kelompok->kelompok_id }}">{{ $kelompok->kelompok_id }} - {{ $kelompok->nama }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                @endforeach
                            </x-select-input>
                        </div>
                        <x-toggle wire:model.live="tampilkanNonaktif" :trueValue="true" :falseValue="false" label="Tampilkan nonaktif" />
                    </div>

                    <div class="flex items-center justify-end gap-2">
                        <span class="text-xs text-muted dark:text-gray-400">
                            @if ($this->rows->count() < (int) $this->ringkasan->jumlah)
                                <b>{{ $this->rows->count() }}</b> tampil ({{ $this->rows->where('tipe', 'D')->count() }} detail) dari {{ $this->ringkasan->jumlah }} akun
                            @else
                                {{ $this->ringkasan->jumlah }} akun · {{ $this->ringkasan->detail }} detail · {{ $this->ringkasan->nonaktif }} nonaktif
                            @endif
                        </span>
                        @can('akun.kelola')
                            <x-primary-button type="button" wire:click="openCreate"><x-ikon nama="tambah" /> Tambah Akun</x-primary-button>
                        @endcan
                        <x-toolbar-refresh-reset :label="null" />
                    </div>
                </div>
            </div>

            {{-- TABLE --}}
            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto rounded-2xl">
                    <table class="ds-table ds-table-entri">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th>Kode</th>
                                <th>Nama Akun</th>
                                <th class="ds-c">Tipe</th>
                                <th class="ds-c">Saldo Normal</th>
                                <th>Sifat</th>
                                <th class="ds-c">Peta SIRUS</th>
                                <th class="ds-c">Aktif</th>
                                @can('akun.kelola')
                                    <th class="ds-c">Aksi</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                @php
                                    $indent = ['pl-0', 'pl-4', 'pl-8', 'pl-12', 'pl-16', 'pl-20'][min(max((int) $row->tingkat - 1, 0), 5)];
                                    $gayaNama = match (true) {
                                        (int) $row->tingkat === 1 => 'font-bold uppercase text-ink dark:text-gray-100',
                                        $row->tipe === 'H' => 'font-semibold uppercase text-ink dark:text-gray-100',
                                        default => 'text-body dark:text-gray-300',
                                    };
                                @endphp
                                <tr wire:key="akun-{{ $row->akun_id }}"
                                    class="{{ $row->aktif === '1' ? '' : 'opacity-60' }} {{ (int) $row->tingkat === 1 ? 'bg-surface-card dark:bg-gray-800' : '' }}">
                                    <td class="ds-td-token">{{ $row->akun_id }}</td>
                                    <td>
                                        <div class="{{ $indent }} {{ $gayaNama }}">
                                            {{ $row->nama }}
                                            @if ((int) $row->tingkat === 1)
                                                <x-badge class="ml-2 normal-case" :variant="$row->jenis_laporan === 'N' ? 'info' : 'purple'">{{ $row->jenis_laporan === 'N' ? 'Neraca' : 'Laba Rugi' }}</x-badge>
                                            @endif
                                        </div>
                                        @if ($row->keterangan)
                                            <div class="{{ $indent }} text-xs text-muted dark:text-gray-400">{{ $row->keterangan }}</div>
                                        @endif
                                    </td>
                                    <td class="ds-c">
                                        <x-badge :variant="$row->tipe === 'D' ? 'success' : 'gray'">{{ $row->tipe === 'D' ? 'Detail' : 'Header' }}</x-badge>
                                    </td>
                                    <td class="ds-c">
                                        <span class="font-mono text-xs">{{ $row->saldo_normal === 'D' ? 'Debit' : 'Kredit' }}</span>
                                    </td>
                                    <td>
                                        @if ($row->tipe === 'D')
                                            <div class="flex flex-wrap gap-1">
                                                @if ($row->kas_status === '1')
                                                    <x-badge variant="info">Kas/Bank</x-badge>
                                                @endif
                                                @if ($row->kategori_arus_kas)
                                                    <x-badge variant="alternative">Arus {{ BaganAkun::LABEL_ARUS_KAS[$row->kategori_arus_kas] ?? $row->kategori_arus_kas }}</x-badge>
                                                @endif
                                                @if ($row->kontrol_hp)
                                                    <x-badge variant="purple">Kontrol {{ BaganAkun::LABEL_KONTROL_HP[$row->kontrol_hp] ?? $row->kontrol_hp }}</x-badge>
                                                @endif
                                                @if ($row->pusat_biaya_wajib === '1')
                                                    <x-badge variant="warning">Pusat biaya</x-badge>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="ds-c">
                                        @if ((int) $row->jumlah_peta > 0)
                                            <a href="{{ route('master.peta-akun', ['cari' => $row->akun_id]) }}" wire:navigate
                                                class="text-xs font-medium text-brand-green hover:underline dark:text-brand-lime">{{ $row->jumlah_peta }} akun</a>
                                        @else
                                            <span class="text-muted-soft">-</span>
                                        @endif
                                    </td>
                                    <td class="ds-c">
                                        @if ((int) $row->tingkat >= 2)
                                            @can('akun.kelola')
                                                <x-toggle :current="$row->aktif" trueValue="1" falseValue="0"
                                                    wireClick="toggleAktif('{{ $row->akun_id }}')" />
                                            @else
                                                <x-badge :variant="$row->aktif === '1' ? 'success' : 'gray'">{{ $row->aktif === '1' ? 'Aktif' : 'Nonaktif' }}</x-badge>
                                            @endcan
                                        @else
                                            <a href="{{ route('master.kelompok-akun') }}" wire:navigate class="text-xs text-muted hover:underline">kelompok</a>
                                        @endif
                                    </td>
                                    @can('akun.kelola')
                                        <td class="ds-c">
                                            <div class="flex justify-end gap-2">
                                                @if ($row->tipe === 'H' && $row->aktif === '1' && (int) $row->tingkat < FormatKodeAkun::jumlahTingkat())
                                                    <x-secondary-button type="button" kecil
                                                        wire:click="openCreate('{{ $row->akun_id }}')"
                                                        aria-label="Tambah akun di bawah {{ $row->akun_id }}"><x-ikon nama="tambah" /> Tambah Anak</x-secondary-button>
                                                @endif
                                                <x-action-edit wire:click="openEdit('{{ $row->akun_id }}')" />
                                                @if ((int) $row->tingkat >= 2)
                                                    <x-action-delete :action="'requestDelete(\'' . $row->akun_id . '\')'"
                                                        title="Hapus Akun"
                                                        message="Hapus akun {{ $row->akun_id }} {{ $row->nama }}? Akun yang sudah dipetakan, dijurnal, atau punya anak tidak bisa dihapus — nonaktifkan saja." />
                                                @endif
                                            </div>
                                        </td>
                                    @endcan
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-10">
                                        <p class="text-base font-medium text-center text-muted dark:text-gray-400">
                                            Akun tidak ditemukan. Bagan belum terpasang? Jalankan database/sql/02_bagan_akun.sql.
                                        </p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @can('akun.kelola')
                <livewire:pages::master.bagan-akun.bagan-akun-actions wire:key="bagan-akun-actions" />
            @endcan

        </div>
    </div>
</div>
