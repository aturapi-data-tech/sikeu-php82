<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use App\Support\BukuBesar;
use App\Support\Format;
use App\Support\Pengaturan;
use App\Support\SaldoAwal;

/**
 * SALDO AWAL — ditetapkan DI SIKEU (bukan dari SIRUS), HANYA untuk tahun pertama SIKEU (pengaturan
 * pembukuan.tahun_mulai). Form berbentuk neraca: semua akun Detail Neraca aktif tampil per kelompok, diisi Debit /
 * Kredit + keterangan dasar angka, jumlah & selisih langsung terlihat → Simpan Draft → Sahkan (wajib seimbang).
 * Tahun sesudahnya: tampil saldo yang diteruskan otomatis dari saldo akhir Desember (baca saja).
 * Logika di App\Support\SaldoAwal. Hak: isi/sahkan = jurnal.posting. Tahun pertama & akun laba: Pengaturan Pembukuan.
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

    /** Tahun pertama SIKEU — diatur di Pembukuan › Pengaturan Pembukuan */
    public string $tahunMulai = '';

    public function mount(): void
    {
        $this->tahunMulai = (string) SaldoAwal::tahunMulai();
        if (!$this->tahunSah()) {
            $this->tahun = $this->tahunMulai;
        }
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
                'd' => (float) $baris['debit'] != 0.0 ? (float) $baris['debit'] : '',
                'k' => (float) $baris['kredit'] != 0.0 ? (float) $baris['kredit'] : '',
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
                $nilaiSisi[$sisi] = Format::angka($baris[$sisi] ?? '');
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
            $nilai = (Format::angka($baris['d'] ?? '') ?? 0.0) - (Format::angka($baris['k'] ?? '') ?? 0.0);
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
                    Bila pembukuan SIKEU memang dimulai lebih awal, Set Ulang tahun pertama di
                    <a href="{{ route('buku-besar.pengaturan') }}" wire:navigate class="font-semibold underline">Pengaturan Pembukuan</a>.
                </div>

            @elseif ($this->mode === 'diteruskan')
                {{-- TAHUN SESUDAHNYA: BACA SAJA --}}
                <div role="status" class="px-4 py-3 text-sm rounded-lg bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200">
                    Saldo awal {{ $tahun }} <b>tidak diisi</b> — sama dengan saldo akhir Desember {{ (int) $tahun - 1 }} di buku besar SIKEU
                    (diteruskan otomatis, angka di bawah ikut berubah bila jurnal {{ (int) $tahun - 1 }} berubah). Koreksi dilakukan lewat
                    <a href="{{ route('buku-besar.jurnal-umum') }}" wire:navigate class="font-semibold underline">Jurnal Umum</a> di tahun yang bersangkutan.
                    @if ($this->diteruskan['laba_tahun_lalu'] != 0.0)
                        <br>Laba tahun lalu Rp {{ Format::uang($this->diteruskan['laba_tahun_lalu']) }} sudah dihitung ke akun laba ditahan ({{ SaldoAwal::akunLaba()['ditahan'] }}).
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
                    <a href="{{ route('buku-besar.pengaturan') }}" wire:navigate class="font-medium underline">Buka di Pengaturan Pembukuan</a>.
                </div>

            @else
                {{-- TAHUN PERTAMA: FORM NERACA --}}
                <div class="px-4 py-2 text-xs rounded-lg bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200">
                    Isi saldo tiap akun per <b>1 Januari {{ $tahun }}</b> dari neraca penutup / audit 31 Desember {{ (int) $tahun - 1 }}, rekening koran, dan hasil opname.
                    Laba tahun-tahun lalu masuk akun laba ditahan. Kotak kosong = 0. Simpan draft boleh bertahap; Sahkan wajib seimbang.
                </div>

                <div class="bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                    {{-- Tabel bergulir di dalam kartu: judul kolom melekat di atas, jumlah & tombol melekat di bawah --}}
                    <div class="overflow-auto rounded-t-2xl max-h-[calc(100vh-19rem)]">
                        <table class="ds-table ds-table-entri">
                            <thead class="sticky top-0 z-10 shadow-[0_1px_0_0_var(--hairline)]">
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
                                        $nilaiD = Format::angka($isian[$kunci]['d'] ?? '') ?? 0.0;
                                        $nilaiK = Format::angka($isian[$kunci]['k'] ?? '') ?? 0.0;
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
                                                <span class="inline-flex items-center gap-1 ml-1 text-xs text-fg-danger-strong"><x-ikon-peringatan label="Saldo berlawanan dengan saldo normal" /> berlawanan</span>
                                            @endif
                                        </td>
                                        @foreach (['d' => 'Debit', 'k' => 'Kredit'] as $sisi => $labelSisi)
                                            <td>
                                                <x-text-input-number wire:model="isian.{{ $kunci }}.{{ $sisi }}" :decimals="2" uang :disabled="!$bolehUbah"
                                                    aria-label="{{ $labelSisi }} {{ $akunId }}" :error="$errors->has('isian.' . $kunci . '.' . $sisi)" />
                                            </td>
                                        @endforeach
                                        <td><x-text-input wire:model.blur="isian.{{ $kunci }}.ket" maxlength="500" :disabled="!$bolehUbah" aria-label="Keterangan {{ $akunId }}" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- JUMLAH + AKSI (melekat di bawah) --}}
                    <div class="px-4 py-3 space-y-3 border-t bg-surface-soft border-hairline rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
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
        </div>
    </div>
</div>
