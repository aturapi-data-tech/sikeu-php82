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
 * SALDO AWAL — tarik saldo awal tahun dari SIRUS (lewat peta akun) jadi draft, koreksi, seimbangkan ke akun
 * pilihan pemakai, lalu sahkan. Logika di App\Support\SaldoAwal. Hak: ubah/sahkan = jurnal.posting,
 * akun laba ditahan/berjalan (pengaturan) = akun.kelola.
 */
new class extends Component {
    #[Url(as: 'tahun', except: '')]
    public string $tahun = '';

    /** Modal baris */
    public string $barisAkunId = '';
    public string $barisPosisi = 'D';
    public string $barisNominal = '';
    public string $barisKeterangan = '';
    public bool $barisBaru = false;

    public string $akunPenyeimbang = '';

    /** Pengaturan akun laba */
    public string $akunLabaDitahan = '';
    public string $akunLabaBerjalan = '';

    public function mount(): void
    {
        if (!preg_match('/^\d{4}$/', $this->tahun)) {
            $this->tahun = (string) (DB::table('sikeumst_tahun_bukus')->min('tahun') ?? now()->format('Y'));
        }
        $akunLaba = SaldoAwal::akunLaba();
        $this->akunLabaDitahan = $akunLaba['ditahan'];
        $this->akunLabaBerjalan = $akunLaba['berjalan'];
    }

    private function wajibPosting(): void
    {
        abort_unless(auth()->user()?->can('jurnal.posting'), 403);
    }

    #[Computed]
    public function tahunList(): array
    {
        return DB::table('sikeumst_tahun_bukus')->orderBy('tahun')->pluck('tahun')->push($this->tahun)
            ->map(fn ($tahun) => (string) $tahun)->unique()->sort()->values()->all();
    }

    #[Computed]
    public function periodeAwal(): ?object
    {
        return DB::table('sikeumst_periodes')->where('periode', SaldoAwal::periodeAwal((int) $this->tahun))->first();
    }

    #[Computed]
    public function jurnal(): array
    {
        return (new SaldoAwal())->jurnal((int) $this->tahun);
    }

    #[Computed]
    public function barisDraft(): array
    {
        return $this->jurnal['draft'] ? (new SaldoAwal())->baris((int) $this->jurnal['draft']->jurnal_id) : [];
    }

    #[Computed]
    public function barisSah(): array
    {
        return $this->jurnal['sah'] ? (new SaldoAwal())->baris((int) $this->jurnal['sah']->jurnal_id) : [];
    }

    /** Tahun sebelumnya sudah punya jurnal di SIKEU → saldo awal seharusnya dari tutup buku, bukan SIRUS. */
    #[Computed]
    public function tahunLaluAdaJurnal(): bool
    {
        return DB::table('sikeutxn_jurnal_hdrs')->where('status', 'P')->where('periode', 'like', ((int) $this->tahun - 1) . '%')->exists();
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
        unset($this->jurnal, $this->barisDraft, $this->barisSah, $this->periodeAwal);
    }

    private function jalankan(callable $aksi, string $pesanSukses): void
    {
        try {
            $aksi();
            $this->dispatch('toast', type: 'success', message: $pesanSukses);
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
    }

    // ─── Aksi draft ───────────────────────────────────────────────────────────
    public function tarikDariSirus(): void
    {
        $this->wajibPosting();
        try {
            $hasil = (new SaldoAwal())->tarikDariSirus((int) $this->tahun, auth()->id());
            $this->dispatch('toast', type: 'success', message: 'Draft saldo awal ditarik dari SIRUS (' . count($hasil['baris']) . ' akun).'
                . ($hasil['dipindah'] !== [] ? ' Dipindah ke laba ditahan: ' . implode('; ', $hasil['dipindah']) . '.' : ''));
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
    }

    public function draftKosong(): void
    {
        $this->wajibPosting();
        $this->jalankan(fn () => (new SaldoAwal())->buatDraftKosong((int) $this->tahun, auth()->id()), 'Draft kosong dibuat.');
    }

    public function koreksiDariSah(): void
    {
        $this->wajibPosting();
        $this->jalankan(fn () => (new SaldoAwal())->draftDariSah((int) $this->tahun, auth()->id()), 'Draft koreksi disalin dari saldo awal yang sah.');
    }

    public function hapusDraft(): void
    {
        $this->wajibPosting();
        $this->jalankan(fn () => (new SaldoAwal())->hapusDraft((int) $this->tahun), 'Draft dihapus.');
    }

    public function seimbangkan(): void
    {
        $this->wajibPosting();
        if ($this->akunPenyeimbang === '') {
            $this->dispatch('toast', type: 'error', message: 'Pilih akun penyeimbang dulu.');
            return;
        }
        $this->jalankan(fn () => (new SaldoAwal())->seimbangkan((int) $this->tahun, $this->akunPenyeimbang),
            "Selisih dimasukkan ke {$this->akunPenyeimbang}. Akun ini wajib ditelusuri sampai nol.");
    }

    public function sahkan(): void
    {
        $this->wajibPosting();
        $this->jalankan(fn () => (new SaldoAwal())->sahkan((int) $this->tahun, auth()->id()), "Saldo awal {$this->tahun} disahkan.");
    }

    // ─── Modal baris ──────────────────────────────────────────────────────────
    public function bukaBaris(?string $akunId = null): void
    {
        $this->wajibPosting();
        $this->resetValidation();
        $baris = $akunId ? collect($this->barisDraft)->firstWhere('akun_id', $akunId) : null;
        $this->barisBaru = $baris === null;
        $this->barisAkunId = $baris['akun_id'] ?? '';
        $this->barisPosisi = $baris && (float) $baris['kredit'] > 0 ? 'K' : 'D';
        $nilai = $baris ? max((float) $baris['debit'], (float) $baris['kredit']) : 0;
        $this->barisNominal = $baris ? Format::uang($nilai) : '';
        $this->barisKeterangan = (string) ($baris['keterangan'] ?? '');
        $this->dispatch('open-modal', name: 'baris-saldo-awal');
    }

    /** "1.234.567,89" / "1234567.89" / "1234567" → float; selain itu null. */
    private function uraiNominal(string $teks): ?float
    {
        $teks = str_replace(' ', '', trim($teks));
        if (str_contains($teks, ',')) {
            $teks = str_replace(',', '.', str_replace('.', '', $teks));
        } elseif (substr_count($teks, '.') > 1) {
            $teks = str_replace('.', '', $teks);
        }

        return is_numeric($teks) && (float) $teks >= 0 ? round((float) $teks, 2) : null;
    }

    public function simpanBaris(): void
    {
        $this->wajibPosting();
        $this->resetValidation();
        $nominal = $this->uraiNominal($this->barisNominal);
        if ($this->barisAkunId === '') {
            $this->addError('barisAkunId', 'Pilih akun.');
        }
        if ($nominal === null) {
            $this->addError('barisNominal', 'Nominal harus angka ≥ 0, mis. 1.250.000 atau 1.250.000,50.');
        }
        if (!in_array($this->barisPosisi, ['D', 'K'], true)) {
            $this->addError('barisPosisi', 'Posisi harus Debit atau Kredit.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        try {
            (new SaldoAwal())->aturBaris((int) $this->tahun, $this->barisAkunId, $this->barisPosisi === 'D' ? $nominal : -$nominal,
                trim($this->barisKeterangan) !== '' ? trim($this->barisKeterangan) : null);
            $this->dispatch('toast', type: 'success', message: $nominal == 0.0 ? "Baris {$this->barisAkunId} dihapus." : "Baris {$this->barisAkunId} disimpan.");
            $this->tutupBaris();
        } catch (\Throwable $exception) {
            $this->addError('barisNominal', $exception->getMessage());
        }
        $this->segarkan();
    }

    public function hapusBaris(string $akunId): void
    {
        $this->wajibPosting();
        $this->jalankan(fn () => (new SaldoAwal())->aturBaris((int) $this->tahun, $akunId, 0), "Baris {$akunId} dihapus.");
    }

    public function tutupBaris(): void
    {
        $this->reset(['barisAkunId', 'barisPosisi', 'barisNominal', 'barisKeterangan', 'barisBaru']);
        $this->resetValidation();
        $this->dispatch('close-modal', name: 'baris-saldo-awal');
    }

    // ─── Pengaturan akun laba ─────────────────────────────────────────────────
    public function simpanAkunLaba(): void
    {
        abort_unless(auth()->user()?->can('akun.kelola'), 403);
        foreach (['akunLabaDitahan' => $this->akunLabaDitahan, 'akunLabaBerjalan' => $this->akunLabaBerjalan] as $properti => $akunId) {
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
    }

    public function teksNilai(float $nilai): string
    {
        return $nilai == 0.0 ? '' : Format::uang($nilai);
    }
};
?>

<div>

    <x-page-title
        title="Saldo Awal"
        subtitle="Saldo akun per 1 Januari — ditarik dari SIRUS lewat peta akun, dikoreksi, diseimbangkan, lalu disahkan" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="px-6 pt-2 pb-6 space-y-4">

            @php
                $periodeBuka = $this->periodeAwal && $this->periodeAwal->status === BukuBesar::STATUS_BUKA;
                $draft = $this->jurnal['draft'];
                $sah = $this->jurnal['sah'];
                $bolehUbah = $periodeBuka && auth()->user()?->can('jurnal.posting');
            @endphp

            {{-- TOOLBAR --}}
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="flex items-end gap-3">
                    <div class="w-32">
                        <x-input-label for="tahun" value="Tahun Buku" />
                        <x-select-input id="tahun" wire:model.live="tahun" class="mt-1">
                            @foreach ($this->tahunList as $pilihanTahun)
                                <option value="{{ $pilihanTahun }}">{{ $pilihanTahun }}</option>
                            @endforeach
                        </x-select-input>
                    </div>
                    @if ($sah)
                        <x-badge variant="success" class="mb-2">Sah: {{ $sah->no_jurnal }} · {{ Format::waktu($sah->diposting_at) }}</x-badge>
                    @else
                        <x-badge variant="warning" class="mb-2">Belum ada saldo awal sah</x-badge>
                    @endif
                    @if ($this->periodeAwal && !$periodeBuka)
                        <x-badge variant="gray" class="mb-2">Januari dikunci — baca saja</x-badge>
                    @endif
                </div>
                @if ($bolehUbah)
                    <div class="flex flex-wrap gap-2">
                        <x-confirm-button variant="primary" action="tarikDariSirus()" title="Tarik Saldo Awal {{ $tahun }} dari SIRUS"
                            message="Saldo awal SIRUS (tktxn_saldoawalakuns) diubah lewat peta akun jadi DRAFT baru. Draft yang ada sekarang diganti; saldo awal sah tetap berlaku sampai draft baru disahkan.">
                            Tarik dari SIRUS
                        </x-confirm-button>
                        @if (!$draft && $sah)
                            <x-secondary-button type="button" wire:click="koreksiDariSah" wire:loading.attr="disabled" wire:target="koreksiDariSah">Koreksi Saldo Sah</x-secondary-button>
                        @endif
                        @if (!$draft)
                            <x-secondary-button type="button" wire:click="draftKosong" wire:loading.attr="disabled" wire:target="draftKosong">Draft Kosong</x-secondary-button>
                        @endif
                    </div>
                @endif
            </div>

            @if (!$this->periodeAwal)
                <div class="p-6 text-center border border-dashed rounded-2xl border-hairline bg-canvas dark:bg-gray-800 dark:border-gray-700">
                    Tahun buku {{ $tahun }} belum dibuka.
                    <a href="{{ route('buku-besar.periode', ['tahun' => $tahun]) }}" wire:navigate class="font-medium underline">Buka di Periode & Posting</a>.
                </div>
            @else
                @if ($this->tahunLaluAdaJurnal)
                    <div role="alert" class="px-4 py-2 text-sm rounded-lg bg-warning-soft text-fg-warning">
                        Tahun {{ (int) $tahun - 1 }} sudah punya jurnal di SIKEU — saldo awal {{ $tahun }} seharusnya hasil tutup buku tahun itu, bukan dari SIRUS.
                    </div>
                @endif

                {{-- PENGATURAN AKUN LABA --}}
                <x-border-form title="Akun Saldo Laba">
                    <div class="grid items-end grid-cols-1 gap-3 md:grid-cols-3">
                        @foreach (['akunLabaDitahan' => 'Laba ditahan (tujuan saldo laba tahun lalu)', 'akunLabaBerjalan' => 'Laba tahun berjalan (nol per 1 Januari)'] as $properti => $judul)
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
                            <div><x-secondary-button type="button" wire:click="simpanAkunLaba" wire:loading.attr="disabled" wire:target="simpanAkunLaba">Simpan Akun Laba</x-secondary-button></div>
                        @endcan
                    </div>
                    <p class="mt-2 text-[11px] text-muted dark:text-gray-400">Saldo awal akun Laba Rugi dan akun laba berjalan dari sistem lama otomatis dipindah ke akun laba ditahan.</p>
                </x-border-form>

                {{-- DRAFT --}}
                @if ($draft)
                    @php $selisih = round((float) $draft->total_debit - (float) $draft->total_kredit, 2); @endphp
                    <div class="bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex flex-col gap-3 px-4 py-3 border-b border-hairline dark:border-gray-700 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p class="font-semibold text-ink dark:text-gray-100">Draft {{ $draft->no_jurnal }} <x-badge variant="warning">belum sah</x-badge></p>
                                <p class="text-xs text-muted dark:text-gray-400">{{ $draft->keterangan }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($selisih != 0.0)
                                    <x-badge variant="danger">Selisih {{ Format::uang(abs($selisih)) }} {{ $selisih > 0 ? '(D lebih)' : '(K lebih)' }}</x-badge>
                                @else
                                    <x-badge variant="success">Seimbang</x-badge>
                                @endif
                                @if ($bolehUbah)
                                    <x-secondary-button type="button" wire:click="bukaBaris">+ Baris</x-secondary-button>
                                    <x-confirm-button variant="danger-soft" action="hapusDraft()" title="Hapus Draft" message="Draft saldo awal dibuang. Saldo awal sah (bila ada) tetap berlaku.">Hapus Draft</x-confirm-button>
                                    <x-confirm-button variant="primary" action="sahkan()" :disabled="$selisih != 0.0"
                                        title="Sahkan Saldo Awal {{ $tahun }}"
                                        message="Draft menjadi saldo awal resmi {{ $tahun }} dan langsung dipakai Neraca Saldo & Buku Besar. Saldo awal sah sebelumnya dibatalkan.">
                                        Sahkan
                                    </x-confirm-button>
                                @endif
                            </div>
                        </div>

                        @if ($selisih != 0.0 && $bolehUbah)
                            <div class="flex flex-col gap-2 px-4 py-3 text-sm border-b bg-danger-soft border-hairline sm:flex-row sm:items-end dark:border-gray-700">
                                <div class="flex-1">
                                    <p class="text-fg-danger-strong">Saldo awal tidak seimbang. Pilih akun penampung selisihnya — selisih <b>tidak</b> diseimbangkan otomatis supaya tetap terlihat & ditelusuri.</p>
                                    <x-select-input wire:model="akunPenyeimbang" aria-label="Akun penyeimbang" class="mt-2">
                                        <option value="">- pilih akun penyeimbang -</option>
                                        @foreach ($this->akunNeracaList as $akunId => $namaAkun)
                                            <option value="{{ $akunId }}">{{ $akunId }} {{ $namaAkun }}</option>
                                        @endforeach
                                    </x-select-input>
                                </div>
                                <x-warning-button type="button" wire:click="seimbangkan" wire:loading.attr="disabled" wire:target="seimbangkan">Seimbangkan ke akun ini</x-warning-button>
                            </div>
                        @endif

                        @include('pages.buku-besar.saldo-awal.tabel-baris', ['barisList' => $this->barisDraft, 'bolehUbah' => $bolehUbah, 'total' => $draft])
                    </div>
                @endif

                {{-- SAH --}}
                @if ($sah)
                    <div class="bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                        <div class="px-4 py-3 border-b border-hairline dark:border-gray-700">
                            <p class="font-semibold text-ink dark:text-gray-100">Saldo Awal Sah {{ $sah->no_jurnal }}</p>
                            <p class="text-xs text-muted dark:text-gray-400">{{ $sah->keterangan }}</p>
                        </div>
                        @include('pages.buku-besar.saldo-awal.tabel-baris', ['barisList' => $this->barisSah, 'bolehUbah' => false, 'total' => $sah])
                    </div>
                @endif

                @if (!$draft && !$sah)
                    <p class="px-6 py-8 text-center text-muted dark:text-gray-400">Belum ada saldo awal {{ $tahun }}. Tarik dari SIRUS untuk memulai.</p>
                @endif
            @endif
        </div>
    </div>

    {{-- MODAL BARIS --}}
    <x-modal name="baris-saldo-awal" size="2xl" focusable>
        <div class="p-1 space-y-4">
            <h2 class="text-lg font-semibold text-ink dark:text-gray-100">{{ $barisBaru ? 'Tambah Baris Saldo Awal' : 'Ubah Saldo Awal ' . $barisAkunId }}</h2>
            <div>
                <x-input-label for="barisAkunId" value="Akun *" />
                @if ($barisBaru)
                    <x-select-input id="barisAkunId" wire:model="barisAkunId" :error="$errors->has('barisAkunId')" class="mt-1">
                        <option value="">- pilih akun Neraca -</option>
                        @foreach ($this->akunNeracaList as $akunId => $namaAkun)
                            <option value="{{ $akunId }}">{{ $akunId }} {{ $namaAkun }}</option>
                        @endforeach
                    </x-select-input>
                @else
                    <x-text-input id="barisAkunId" :value="$barisAkunId . ' ' . ($this->akunNeracaList[$barisAkunId] ?? '')" disabled class="mt-1" />
                @endif
                <x-input-error :messages="$errors->get('barisAkunId')" class="mt-1" />
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <x-input-label for="barisPosisi" value="Posisi *" />
                    <x-select-input id="barisPosisi" wire:model="barisPosisi" class="mt-1">
                        <option value="D">Debit</option>
                        <option value="K">Kredit</option>
                    </x-select-input>
                </div>
                <div class="col-span-2">
                    <x-input-label for="barisNominal" value="Nominal *" />
                    <x-text-input id="barisNominal" wire:model="barisNominal" inputmode="decimal" placeholder="1.250.000,00" :error="$errors->has('barisNominal')"
                        class="mt-1 text-right tabular-nums" x-on:keydown.enter.prevent="$wire.simpanBaris()" />
                    <x-input-error :messages="$errors->get('barisNominal')" class="mt-1" />
                    <p class="mt-1 text-[11px] text-muted dark:text-gray-400">Isi 0 untuk menghapus baris.</p>
                </div>
            </div>
            <div>
                <x-input-label for="barisKeterangan" value="Keterangan / dasar angka" />
                <x-text-input id="barisKeterangan" wire:model="barisKeterangan" maxlength="500" placeholder="mis. rekening koran 31/12, hasil opname 31/12" class="mt-1" />
            </div>
            <div class="flex justify-end gap-2">
                <x-secondary-button type="button" wire:click="tutupBaris">Batal</x-secondary-button>
                <x-primary-button type="button" wire:click="simpanBaris" wire:loading.attr="disabled" wire:target="simpanBaris">Simpan</x-primary-button>
            </div>
        </div>
    </x-modal>
</div>
