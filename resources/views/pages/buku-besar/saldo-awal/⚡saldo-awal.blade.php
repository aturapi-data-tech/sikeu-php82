<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use App\Support\BaganAkun;
use App\Support\BukuBesar;
use App\Support\Format;
use App\Support\Pengaturan;
use App\Support\SaldoAwal;

/**
 * SALDO AWAL — ditetapkan DI SIKEU (bukan dari SIRUS), HANYA untuk tahun pertama SIKEU (pengaturan
 * pembukuan.tahun_mulai). Form berbentuk neraca: semua akun Detail Neraca aktif tampil per kelompok, diisi Debit /
 * Kredit + keterangan dasar angka, jumlah & selisih langsung terlihat → Simpan Draft → Sahkan (wajib seimbang).
 * Tahun sesudahnya: tampil saldo yang diteruskan otomatis dari saldo akhir Desember (baca saja).
 * Logika di App\Support\SaldoAwal. Hak: isi/sahkan = jurnal.posting; tahun pertama & akun laba = akun.kelola.
 */
new class extends Component {
    #[Url(as: 'tahun', except: '')]
    public string $tahun = '';

    /**
     * Isian form: [kunci => ['d' => string, 'k' => string, 'ket' => string]], kunci = kunciIsian(akun_id) — kode akun
     * bertitik tidak boleh langsung jadi jalur wire:model (titik = jalur bersarang).
     */
    public array $isian = [];

    public string $akunPenyeimbang = '';

    /** Pengaturan */
    public string $tahunMulai = '';

    /** Jendela Set Ulang Tahun Pertama */
    public string $tahunBaru = '';
    public string $alasanSetUlang = '';
    public string $akunLabaDitahan = '';
    public string $akunLabaBerjalan = '';

    public function mount(): void
    {
        $this->tahunMulai = (string) SaldoAwal::tahunMulai();
        if (!$this->tahunSah()) {
            $this->tahun = $this->tahunMulai;
        }
        $akunLaba = SaldoAwal::akunLaba();
        $this->akunLabaDitahan = $akunLaba['ditahan'];
        $this->akunLabaBerjalan = $akunLaba['berjalan'];
        $this->muatIsian();
    }

    public static function kunciIsian(string $akunId): string
    {
        return 'a' . bin2hex($akunId);
    }

    public function tahunSah(): bool
    {
        return (bool) preg_match('/^\d{4}$/', $this->tahun) && (int) $this->tahun >= 2000 && (int) $this->tahun <= 2100;
    }

    public function updatedTahun(): void
    {
        $this->tahun = trim($this->tahun);
        $this->segarkan();
        $this->muatIsian();
    }

    public function geserTahun(int $langkah): void
    {
        if (!$this->tahunSah()) {
            return;
        }
        $this->tahun = (string) ((int) $this->tahun + ($langkah < 0 ? -1 : 1));
        $this->updatedTahun();
    }

    private function wajibPosting(): void
    {
        abort_unless(auth()->user()?->can('jurnal.posting'), 403);
    }

    /** 'pertama' = tahun pertama SIKEU (diisi), 'diteruskan' = sesudahnya (otomatis), 'sebelum' = sebelum SIKEU. */
    #[Computed]
    public function mode(): string
    {
        $tahunMulai = SaldoAwal::tahunMulai();

        return match (true) {
            (int) $this->tahun === $tahunMulai => 'pertama',
            (int) $this->tahun > $tahunMulai => 'diteruskan',
            default => 'sebelum',
        };
    }

    #[Computed]
    public function periodeAwal(): ?object
    {
        return $this->tahunSah() ? DB::table('sikeumst_periodes')->where('periode', SaldoAwal::periodeAwal((int) $this->tahun))->first() : null;
    }

    #[Computed]
    public function jurnal(): array
    {
        return $this->tahunSah() ? (new SaldoAwal())->jurnal((int) $this->tahun) : ['draft' => null, 'sah' => null];
    }

    /** Akun Detail Neraca aktif (tanpa akun laba berjalan), urut kelompok lalu kode. */
    #[Computed]
    public function akunList(): array
    {
        return DB::table('sikeumst_akuns as a')->join('sikeumst_akun_kelompoks as k', 'k.kelompok_id', '=', 'a.kelompok_id')
            ->leftJoin('sikeumst_akuns as i', 'i.akun_id', '=', 'a.induk_id')
            ->where('a.tipe', 'D')->where('a.aktif', '1')->where('k.jenis_laporan', 'N')
            ->where('a.akun_id', '<>', Pengaturan::ambil('akun.laba_berjalan'))
            ->orderBy('k.urut')->orderBy('a.akun_id')
            ->get(['a.akun_id', 'a.nama', 'a.saldo_normal', 'k.nama as kelompok', 'a.induk_id', 'i.nama as nama_induk'])
            ->map(fn ($row) => (array) $row)->all();
    }

    #[Computed]
    public function akunNeracaList(): array
    {
        return DB::table('sikeumst_akuns as a')->join('sikeumst_akun_kelompoks as k', 'k.kelompok_id', '=', 'a.kelompok_id')
            ->where('a.tipe', 'D')->where('a.aktif', '1')->where('k.jenis_laporan', 'N')->orderBy('a.akun_id')
            ->pluck('a.nama', 'a.akun_id')->all();
    }

    /** Saldo diteruskan (mode 'diteruskan'). */
    #[Computed]
    public function diteruskan(): array
    {
        return $this->mode === 'diteruskan' ? (new SaldoAwal())->diteruskan((int) $this->tahun) : ['saldo' => [], 'laba_tahun_lalu' => 0.0];
    }

    /** Isi form dari draft (bila ada) atau saldo awal sah. */
    private function muatIsian(): void
    {
        $this->isian = [];
        $this->resetValidation();
        if (!$this->tahunSah() || $this->mode !== 'pertama') {
            return;
        }
        $jurnal = $this->jurnal['draft'] ?? $this->jurnal['sah'];
        $barisList = $jurnal ? (new SaldoAwal())->baris((int) $jurnal->jurnal_id) : [];
        foreach ($barisList as $baris) {
            $this->isian[self::kunciIsian((string) $baris['akun_id'])] = [
                'd' => (float) $baris['debit'] != 0.0 ? Format::uang((float) $baris['debit']) : '',
                'k' => (float) $baris['kredit'] != 0.0 ? Format::uang((float) $baris['kredit']) : '',
                'ket' => (string) ($baris['keterangan'] ?? ''),
            ];
        }
    }

    /** Nilai bertanda per akun dari isian (+ debit, − kredit); null bila ada angka tak terbaca. */
    private function nilaiIsian(): ?array
    {
        $nilaiList = [];
        $valid = true;
        foreach ($this->isian as $kunci => $baris) {
            $akunId = hex2bin(substr((string) $kunci, 1));
            $nilaiSisi = [];
            foreach (['d', 'k'] as $sisi) {
                $teks = trim((string) ($baris[$sisi] ?? ''));
                $nilaiSisi[$sisi] = $teks === '' ? 0.0 : Format::uraiUang($teks);
                if ($nilaiSisi[$sisi] === null) {
                    $this->addError("isian.{$kunci}.{$sisi}", 'Angka tidak terbaca.');
                    $valid = false;
                }
            }
            if ($valid) {
                $nilaiList[$akunId] = ['nilai' => round($nilaiSisi['d'] - $nilaiSisi['k'], 2), 'keterangan' => trim((string) ($baris['ket'] ?? ''))];
            }
        }

        return $valid ? $nilaiList : null;
    }

    /** Jumlah debit/kredit isian (angka tak terbaca dihitung 0) — untuk tampilan langsung. */
    #[Computed]
    public function jumlah(): array
    {
        $debit = 0.0;
        $kredit = 0.0;
        foreach ($this->isian as $baris) {
            $nilai = (Format::uraiUang(trim((string) ($baris['d'] ?? ''))) ?? 0.0) - (Format::uraiUang(trim((string) ($baris['k'] ?? ''))) ?? 0.0);
            $debit += max($nilai, 0);
            $kredit += max(-$nilai, 0);
        }

        return ['debit' => round($debit, 2), 'kredit' => round($kredit, 2), 'selisih' => round($debit - $kredit, 2)];
    }

    private function segarkan(): void
    {
        unset($this->jurnal, $this->periodeAwal, $this->mode, $this->diteruskan, $this->jumlah, $this->akunList);
    }

    private function simpanDraftDiam(): bool
    {
        $this->resetValidation();
        $nilaiList = $this->nilaiIsian();
        if ($nilaiList === null) {
            $this->dispatch('toast', type: 'error', message: 'Ada angka yang tidak terbaca — periksa kotak bertanda merah.');
            return false;
        }
        // akun yang dimuat dari draft lalu dikosongkan tetap ada di $isian → terkirim sebagai 0 (baris dihapus)
        (new SaldoAwal())->simpanIsian((int) $this->tahun, $nilaiList, auth()->id());

        return true;
    }

    public function simpanDraft(): void
    {
        $this->wajibPosting();
        try {
            if ($this->simpanDraftDiam()) {
                $this->dispatch('toast', type: 'success', message: "Draft saldo awal {$this->tahun} disimpan.");
            }
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
    }

    public function sahkan(): void
    {
        $this->wajibPosting();
        try {
            if ($this->simpanDraftDiam()) {
                (new SaldoAwal())->sahkan((int) $this->tahun, auth()->id());
                $this->dispatch('toast', type: 'success', message: "Saldo awal {$this->tahun} disahkan dan langsung dipakai Neraca Saldo & laporan.");
            }
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
        $this->muatIsian();
    }

    public function seimbangkan(): void
    {
        $this->wajibPosting();
        if ($this->akunPenyeimbang === '') {
            $this->dispatch('toast', type: 'error', message: 'Pilih akun penampung selisih dulu.');
            return;
        }
        try {
            if ($this->simpanDraftDiam()) {
                (new SaldoAwal())->seimbangkan((int) $this->tahun, $this->akunPenyeimbang);
                $this->dispatch('toast', type: 'success', message: "Selisih dimasukkan ke {$this->akunPenyeimbang}. Akun ini wajib ditelusuri sampai nol.");
            }
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
        $this->muatIsian();
    }

    public function hapusDraft(): void
    {
        $this->wajibPosting();
        try {
            (new SaldoAwal())->hapusDraft((int) $this->tahun);
            $this->dispatch('toast', type: 'success', message: 'Draft dibuang — form kembali ke saldo awal sah (bila ada).');
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
        $this->muatIsian();
    }

    // ─── Pengaturan ───────────────────────────────────────────────────────────
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
            $this->tahunMulai = (string) SaldoAwal::tahunMulai();
            $this->tahun = $this->tahunMulai;
            $this->dispatch('toast', type: 'success', message: "Tahun pertama SIKEU sekarang {$this->tahunMulai}."
                . ($hasil['saldo_awal'] !== [] ? ' Saldo awal dibatalkan: ' . implode(', ', $hasil['saldo_awal']) . '.' : '')
                . ($hasil['jurnal_dibatalkan'] > 0 ? " {$hasil['jurnal_dibatalkan']} jurnal posting dibatalkan." : '')
                . " Isi saldo awal {$this->tahunMulai} di bawah.");
            $this->tutupSetUlang();
        } catch (\Throwable $exception) {
            $this->addError('alasanSetUlang', $exception->getMessage());
            return; // jangan muatIsian(): resetValidation di dalamnya menghapus pesan ini
        }
        $this->segarkan();
        $this->muatIsian();
    }

    public function tutupSetUlang(): void
    {
        $this->reset(['tahunBaru', 'alasanSetUlang']);
        $this->resetValidation();
        unset($this->dampakSetUlang);
        $this->dispatch('close-modal', name: 'set-ulang-tahun');
    }

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
        $this->dispatch('toast', type: 'success', message: 'Akun laba disimpan.');
        $this->segarkan();
    }
};
?>

<div>

    <x-page-title
        title="Saldo Awal"
        subtitle="Saldo akun per 1 Januari tahun pertama SIKEU — diisi di sini dari neraca penutup / audit, wajib seimbang, lalu disahkan. Tahun berikutnya diteruskan otomatis." />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="px-6 pt-2 pb-6 space-y-4">

            @php
                $periodeBuka = $this->periodeAwal && $this->periodeAwal->status === BukuBesar::STATUS_BUKA;
                $draft = $this->jurnal['draft'];
                $sah = $this->jurnal['sah'];
                $bolehUbah = $this->mode === 'pertama' && $periodeBuka && auth()->user()?->can('jurnal.posting');
                $jumlah = $this->jumlah;
            @endphp

            {{-- TOOLBAR --}}
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <x-input-label for="tahun" value="Tahun Buku" />
                    <div class="flex items-center gap-1 mt-1">
                        <x-secondary-button type="button" wire:click="geserTahun(-1)" class="px-3" aria-label="Tahun sebelumnya"><x-ikon nama="sebelumnya" /></x-secondary-button>
                        <div class="w-24"><x-text-input id="tahun" wire:model.live.debounce.500ms="tahun" inputmode="numeric" maxlength="4" placeholder="yyyy" class="text-center" /></div>
                        <x-secondary-button type="button" wire:click="geserTahun(1)" class="px-3" aria-label="Tahun berikutnya"><x-ikon nama="berikutnya" /></x-secondary-button>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 pb-2">
                    @if ($this->mode === 'pertama')
                        <x-badge variant="info">Tahun pertama SIKEU</x-badge>
                        @if ($draft)
                            <x-badge variant="warning">Draft {{ $draft->no_jurnal }} — belum sah</x-badge>
                        @endif
                        @if ($sah)
                            <x-badge variant="success">Sah: {{ $sah->no_jurnal }} · {{ Format::waktu($sah->diposting_at) }}</x-badge>
                        @elseif (!$draft)
                            <x-badge variant="warning">Belum ada saldo awal</x-badge>
                        @endif
                        @if ($this->periodeAwal && !$periodeBuka)
                            <x-badge variant="gray">Januari dikunci — baca saja</x-badge>
                        @endif
                    @elseif ($this->mode === 'diteruskan')
                        <x-badge variant="success">Diteruskan otomatis dari {{ (int) $tahun - 1 }}</x-badge>
                    @endif
                </div>
            </div>

            @if (!$this->tahunSah())
                <p class="px-6 py-8 text-center text-muted">Ketik tahun 4 angka, mis. {{ $tahunMulai }}.</p>

            @elseif ($this->mode === 'sebelum')
                <div role="status" class="px-4 py-3 text-sm rounded-lg bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200">
                    Tahun {{ $tahun }} sebelum tahun pertama SIKEU ({{ $tahunMulai }}) — tidak ada saldo awal.
                    Bila pembukuan SIKEU memang dimulai lebih awal, ubah <b>Tahun pertama SIKEU</b> di bagian Pengaturan di bawah.
                </div>

            @elseif ($this->mode === 'diteruskan')
                {{-- TAHUN SESUDAHNYA: BACA SAJA --}}
                <div role="status" class="px-4 py-3 text-sm rounded-lg bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200">
                    Saldo awal {{ $tahun }} <b>tidak diisi</b> — sama dengan saldo akhir Desember {{ (int) $tahun - 1 }} di buku besar SIKEU
                    (diteruskan otomatis, angka di bawah ikut berubah bila jurnal {{ (int) $tahun - 1 }} berubah). Koreksi dilakukan lewat
                    <a href="{{ route('buku-besar.jurnal-umum') }}" wire:navigate class="font-semibold underline">Jurnal Umum</a> di tahun yang bersangkutan.
                    @if ($this->diteruskan['laba_tahun_lalu'] != 0.0)
                        <br>Laba tahun lalu Rp {{ Format::uang($this->diteruskan['laba_tahun_lalu']) }} sudah dihitung ke akun laba ditahan ({{ $akunLabaDitahan }}).
                    @endif
                </div>
                <div class="overflow-x-auto bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                    <table class="ds-table ds-table-entri">
                        <thead><tr><th>Akun</th><th>Nama Akun</th><th class="text-right">Debit</th><th class="text-right">Kredit</th></tr></thead>
                        <tbody>
                            @php $totalDiteruskan = ['d' => 0.0, 'k' => 0.0]; @endphp
                            @forelse ($this->akunList as $akun)
                                @php $nilai = (float) ($this->diteruskan['saldo'][$akun['akun_id']] ?? 0); @endphp
                                @continue($nilai == 0.0)
                                @php $totalDiteruskan['d'] += max($nilai, 0); $totalDiteruskan['k'] += max(-$nilai, 0); @endphp
                                <tr wire:key="teruskan-{{ $akun['akun_id'] }}">
                                    <td class="ds-td-token">{{ $akun['akun_id'] }}</td>
                                    <td>{{ $akun['nama'] }}</td>
                                    <td class="text-right tabular-nums">{{ $nilai > 0 ? Format::uang($nilai) : '' }}</td>
                                    <td class="text-right tabular-nums">{{ $nilai < 0 ? Format::uang(-$nilai) : '' }}</td>
                                </tr>
                            @empty
                            @endforelse
                            @if ($totalDiteruskan['d'] == 0.0 && $totalDiteruskan['k'] == 0.0)
                                <tr><td colspan="4" class="px-6 py-6 text-center text-muted">Belum ada saldo — {{ (int) $tahun - 1 }} belum dibukukan.</td></tr>
                            @endif
                        </tbody>
                        <tfoot>
                            <tr class="font-bold bg-surface-card text-ink dark:bg-gray-800 dark:text-gray-100">
                                <td colspan="2" class="px-3 py-3">TOTAL</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ Format::uang($totalDiteruskan['d']) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ Format::uang($totalDiteruskan['k']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

            @elseif (!$this->periodeAwal)
                <div class="p-6 text-center border border-dashed rounded-2xl border-hairline bg-canvas dark:bg-gray-800 dark:border-gray-700">
                    Tahun buku {{ $tahun }} belum dibuka.
                    <a href="{{ route('buku-besar.periode', ['tahun' => $tahun]) }}" wire:navigate class="font-medium underline">Buka di Tarik Data SIRUS</a>.
                </div>

            @else
                {{-- TAHUN PERTAMA: FORM NERACA --}}
                <div class="px-4 py-2 text-xs rounded-lg bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200">
                    Isi saldo tiap akun per <b>1 Januari {{ $tahun }}</b> dari neraca penutup / audit 31 Desember {{ (int) $tahun - 1 }}, rekening koran, dan hasil opname.
                    Laba tahun-tahun lalu masuk akun laba ditahan. Kotak kosong = 0. Simpan draft boleh bertahap; Sahkan wajib seimbang.
                </div>

                <div class="bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                    <div class="overflow-x-auto">
                        <table class="ds-table ds-table-entri">
                            <thead class="sticky z-10 top-20">
                                <tr>
                                    <th class="w-28">Akun</th>
                                    <th>Nama Akun</th>
                                    <th class="w-44 text-right">Debit</th>
                                    <th class="w-44 text-right">Kredit</th>
                                    <th>Keterangan / dasar angka</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $kelompokSebelumnya = null; @endphp
                                @foreach ($this->akunList as $akun)
                                    @php
                                        $akunId = $akun['akun_id'];
                                        $kunci = $this::kunciIsian($akunId);
                                        $nilaiD = Format::uraiUang(trim((string) ($isian[$kunci]['d'] ?? ''))) ?? 0.0;
                                        $nilaiK = Format::uraiUang(trim((string) ($isian[$kunci]['k'] ?? ''))) ?? 0.0;
                                        $bersih = $nilaiD - $nilaiK;
                                        $berlawanan = ($bersih < 0 && $akun['saldo_normal'] === 'D') || ($bersih > 0 && $akun['saldo_normal'] === 'K');
                                    @endphp
                                    @if ($akun['induk_id'] !== $kelompokSebelumnya)
                                        <tr wire:key="kel-{{ $akunId }}" class="bg-surface-card dark:bg-gray-800">
                                            <td colspan="5" class="px-3 py-1.5 text-xs font-semibold text-ink dark:text-gray-100">
                                                <span class="tracking-wide uppercase text-muted dark:text-gray-400">{{ $akun['kelompok'] }} ›</span>
                                                <span class="font-mono">{{ $akun['induk_id'] }}</span> {{ $akun['nama_induk'] }}
                                            </td>
                                        </tr>
                                        @php $kelompokSebelumnya = $akun['induk_id']; @endphp
                                    @endif
                                    <tr wire:key="sa-{{ $akunId }}" class="{{ $berlawanan ? 'bg-danger-soft' : '' }}">
                                        <td class="ds-td-token">{{ $akunId }}</td>
                                        <td class="text-body dark:text-gray-300">
                                            {{ $akun['nama'] }}
                                            @if ($berlawanan)
                                                <span class="inline-flex items-center gap-1 ml-1 text-[11px] text-fg-danger-strong"><x-ikon-peringatan label="Saldo berlawanan dengan saldo normal" /> berlawanan</span>
                                            @endif
                                        </td>
                                        @foreach (['d' => 'Debit', 'k' => 'Kredit'] as $sisi => $labelSisi)
                                            <td>
                                                <x-text-input wire:model.blur="isian.{{ $kunci }}.{{ $sisi }}" inputmode="decimal" :disabled="!$bolehUbah"
                                                    aria-label="{{ $labelSisi }} {{ $akunId }}" :error="$errors->has('isian.' . $kunci . '.' . $sisi)"
                                                    class="text-right tabular-nums" />
                                            </td>
                                        @endforeach
                                        <td><x-text-input wire:model.blur="isian.{{ $kunci }}.ket" maxlength="500" :disabled="!$bolehUbah" aria-label="Keterangan {{ $akunId }}" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- JUMLAH + AKSI (melekat di bawah) --}}
                    <div class="sticky bottom-0 z-10 px-4 py-3 space-y-3 border-t bg-surface-soft border-hairline rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
                        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                            <span>Total Debit <b class="tabular-nums text-ink dark:text-gray-100">{{ Format::uang($jumlah['debit']) }}</b></span>
                            <span>Total Kredit <b class="tabular-nums text-ink dark:text-gray-100">{{ Format::uang($jumlah['kredit']) }}</b></span>
                            @if ($jumlah['selisih'] != 0.0)
                                <x-badge variant="danger">Selisih {{ Format::uang(abs($jumlah['selisih'])) }} {{ $jumlah['selisih'] > 0 ? '(D lebih)' : '(K lebih)' }}</x-badge>
                            @elseif ($jumlah['debit'] > 0)
                                <x-badge variant="success">Seimbang</x-badge>
                            @endif
                            @if ($bolehUbah)
                                <div class="flex flex-wrap gap-2 ml-auto">
                                    @if ($draft)
                                        <x-confirm-button variant="danger-soft" action="hapusDraft()" title="Buang Draft"
                                            message="Perubahan yang belum disahkan dibuang; form kembali ke saldo awal sah (bila ada)."><x-ikon nama="hapus" /> Buang Draft</x-confirm-button>
                                    @endif
                                    <x-secondary-button type="button" wire:click="simpanDraft" wire:loading.attr="disabled" wire:target="simpanDraft"><x-ikon nama="simpan" /> Simpan Draft</x-secondary-button>
                                    <x-confirm-button variant="primary" action="sahkan()" :disabled="$jumlah['selisih'] != 0.0 || $jumlah['debit'] == 0.0"
                                        title="Sahkan Saldo Awal {{ $tahun }}"
                                        message="Isian disimpan lalu menjadi saldo awal resmi {{ $tahun }} — langsung dipakai Neraca Saldo & laporan. Saldo awal sah sebelumnya dibatalkan.">
                                        <x-ikon nama="setujui" /> Sahkan
                                    </x-confirm-button>
                                </div>
                            @endif
                        </div>
                        @if ($bolehUbah && $jumlah['selisih'] != 0.0)
                            <div class="flex flex-col gap-2 px-3 py-2 text-sm rounded-lg bg-danger-soft sm:flex-row sm:items-end">
                                <p class="flex-1 text-fg-danger-strong">Belum seimbang. Periksa angkanya dulu; bila selisih memang belum bisa ditelusuri, pilih akun penampungnya — tidak diseimbangkan otomatis supaya tetap terlihat.</p>
                                <div class="sm:w-80">
                                    <x-select-input wire:model="akunPenyeimbang" aria-label="Akun penampung selisih">
                                        <option value="">- pilih akun penampung -</option>
                                        @foreach ($this->akunNeracaList as $akunId => $namaAkun)
                                            <option value="{{ $akunId }}">{{ $akunId }} {{ $namaAkun }}</option>
                                        @endforeach
                                    </x-select-input>
                                </div>
                                <x-warning-button type="button" wire:click="seimbangkan" wire:loading.attr="disabled" wire:target="seimbangkan"><x-ikon nama="proses" /> Tampung Selisih</x-warning-button>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- PENGATURAN --}}
            <x-border-form title="Pengaturan">
                <div class="grid items-end grid-cols-1 gap-3 md:grid-cols-4">
                    <div>
                        <x-input-label value="Tahun pertama SIKEU" />
                        <div class="flex items-center gap-2 mt-1">
                            <span class="px-3 py-2 font-mono text-base font-semibold border rounded-lg border-hairline text-ink dark:text-gray-100 dark:border-gray-700">{{ $tahunMulai }}</span>
                            @can('tutupBuku.jalankan')
                                <x-warning-button type="button" wire:click="bukaSetUlang"><x-ikon nama="muat-ulang" /> Set Ulang</x-warning-button>
                            @endcan
                        </div>
                    </div>
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
                        <div class="flex flex-wrap gap-2">
                            <x-secondary-button type="button" wire:click="simpanAkunLaba" wire:loading.attr="disabled" wire:target="simpanAkunLaba"><x-ikon nama="simpan" /> Simpan Akun Laba</x-secondary-button>
                        </div>
                    @endcan
                </div>
                <p class="mt-2 text-[11px] text-muted dark:text-gray-400">Saldo awal hanya diisi untuk tahun pertama SIKEU; tahun berikutnya diteruskan otomatis. Set Ulang memindah tahun pertama (mis. mulai dari 2024) — dampaknya ditampilkan dulu.</p>
            </x-border-form>

            {{-- JENDELA SET ULANG TAHUN PERTAMA --}}
            <x-modal name="set-ulang-tahun" size="2xl">
                <div class="flex flex-col">
                    <x-modul-dokumen.header judul="Set Ulang Tahun Pertama SIKEU"
                        ikon="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" tutupAlpine="$wire.tutupSetUlang()">
                        Sekarang {{ $tahunMulai }}. Pembukuan dimulai dari tahun baru: saldo awal diisi di tahun itu, lalu tiap tahun dikunci berurutan.
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
                                    <p class="font-semibold text-ink dark:text-gray-100">
                                        {{ $dampak['arah'] === 'mundur' ? 'Mundur' : 'Maju' }}: {{ $dampak['lama'] }} → {{ $dampak['baru'] }}. Yang terjadi:
                                    </p>
                                    <ul class="pl-5 space-y-1 list-disc">
                                        @if ($dampak['saldo_awal'] !== [])
                                            <li>Saldo awal <b>{{ implode(', ', $dampak['saldo_awal']) }}</b> dibatalkan{{ $dampak['arah'] === 'mundur' ? ' — tahun itu nanti diteruskan dari tahun sebelumnya' : '' }}.</li>
                                        @endif
                                        @if ($dampak['arah'] === 'maju')
                                            <li>{{ count($dampak['periode_posting']) }} bulan terposting sebelum {{ $dampak['baru'] }} dibatalkan postingnya{{ $dampak['periode_posting'] !== [] ? ' (' . implode(', ', array_map(fn ($periode) => \App\Support\SnapshotJurnal::labelPeriode($periode), $dampak['periode_posting'])) . ')' : '' }}; hasil tarikan tetap disimpan, tahun itu jadi "hanya dicek".</li>
                                        @else
                                            <li>Posting yang sudah ada tetap; tahun {{ $dampak['baru'] }}–{{ $dampak['lama'] - 1 }} perlu ditarik &amp; diposting.</li>
                                        @endif
                                        <li>Isi <b>saldo awal 1 Januari {{ $dampak['baru'] }}</b> di halaman ini, lalu kunci berurutan mulai <b>01/{{ $dampak['baru'] }}</b> — tahun berikutnya baru bisa dikunci setelah tahun sebelumnya terkunci penuh.</li>
                                        @if ($dampak['tahun_perlu_dibuka'] !== [])
                                            <li>Tahun yang perlu dibuka di Tarik Data SIRUS: <b>{{ implode(', ', $dampak['tahun_perlu_dibuka']) }}</b>.</li>
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
    </div>
</div>
