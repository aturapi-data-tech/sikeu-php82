<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use App\Support\BaganAkun;
use App\Support\BukuBesar;
use App\Support\Format;
use App\Support\Pengaturan;
use App\Support\SaldoAwal;
use App\Support\SnapshotJurnal;

/**
 * PENGATURAN PEMBUKUAN — langkah pertama sebelum membukukan (menu Pembukuan paling atas):
 *   1. Tahun pertama SIKEU + Set Ulang (hak tutupBuku.jalankan, alasan wajib, pratinjau dampak — SaldoAwal::dampakSetUlang).
 *   2. Tahun buku sejak tahun pertama: status (belum dibuka / n dari 12 terkunci) + Buka Tahun Buku (hak periode.kunci);
 *      Reset Tahun (posting dibatalkan, jurnal umum jadi draft) / Hapus Tahun Buku bila belum dipakai (hak tutupBuku.jalankan);
 *      Tutup Buku (12 bulan terkunci → jurnal PENUTUP, BukuBesar::tutupBuku) / Batal Tutup Buku beralasan (hak tutupBuku.jalankan).
 *   3. Akun saldo laba: laba ditahan & laba tahun berjalan (hak akun.kelola).
 * Lanjutannya: Saldo Awal (tahun pertama) → Tarik Data SIRUS.
 */
new class extends Component {
    /** Jendela Reset Tahun */
    public ?int $tahunReset = null;
    public string $alasanReset = '';

    /** Jendela Tutup Buku / Batal Tutup Buku */
    public ?int $tahunTutup = null;
    public bool $batalTutup = false;
    public string $alasanBatalTutup = '';

    /** Jendela Set Ulang */
    public string $tahunBaru = '';
    public string $alasanSetUlang = '';

    public string $akunLabaDitahan = '';
    public string $akunLabaBerjalan = '';

    public function mount(): void
    {
        $akunLaba = SaldoAwal::akunLaba();
        $this->akunLabaDitahan = $akunLaba['ditahan'];
        $this->akunLabaBerjalan = $akunLaba['berjalan'];
    }

    #[Computed]
    public function tahunMulai(): int
    {
        return SaldoAwal::tahunMulai();
    }

    /** Tahun buku sejak tahun pertama sampai tahun terakhir yang dibuka (minimal tahun ini). */
    #[Computed]
    public function tahunList(): array
    {
        $statusTahun = DB::table('sikeumst_tahun_bukus')->pluck('status', 'tahun');
        $ringkasan = DB::table('sikeumst_periodes')
            ->selectRaw("SUBSTR(periode, 1, 4) AS tahun, COUNT(*) AS jumlah,
                SUM(CASE WHEN status IN ('K', 'T') THEN 1 ELSE 0 END) AS terkunci,
                SUM(CASE WHEN snap_id_sah IS NOT NULL THEN 1 ELSE 0 END) AS diposting")
            ->groupByRaw('SUBSTR(periode, 1, 4)')->get()->keyBy('tahun');
        // jurnal aktif per tahun (posting/draft apa pun, atau jurnal non-SIRUS termasuk yang dibatalkan) → Reset, bukan Hapus
        $jurnalAktif = DB::table('sikeutxn_jurnal_hdrs')
            ->where(fn ($subQuery) => $subQuery->whereIn('status', ['P', 'D'])->orWhere('sumber', '<>', BukuBesar::SUMBER_SIRUS))
            ->selectRaw('SUBSTR(periode, 1, 4) AS tahun, COUNT(*) AS jumlah')->groupByRaw('SUBSTR(periode, 1, 4)')->pluck('jumlah', 'tahun');
        $tahunAkhir = max((int) now()->format('Y'), $this->tahunMulai, ...$ringkasan->keys()->map(fn ($tahun) => (int) $tahun)->all());
        $rows = [];
        for ($tahun = $this->tahunMulai; $tahun <= $tahunAkhir; $tahun++) {
            $row = $ringkasan[(string) $tahun] ?? null;
            $rows[] = ['tahun' => $tahun, 'dibuka' => $row !== null, 'terkunci' => (int) ($row->terkunci ?? 0), 'diposting' => (int) ($row->diposting ?? 0),
                'ada_jurnal' => (int) ($jurnalAktif[(string) $tahun] ?? 0) > 0,
                'tutup_buku' => ($statusTahun[$tahun] ?? $statusTahun[(string) $tahun] ?? null) === BukuBesar::STATUS_TUTUP];
        }

        return $rows;
    }

    #[Computed]
    public function giliranKunci(): array
    {
        return BukuBesar::giliranKunci();
    }

    #[Computed]
    public function akunNeracaList(): array
    {
        return DB::table('sikeumst_akuns as a')->join('sikeumst_akun_kelompoks as k', 'k.kelompok_id', '=', 'a.kelompok_id')
            ->where('a.tipe', 'D')->where('a.aktif', '1')->where('k.jenis_laporan', 'N')->orderBy('a.akun_id')
            ->pluck('a.nama', 'a.akun_id')->all();
    }

    private function segarkan(): void
    {
        unset($this->tahunMulai, $this->tahunList, $this->giliranKunci, $this->dampakSetUlang, $this->dampakReset, $this->dampakTutup);
    }

    // ─── Tahun buku ───────────────────────────────────────────────────────────
    public function bukaTahun(int $tahun): void
    {
        abort_unless(auth()->user()?->can('periode.kunci'), 403);
        try {
            (new BukuBesar())->bukaTahun($tahun, auth()->id());
            $this->dispatch('toast', type: 'success', message: "Tahun buku {$tahun} dibuka (12 periode).");
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
    }

    public function hapusTahun(int $tahun): void
    {
        abort_unless(auth()->user()?->can('tutupBuku.jalankan'), 403);
        try {
            (new BukuBesar())->hapusTahunBuku($tahun, auth()->id());
            $this->dispatch('toast', type: 'success', message: "Tahun buku {$tahun} dihapus — kembali Belum dibuka. Hasil tarikan SIRUS tetap tersimpan.");
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
    }

    public function bukaReset(int $tahun): void
    {
        abort_unless(auth()->user()?->can('tutupBuku.jalankan'), 403);
        $this->tahunReset = $tahun;
        $this->alasanReset = '';
        $this->resetValidation();
        unset($this->dampakReset);
        $this->dispatch('open-modal', name: 'reset-tahun');
    }

    #[Computed]
    public function dampakReset(): ?array
    {
        return $this->tahunReset ? (new BukuBesar())->dampakResetTahun($this->tahunReset) : null;
    }

    public function resetTahun(): void
    {
        abort_unless(auth()->user()?->can('tutupBuku.jalankan'), 403);
        $this->resetValidation();
        try {
            $hasil = (new BukuBesar())->resetTahun((int) $this->tahunReset, $this->alasanReset, auth()->id());
        } catch (\Throwable $exception) {
            $this->addError('alasanReset', $exception->getMessage());
            return;
        }
        $this->dispatch('toast', type: 'success', message: "Tahun {$hasil['tahun']} direset: " . count($hasil['periode_posting']) . ' bulan dilepas postingnya'
            . ($hasil['jurnal_umum'] > 0 ? ", {$hasil['jurnal_umum']} jurnal umum kembali jadi draft" : '') . '. Hasil tarikan tetap — tinggal Sahkan lagi.');
        $this->tutupReset();
        $this->segarkan();
    }

    public function tutupReset(): void
    {
        $this->reset(['tahunReset', 'alasanReset']);
        $this->resetValidation();
        unset($this->dampakReset);
        $this->dispatch('close-modal', name: 'reset-tahun');
    }

    // ─── Tutup buku tahunan ───────────────────────────────────────────────────
    public function bukaTutupBuku(int $tahun, bool $batal = false): void
    {
        abort_unless(auth()->user()?->can('tutupBuku.jalankan'), 403);
        $this->tahunTutup = $tahun;
        $this->batalTutup = $batal;
        $this->alasanBatalTutup = '';
        $this->resetValidation();
        unset($this->dampakTutup);
        $this->dispatch('open-modal', name: 'tutup-buku');
    }

    /** Pratinjau jendela: syarat + jurnal penutup (tutup) atau syarat pembatalan (batal). */
    #[Computed]
    public function dampakTutup(): ?array
    {
        if (!$this->tahunTutup) {
            return null;
        }
        $bukuBesar = new BukuBesar();

        return $this->batalTutup ? $bukuBesar->dampakBatalTutupBuku($this->tahunTutup) : $bukuBesar->dampakTutupBuku($this->tahunTutup);
    }

    public function tutupBuku(): void
    {
        abort_unless(auth()->user()?->can('tutupBuku.jalankan'), 403);
        try {
            $hasil = (new BukuBesar())->tutupBuku((int) $this->tahunTutup, auth()->id());
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
            unset($this->dampakTutup);
            return;
        }
        $this->dispatch('toast', type: 'success', message: "Tahun {$hasil['tahun']} tutup buku: " . ($hasil['laba'] >= 0 ? 'laba' : 'rugi')
            . ' Rp ' . Format::uang(abs($hasil['laba'])) . ' dipindah ke saldo laba' . ($hasil['no_jurnal'] ? " ({$hasil['no_jurnal']})" : '') . '.');
        $this->tutupJendelaTutup();
        $this->segarkan();
    }

    public function batalTutupBuku(): void
    {
        abort_unless(auth()->user()?->can('tutupBuku.jalankan'), 403);
        $this->resetValidation();
        try {
            $hasil = (new BukuBesar())->batalTutupBuku((int) $this->tahunTutup, $this->alasanBatalTutup, auth()->id());
        } catch (\Throwable $exception) {
            $this->addError('alasanBatalTutup', $exception->getMessage());
            return;
        }
        $this->dispatch('toast', type: 'success', message: "Tutup buku {$hasil['tahun']} dibatalkan: jurnal penutup berstatus batal, 12 bulan kembali Kunci.");
        $this->tutupJendelaTutup();
        $this->segarkan();
    }

    public function tutupJendelaTutup(): void
    {
        $this->reset(['tahunTutup', 'batalTutup', 'alasanBatalTutup']);
        $this->resetValidation();
        unset($this->dampakTutup);
        $this->dispatch('close-modal', name: 'tutup-buku');
    }

    // ─── Set ulang tahun pertama ──────────────────────────────────────────────
    public function bukaSetUlang(): void
    {
        abort_unless(auth()->user()?->can('tutupBuku.jalankan'), 403);
        $this->reset(['tahunBaru', 'alasanSetUlang']);
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'set-ulang-tahun');
    }

    /** Dampak set ulang untuk tahun yang sedang diketik di jendela (null bila belum 4 angka). */
    #[Computed]
    public function dampakSetUlang(): ?array
    {
        return preg_match('/^\d{4}$/', trim($this->tahunBaru)) ? SaldoAwal::dampakSetUlang((int) trim($this->tahunBaru)) : null;
    }

    public function setUlang(): void
    {
        abort_unless(auth()->user()?->can('tutupBuku.jalankan'), 403);
        $this->resetValidation();
        if (!preg_match('/^\d{4}$/', trim($this->tahunBaru))) {
            $this->addError('tahunBaru', 'Ketik tahun 4 angka.');
            return;
        }
        try {
            $hasil = SaldoAwal::setUlangTahunMulai((int) trim($this->tahunBaru), $this->alasanSetUlang, auth()->id());
        } catch (\Throwable $exception) {
            $this->addError('alasanSetUlang', $exception->getMessage());
            return;
        }
        $tahunBaru = SaldoAwal::tahunMulai();
        $this->dispatch('toast', type: 'success', message: "Tahun pertama SIKEU sekarang {$tahunBaru}."
            . ($hasil['saldo_awal'] !== [] ? ' Saldo awal dibatalkan: ' . implode(', ', $hasil['saldo_awal']) . '.' : '')
            . ($hasil['jurnal_dibatalkan'] > 0 ? " {$hasil['jurnal_dibatalkan']} jurnal posting dibatalkan." : '')
            . " Lanjutkan: buka tahun buku {$tahunBaru}, lalu isi Saldo Awal.");
        $this->tutupSetUlang();
        $this->segarkan();
    }

    public function tutupSetUlang(): void
    {
        $this->reset(['tahunBaru', 'alasanSetUlang']);
        $this->resetValidation();
        unset($this->dampakSetUlang);
        $this->dispatch('close-modal', name: 'set-ulang-tahun');
    }

    // ─── Akun saldo laba ──────────────────────────────────────────────────────
    public function simpanAkunLaba(): void
    {
        abort_unless(auth()->user()?->can('akun.kelola'), 403);
        foreach ([$this->akunLabaDitahan, $this->akunLabaBerjalan] as $akunId) {
            if (!isset($this->akunNeracaList[$akunId])) {
                $this->dispatch('toast', type: 'error', message: 'Akun laba harus akun Detail Neraca yang aktif.');
                return;
            }
        }
        if ($this->akunLabaDitahan === $this->akunLabaBerjalan) {
            $this->dispatch('toast', type: 'error', message: 'Akun laba ditahan dan laba berjalan harus berbeda.');
            return;
        }
        $lama = SaldoAwal::akunLaba();
        Pengaturan::simpan('akun.laba_ditahan', $this->akunLabaDitahan);
        Pengaturan::simpan('akun.laba_berjalan', $this->akunLabaBerjalan);
        BaganAkun::catatAudit('UBAH', 'SIKEUMST_PENGATURANS', 'akun.laba', $lama, SaldoAwal::akunLaba());
        $this->dispatch('toast', type: 'success', message: 'Akun saldo laba disimpan.');
    }
};
?>

<div>
    <x-page-title title="Pengaturan Pembukuan"
        subtitle="Langkah pertama: tahun pertama SIKEU, tahun buku, dan akun saldo laba — lanjutkan ke Saldo Awal lalu Tarik Data SIRUS" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="max-w-5xl px-6 pt-2 pb-6 space-y-4">

            {{-- 1. TAHUN PERTAMA --}}
            <x-border-form title="1. Tahun pertama SIKEU">
                <div class="flex flex-wrap items-center gap-4">
                    <span class="px-4 py-2 font-mono text-2xl font-bold border rounded-xl border-hairline text-ink dark:text-gray-100 dark:border-gray-700">{{ $this->tahunMulai }}</span>
                    <div class="flex-1 min-w-64 text-sm text-body dark:text-gray-300">
                        Pembukuan SIKEU dimulai 1 Januari {{ $this->tahunMulai }}: saldo awal hanya diisi di tahun ini, tahun sesudahnya diteruskan otomatis,
                        tahun sebelumnya hanya untuk dicek. Kunci berikutnya: <b class="text-ink dark:text-gray-100">{{ SnapshotJurnal::labelPeriode($this->giliranKunci['periode']) }}</b>.
                    </div>
                    @can('tutupBuku.jalankan')
                        <x-warning-button type="button" wire:click="bukaSetUlang"><x-ikon nama="muat-ulang" /> Set Ulang</x-warning-button>
                    @endcan
                </div>
            </x-border-form>

            {{-- 2. TAHUN BUKU --}}
            <x-border-form title="2. Tahun buku">
                <p class="mb-3 text-sm text-body dark:text-gray-300">Dikunci berurutan per tahun: {{ $this->tahunMulai }} terkunci penuh dulu, baru tahun berikutnya.
                    Setelah 12 bulan terkunci, <b>Tutup Buku</b> memindahkan laba/rugi tahun itu ke akun laba ditahan (jurnal penutup 31 Desember).</p>
                <div class="overflow-x-auto border rounded-xl border-hairline dark:border-gray-700">
                    <table class="ds-table ds-table-entri">
                        <thead><tr><th>Tahun</th><th class="ds-c">Status</th><th class="ds-c">Diposting</th><th class="ds-c">Terkunci</th><th class="ds-c">Aksi</th></tr></thead>
                        <tbody>
                            @foreach ($this->tahunList as $row)
                                <tr wire:key="tahun-{{ $row['tahun'] }}">
                                    <td class="font-mono font-semibold text-ink dark:text-gray-100">{{ $row['tahun'] }}
                                        @if ($row['tahun'] === $this->tahunMulai) <x-badge variant="info">tahun pertama</x-badge> @endif
                                    </td>
                                    <td class="ds-c">
                                        @if (!$row['dibuka'])
                                            <x-badge variant="gray">Belum dibuka</x-badge>
                                        @elseif ($row['tutup_buku'])
                                            <x-badge variant="success">Tutup buku</x-badge>
                                        @elseif ($row['terkunci'] === 12)
                                            <x-badge variant="info">Terkunci penuh</x-badge>
                                        @else
                                            <x-badge variant="warning">Berjalan</x-badge>
                                        @endif
                                    </td>
                                    <td class="ds-c tabular-nums">{{ $row['dibuka'] ? $row['diposting'] . ' / 12' : '-' }}</td>
                                    <td class="ds-c tabular-nums">{{ $row['dibuka'] ? $row['terkunci'] . ' / 12' : '-' }}</td>
                                    <td class="ds-c">
                                        <div class="flex justify-end gap-2">
                                            @if (!$row['dibuka'])
                                                @can('periode.kunci')
                                                    <x-confirm-button variant="primary" action="bukaTahun({{ $row['tahun'] }})" title="Buka Tahun Buku {{ $row['tahun'] }}"
                                                        message="Membuat tahun buku {{ $row['tahun'] }} dan 12 periode berstatus Buka.">
                                                        <x-ikon nama="tambah" /> Buka Tahun Buku
                                                    </x-confirm-button>
                                                @endcan
                                            @else
                                                @if ($row['tahun'] === $this->tahunMulai)
                                                    <x-outline-button type="button" kecil x-on:click="Livewire.navigate('{{ route('buku-besar.saldo-awal', ['tahun' => $row['tahun']]) }}')"><x-ikon nama="lihat" /> Saldo Awal</x-outline-button>
                                                @endif
                                                <x-outline-button type="button" kecil x-on:click="Livewire.navigate('{{ route('buku-besar.periode', ['tahun' => $row['tahun']]) }}')"><x-ikon nama="lihat" /> Tarik Data SIRUS</x-outline-button>
                                                @can('tutupBuku.jalankan')
                                                    @if ($row['tutup_buku'])
                                                        <x-warning-button type="button" kecil wire:click="bukaTutupBuku({{ $row['tahun'] }}, true)"><x-ikon nama="buka-kunci" /> Batal Tutup Buku</x-warning-button>
                                                    @elseif ($row['terkunci'] === 12)
                                                        <x-primary-button type="button" kecil wire:click="bukaTutupBuku({{ $row['tahun'] }})"><x-ikon nama="kunci" /> Tutup Buku</x-primary-button>
                                                    @elseif ($row['ada_jurnal'] || $row['diposting'] > 0)
                                                        <x-warning-button type="button" kecil wire:click="bukaReset({{ $row['tahun'] }})"><x-ikon nama="muat-ulang" /> Reset Tahun</x-warning-button>
                                                    @elseif ($row['terkunci'] === 0)
                                                        <x-confirm-button variant="danger-soft" action="hapusTahun({{ $row['tahun'] }})" title="Hapus Tahun Buku {{ $row['tahun'] }}"
                                                            message="Tahun {{ $row['tahun'] }} belum dipakai. Ke-12 periodenya dihapus dan kembali Belum dibuka; hasil tarikan SIRUS tetap tersimpan.">
                                                            <x-ikon nama="hapus" /> Hapus Tahun Buku
                                                        </x-confirm-button>
                                                    @endif
                                                @endcan
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-border-form>

            {{-- 3. AKUN SALDO LABA --}}
            <x-border-form title="3. Akun saldo laba">
                <div class="grid items-end grid-cols-1 gap-3 md:grid-cols-3">
                    @foreach (['akunLabaDitahan' => 'Laba ditahan (laba tahun-tahun lalu)', 'akunLabaBerjalan' => 'Laba tahun berjalan (nol per 1 Januari)'] as $properti => $judul)
                        <div>
                            <x-input-label for="{{ $properti }}" value="{{ $judul }}" />
                            <x-select-input id="{{ $properti }}" wire:model="{{ $properti }}" :disabled="!auth()->user()?->can('akun.kelola')" class="mt-1">
                                @foreach ($this->akunNeracaList as $akunId => $namaAkun)
                                    @if (str_starts_with((string) $akunId, '3') || $akunId === $$properti)
                                        <option value="{{ $akunId }}">{{ $akunId }} {{ $namaAkun }}</option>
                                    @endif
                                @endforeach
                            </x-select-input>
                        </div>
                    @endforeach
                    @can('akun.kelola')
                        <div><x-secondary-button type="button" wire:click="simpanAkunLaba" wire:loading.attr="disabled" wire:target="simpanAkunLaba"><x-ikon nama="simpan" /> Simpan Akun Laba</x-secondary-button></div>
                    @endcan
                </div>
                <p class="mt-2 text-xs text-muted dark:text-gray-400">Dipakai Neraca: laba tahun-tahun lalu masuk akun laba ditahan; akun laba berjalan tidak punya saldo awal.</p>
            </x-border-form>

            {{-- LANJUT --}}
            <div class="flex flex-wrap justify-end gap-2">
                <x-primary-button type="button" x-on:click="Livewire.navigate('{{ route('buku-besar.saldo-awal') }}')"><x-ikon nama="berikutnya" /> Lanjut ke Saldo Awal</x-primary-button>
            </div>
        </div>
    </div>

    {{-- JENDELA RESET TAHUN --}}
    <x-modal name="reset-tahun" size="2xl">
        <div class="flex flex-col">
            <x-modul-dokumen.header judul="Reset Tahun {{ $tahunReset }}"
                ikon="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" tutupAlpine="$wire.tutupReset()">
                Mengulang pembukuan satu tahun: posting dilepas, hasil tarikan SIRUS tetap — tinggal Sahkan lagi.
            </x-modul-dokumen.header>
            <div class="p-5 space-y-4 text-sm bg-canvas text-body dark:bg-gray-900 dark:text-gray-300">
                @php $dampakReset = $this->dampakReset; @endphp
                @if ($dampakReset)
                    @if ($dampakReset['galat'])
                        <div role="alert" class="flex gap-2 px-3 py-2 rounded-lg bg-danger-soft text-fg-danger-strong"><x-ikon-peringatan /><p>{{ $dampakReset['galat'] }}</p></div>
                    @else
                        <ul class="p-3 pl-8 space-y-1 border list-disc rounded-xl border-hairline dark:border-gray-700">
                            <li><b>{{ count($dampakReset['periode_posting']) }} bulan</b> dilepas postingnya ({{ $dampakReset['jurnal_sirus'] }} jurnal SIRUS dibatalkan){{ $dampakReset['periode_posting'] !== [] ? ': ' . implode(', ', array_map(fn ($periode) => SnapshotJurnal::labelPeriode($periode), $dampakReset['periode_posting'])) : '' }}.</li>
                            <li><b>{{ $dampakReset['jurnal_umum'] }} jurnal umum</b> terposting dikembalikan jadi <b>draft</b> — tidak hilang, bisa diposting lagi.</li>
                            <li>Saldo awal tidak disentuh; hasil tarikan SIRUS tetap tersimpan.</li>
                        </ul>
                    @endif
                @endif
                <div>
                    <x-input-label for="alasanReset" value="Alasan *" />
                    <x-text-input id="alasanReset" wire:model="alasanReset" maxlength="300" placeholder="mis. peta akun diperbaiki, posting ulang setahun" :error="$errors->has('alasanReset')" class="mt-1" />
                    <x-input-error :messages="$errors->get('alasanReset')" class="mt-1" />
                </div>
                <div class="flex justify-end gap-2">
                    <x-secondary-button type="button" wire:click="tutupReset"><x-ikon nama="batal" /> Batal</x-secondary-button>
                    <x-danger-button type="button" wire:click="resetTahun" wire:loading.attr="disabled" wire:target="resetTahun" :disabled="!$dampakReset || !$dampakReset['boleh']">
                        <x-ikon nama="muat-ulang" /> Reset Tahun {{ $tahunReset }}
                    </x-danger-button>
                </div>
            </div>
        </div>
    </x-modal>

    {{-- JENDELA TUTUP BUKU / BATAL TUTUP BUKU --}}
    <x-modal name="tutup-buku" size="2xl">
        <div class="flex flex-col">
            <x-modul-dokumen.header judul="{{ $batalTutup ? 'Batal Tutup Buku' : 'Tutup Buku' }} {{ $tahunTutup }}"
                ikon="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" tutupAlpine="$wire.tutupJendelaTutup()">
                {{ $batalTutup ? 'Jurnal penutup dibatalkan (tetap tersimpan), 12 bulan kembali berstatus Kunci.' : 'Saldo akun Laba Rugi dinolkan ke akun laba ditahan lewat jurnal penutup 31 Desember.' }}
            </x-modul-dokumen.header>
            <div class="p-5 space-y-4 text-sm bg-canvas text-body dark:bg-gray-900 dark:text-gray-300">
                @php $dampakTutup = $this->dampakTutup; @endphp
                @if ($dampakTutup)
                    @if ($dampakTutup['galat'])
                        <div role="alert" class="flex gap-2 px-3 py-2 rounded-lg bg-danger-soft text-fg-danger-strong"><x-ikon-peringatan /><p>{{ $dampakTutup['galat'] }}</p></div>
                    @endif

                    @if (!$batalTutup)
                        <div class="flex flex-wrap items-baseline justify-between gap-2 p-3 border rounded-xl border-hairline dark:border-gray-700">
                            <span>{{ $dampakTutup['laba'] >= 0 ? 'Laba' : 'Rugi' }} tahun {{ $tahunTutup }} ({{ count($dampakTutup['akun']) }} akun Laba Rugi)</span>
                            <span class="text-lg font-semibold tabular-nums {{ $dampakTutup['laba'] >= 0 ? 'text-ink dark:text-gray-100' : 'text-fg-danger-strong' }}">Rp {{ Format::uang(abs($dampakTutup['laba'])) }}</span>
                        </div>
                        @if ($dampakTutup['boleh'])
                            <ul class="pl-5 space-y-1 list-disc">
                                <li>Jurnal penutup 31 Desember {{ $tahunTutup }}: tiap akun Laba Rugi dinolkan, {{ $dampakTutup['laba'] >= 0 ? 'laba' : 'rugi' }} masuk ke
                                    <b>{{ $dampakTutup['akun_ditahan'] }} {{ $dampakTutup['nama_ditahan'] }}</b>.</li>
                                <li>Laporan {{ $tahunTutup }} (Laba Rugi, Neraca, Neraca Saldo s/d Desember) <b>tidak berubah</b>; mulai Januari {{ $tahunTutup + 1 }}
                                    baris "laba tahun lalu belum ditutup" hilang dan angkanya tampil di akun laba ditahan.</li>
                                <li>Tahun {{ $tahunTutup }} berstatus <b>Tutup</b>: tidak bisa buka kunci, posting, atau jurnal umum sampai tutup bukunya dibatalkan.</li>
                                @if ($dampakTutup['bulan_sesudah_terkunci'] > 0)
                                    <li>Saldo tersimpan {{ $dampakTutup['bulan_sesudah_terkunci'] }} bulan terkunci sesudahnya dihitung ulang (totalnya tetap).</li>
                                @endif
                            </ul>
                        @endif
                    @else
                        @if ($dampakTutup['boleh'])
                            <ul class="pl-5 space-y-1 list-disc">
                                <li>Jurnal penutup <b>{{ $dampakTutup['no_jurnal'] ?? '-' }}</b> berstatus batal (tetap tersimpan sebagai jejak).</li>
                                <li>12 bulan {{ $tahunTutup }} kembali berstatus <b>Kunci</b>; laba/rugi Rp {{ Format::uang(abs($dampakTutup['laba'])) }} kembali tampil sebagai "laba tahun lalu belum ditutup".</li>
                                <li>Sesudah diperbaiki (buka kunci, koreksi, kunci lagi), jalankan Tutup Buku lagi.</li>
                            </ul>
                        @endif
                        <div>
                            <x-input-label for="alasanBatalTutup" value="Alasan *" />
                            <x-text-input id="alasanBatalTutup" wire:model="alasanBatalTutup" maxlength="300" placeholder="mis. koreksi audit KAP atas persediaan Desember" :error="$errors->has('alasanBatalTutup')" class="mt-1" />
                            <x-input-error :messages="$errors->get('alasanBatalTutup')" class="mt-1" />
                        </div>
                    @endif
                @endif
                <div class="flex justify-end gap-2">
                    <x-secondary-button type="button" wire:click="tutupJendelaTutup"><x-ikon nama="batal" /> Batal</x-secondary-button>
                    @if ($batalTutup)
                        <x-warning-button type="button" wire:click="batalTutupBuku" wire:loading.attr="disabled" wire:target="batalTutupBuku" :disabled="!$dampakTutup || !$dampakTutup['boleh']">
                            <x-ikon nama="buka-kunci" /> Batal Tutup Buku {{ $tahunTutup }}
                        </x-warning-button>
                    @else
                        <x-primary-button type="button" wire:click="tutupBuku" wire:loading.attr="disabled" wire:target="tutupBuku" :disabled="!$dampakTutup || !$dampakTutup['boleh']">
                            <x-ikon nama="kunci" /> Tutup Buku {{ $tahunTutup }}
                        </x-primary-button>
                    @endif
                </div>
            </div>
        </div>
    </x-modal>

    {{-- JENDELA SET ULANG TAHUN PERTAMA --}}
    <x-modal name="set-ulang-tahun" size="2xl">
        <div class="flex flex-col">
            <x-modul-dokumen.header judul="Set Ulang Tahun Pertama SIKEU"
                ikon="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" tutupAlpine="$wire.tutupSetUlang()">
                Sekarang {{ $this->tahunMulai }}. Pembukuan dimulai dari tahun baru: saldo awal diisi di tahun itu, lalu tiap tahun dikunci berurutan.
            </x-modul-dokumen.header>
            <div class="p-5 space-y-4 text-sm bg-canvas text-body dark:bg-gray-900 dark:text-gray-300">
                <div class="w-40">
                    <x-input-label for="tahunBaru" value="Tahun pertama baru *" />
                    <x-text-input id="tahunBaru" wire:model.live.debounce.400ms="tahunBaru" inputmode="numeric" maxlength="4" placeholder="mis. 2024" :error="$errors->has('tahunBaru')" class="mt-1" />
                    <x-input-error :messages="$errors->get('tahunBaru')" class="mt-1" />
                </div>

                @php $dampak = $this->dampakSetUlang; @endphp
                @if ($dampak)
                    @if ($dampak['galat'])
                        <div role="alert" class="flex gap-2 px-3 py-2 rounded-lg bg-danger-soft text-fg-danger-strong"><x-ikon-peringatan /><p>{{ $dampak['galat'] }}</p></div>
                    @else
                        <div class="p-3 space-y-2 border rounded-xl border-hairline dark:border-gray-700">
                            <p class="font-semibold text-ink dark:text-gray-100">{{ $dampak['arah'] === 'mundur' ? 'Mundur' : 'Maju' }}: {{ $dampak['lama'] }} → {{ $dampak['baru'] }}. Yang terjadi:</p>
                            <ul class="pl-5 space-y-1 list-disc">
                                @if ($dampak['saldo_awal'] !== [])
                                    <li>Saldo awal <b>{{ implode(', ', $dampak['saldo_awal']) }}</b> dibatalkan{{ $dampak['arah'] === 'mundur' ? ' — tahun itu nanti diteruskan dari tahun sebelumnya' : '' }}.</li>
                                @endif
                                @if ($dampak['arah'] === 'maju')
                                    <li>{{ count($dampak['periode_posting']) }} bulan terposting sebelum {{ $dampak['baru'] }} dibatalkan postingnya{{ $dampak['periode_posting'] !== [] ? ' (' . implode(', ', array_map(fn ($periode) => SnapshotJurnal::labelPeriode($periode), $dampak['periode_posting'])) . ')' : '' }}; hasil tarikan tetap disimpan, tahun itu jadi "hanya dicek".</li>
                                @else
                                    <li>Posting yang sudah ada tetap; tahun {{ $dampak['baru'] }}–{{ $dampak['lama'] - 1 }} perlu ditarik &amp; diposting.</li>
                                @endif
                                <li>Isi <b>saldo awal 1 Januari {{ $dampak['baru'] }}</b>, lalu kunci berurutan mulai <b>01/{{ $dampak['baru'] }}</b> — tahun berikutnya baru bisa dikunci setelah tahun sebelumnya terkunci penuh.</li>
                                @if ($dampak['tahun_perlu_dibuka'] !== [])
                                    <li>Tahun yang perlu dibuka: <b>{{ implode(', ', $dampak['tahun_perlu_dibuka']) }}</b> (bagian 2 halaman ini).</li>
                                @endif
                            </ul>
                        </div>
                    @endif
                @endif

                <div>
                    <x-input-label for="alasanSetUlang" value="Alasan *" />
                    <x-text-input id="alasanSetUlang" wire:model="alasanSetUlang" maxlength="300" placeholder="mis. pembukuan SIKEU dimulai dari laporan audit 2023" :error="$errors->has('alasanSetUlang')" class="mt-1" />
                    <x-input-error :messages="$errors->get('alasanSetUlang')" class="mt-1" />
                </div>
                <div class="flex justify-end gap-2">
                    <x-secondary-button type="button" wire:click="tutupSetUlang"><x-ikon nama="batal" /> Batal</x-secondary-button>
                    <x-warning-button type="button" wire:click="setUlang" wire:loading.attr="disabled" wire:target="setUlang" :disabled="!$dampak || !$dampak['boleh']">
                        <x-ikon nama="setujui" /> Set Ulang
                    </x-warning-button>
                </div>
            </div>
        </div>
    </x-modal>
</div>
