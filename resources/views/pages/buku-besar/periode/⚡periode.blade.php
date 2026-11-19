<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use App\Support\BukuBesar;
use App\Support\Format;
use App\Support\SnapshotJurnal;

/**
 * PERIODE & POSTING — tahun buku, 12 periode, sahkan versi snapshot SIRUS + posting ke buku besar, kunci / buka kunci.
 * Logika di App\Support\BukuBesar. Hak: lihat = laporan.lihat (menu), posting = jurnal.posting, buka tahun & kunci = periode.kunci.
 */
new class extends Component {
    #[Url(as: 'tahun', except: '')]
    public string $tahun = '';

    /** Modal posting */
    public string $periodePosting = '';
    public ?int $snapIdPilih = null;

    /** Modal buka kunci */
    public string $periodeBuka = '';
    public string $alasanBuka = '';

    public function mount(): void
    {
        if (!preg_match('/^\d{4}$/', $this->tahun)) {
            $this->tahun = (string) (DB::table('sikeumst_tahun_bukus')->max('tahun') ?? now()->format('Y'));
        }
    }

    #[Computed]
    public function tahunAda(): bool
    {
        return DB::table('sikeumst_tahun_bukus')->where('tahun', (int) $this->tahun)->exists();
    }

    #[Computed]
    public function tahunList(): array
    {
        $tahunList = DB::table('sikeumst_tahun_bukus')->orderBy('tahun')->pluck('tahun')->map(fn ($tahun) => (string) $tahun)->all();
        $tahunSnapshot = DB::table('sikeutxn_snap_hdrs')->selectRaw('DISTINCT SUBSTR(periode, 1, 4) AS tahun')->pluck('tahun')->all();

        return collect(array_merge($tahunList, $tahunSnapshot, [now()->format('Y'), $this->tahun]))->unique()->sort()->values()->all();
    }

    #[Computed]
    public function periodeList(): array
    {
        return (new BukuBesar())->periodeList((int) $this->tahun);
    }

    private function segarkan(): void
    {
        unset($this->periodeList, $this->tahunAda, $this->tahunList);
    }

    public function bukaTahun(): void
    {
        abort_unless(auth()->user()?->can('periode.kunci'), 403);
        try {
            (new BukuBesar())->bukaTahun((int) $this->tahun, auth()->id());
            $this->dispatch('toast', type: 'success', message: "Tahun buku {$this->tahun} dibuka (12 periode).");
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
    }

    // ─── Posting ──────────────────────────────────────────────────────────────
    public function bukaPosting(string $periode): void
    {
        abort_unless(auth()->user()?->can('jurnal.posting'), 403);
        $baris = collect($this->periodeList)->firstWhere('periode', $periode);
        if (!$baris || $baris['versi_list'] === []) {
            $this->dispatch('toast', type: 'error', message: 'Belum ada snapshot selesai untuk periode ini — ambil dulu di Cutoff Jurnal › Snapshot Jurnal.');
            return;
        }
        $this->periodePosting = $periode;
        $this->snapIdPilih = (int) end($baris['versi_list'])['snap_id'];
        $this->dispatch('open-modal', name: 'posting-periode');
    }

    #[Computed]
    public function akunBelumSiap(): array
    {
        return $this->snapIdPilih ? (new BukuBesar())->akunBelumSiap($this->snapIdPilih) : [];
    }

    public function sahkanPosting(): void
    {
        abort_unless(auth()->user()?->can('jurnal.posting'), 403);
        if (!$this->snapIdPilih) {
            return;
        }
        try {
            $hasil = (new BukuBesar())->sahkanDanPosting($this->snapIdPilih, auth()->id());
            $this->dispatch('toast', type: 'success', message: "Diposting: {$hasil['jurnal']} jurnal, {$hasil['baris']} baris, D = K Rp " . Format::uang($hasil['debit'])
                . ($hasil['dibatalkan'] > 0 ? " ({$hasil['dibatalkan']} jurnal posting lama dibatalkan)." : '.'));
            $this->tutupPosting();
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
    }

    public function tutupPosting(): void
    {
        $this->reset(['periodePosting', 'snapIdPilih']);
        unset($this->akunBelumSiap);
        $this->dispatch('close-modal', name: 'posting-periode');
    }

    // ─── Kunci / buka kunci ───────────────────────────────────────────────────
    public function kunci(string $periode): void
    {
        abort_unless(auth()->user()?->can('periode.kunci'), 403);
        try {
            (new BukuBesar())->kunci($periode, auth()->id());
            $this->dispatch('toast', type: 'success', message: 'Periode ' . SnapshotJurnal::labelPeriode($periode) . ' dikunci.');
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
    }

    public function bukaFormBukaKunci(string $periode): void
    {
        abort_unless(auth()->user()?->can('periode.kunci'), 403);
        $this->periodeBuka = $periode;
        $this->alasanBuka = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'buka-kunci-periode');
    }

    public function bukaKunci(): void
    {
        abort_unless(auth()->user()?->can('periode.kunci'), 403);
        try {
            (new BukuBesar())->bukaKunci($this->periodeBuka, $this->alasanBuka, auth()->id());
            $this->dispatch('toast', type: 'success', message: 'Periode ' . SnapshotJurnal::labelPeriode($this->periodeBuka) . ' dibuka kembali.');
            $this->tutupBukaKunci();
        } catch (\Throwable $exception) {
            $this->addError('alasanBuka', $exception->getMessage());
        }
        $this->segarkan();
    }

    public function tutupBukaKunci(): void
    {
        $this->reset(['periodeBuka', 'alasanBuka']);
        $this->resetValidation();
        $this->dispatch('close-modal', name: 'buka-kunci-periode');
    }
};
?>

<div>

    <x-page-title
        title="Periode & Posting"
        subtitle="Sahkan versi snapshot SIRUS per bulan, posting ke buku besar, lalu kunci periode yang sudah diperiksa" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="px-6 pt-2 pb-6 space-y-4">

            {{-- TOOLBAR --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="w-full sm:w-40">
                    <x-input-label for="tahun" value="Tahun Buku" />
                    <x-select-input id="tahun" wire:model.live="tahun" class="mt-1">
                        @foreach ($this->tahunList as $pilihanTahun)
                            <option value="{{ $pilihanTahun }}">{{ $pilihanTahun }}</option>
                        @endforeach
                    </x-select-input>
                </div>
                <div class="px-4 py-2 text-xs rounded-lg bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200 sm:max-w-3xl">
                    Alur: ambil snapshot (Cutoff Jurnal) → <b>Sahkan & Posting</b> versi yang dipakai → periksa di Neraca Saldo → <b>Kunci</b>.
                    Selama periode Buka, versi lain boleh disahkan (posting lama dibatalkan, tetap tersimpan). Kunci berurutan dari bulan terlama.
                </div>
            </div>

            @if (!$this->tahunAda)
                <div class="p-6 text-center border border-dashed rounded-2xl border-hairline bg-canvas dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-sm text-body dark:text-gray-300">Tahun buku {{ $tahun }} belum dibuka.</p>
                    @can('periode.kunci')
                        <x-confirm-button variant="primary" action="bukaTahun()" class="mt-3"
                            title="Buka Tahun Buku {{ $tahun }}"
                            message="Membuat tahun buku {{ $tahun }} dan 12 periode berstatus Buka.">
                            Buka Tahun Buku {{ $tahun }}
                        </x-confirm-button>
                    @else
                        <p class="mt-2 text-xs text-muted">Minta pemegang hak "periode.kunci" membuka tahun buku.</p>
                    @endcan
                </div>
            @else
                <div class="overflow-x-auto bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                    <table class="ds-table ds-table-entri">
                        <thead>
                            <tr>
                                <th>Periode</th>
                                <th class="ds-c">Status</th>
                                <th>Versi Disahkan</th>
                                <th class="ds-c">Snapshot</th>
                                <th class="text-right">Total Jurnal (D = K)</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->periodeList as $row)
                                <tr wire:key="periode-{{ $row['periode'] }}">
                                    <td class="font-medium text-ink dark:text-gray-100">{{ SnapshotJurnal::labelPeriode($row['periode']) }}</td>
                                    <td class="ds-c">
                                        @switch($row['status'])
                                            @case('B') <x-badge variant="success">Buka</x-badge> @break
                                            @case('K') <x-badge variant="warning">Kunci</x-badge> @break
                                            @case('T') <x-badge variant="gray">Tutup</x-badge> @break
                                        @endswitch
                                        @if ($row['alasan_buka'] && $row['status'] === 'B')
                                            <div class="mt-1 text-[11px] text-muted dark:text-gray-400" title="{{ $row['alasan_buka'] }}">pernah dibuka ulang</div>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($row['versi_sah'])
                                            <span class="font-medium text-ink dark:text-gray-200">Versi {{ $row['versi_sah'] }}</span>
                                            @if ($row['jurnal_sirus'])
                                                <div class="text-[11px] text-muted dark:text-gray-400">{{ $row['jurnal_sirus']->jumlah }} jurnal · {{ Format::waktu($row['jurnal_sirus']->terakhir) }}</div>
                                            @endif
                                            @if ($row['ada_versi_baru'])
                                                <a href="{{ route('cutoff.banding-versi', ['periode' => $row['periode']]) }}" wire:navigate>
                                                    <x-badge variant="warning" class="mt-1">Ada versi lebih baru — bandingkan</x-badge>
                                                </a>
                                            @endif
                                        @else
                                            <span class="italic text-muted-soft">belum diposting</span>
                                        @endif
                                    </td>
                                    <td class="ds-c">
                                        @if (count($row['versi_list']) > 0)
                                            <span class="text-xs">{{ count($row['versi_list']) }} versi</span>
                                        @else
                                            <a href="{{ route('cutoff.snapshot-jurnal') }}" wire:navigate class="text-xs text-muted hover:underline">belum ada</a>
                                        @endif
                                    </td>
                                    <td class="text-right tabular-nums">{{ $row['total_debit'] > 0 ? Format::uang($row['total_debit']) : '-' }}</td>
                                    <td class="ds-c">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @if ($row['status'] === 'B' && count($row['versi_list']) > 0)
                                                @can('jurnal.posting')
                                                    <x-primary-button type="button" class="px-3 py-1.5 text-sm" wire:click="bukaPosting('{{ $row['periode'] }}')">
                                                        {{ $row['versi_sah'] ? 'Ganti Versi' : 'Sahkan & Posting' }}
                                                    </x-primary-button>
                                                @endcan
                                            @endif
                                            @if ($row['status'] === 'B' && $row['versi_sah'])
                                                @can('periode.kunci')
                                                    <x-confirm-button variant="warning-soft" :action="'kunci(\'' . $row['periode'] . '\')'" class="px-3 py-1.5"
                                                        title="Kunci Periode {{ SnapshotJurnal::labelPeriode($row['periode']) }}"
                                                        message="Sesudah dikunci, periode ini tidak menerima posting / jurnal baru sampai dibuka kembali dengan alasan. Pastikan Neraca Saldo sudah diperiksa.">
                                                        Kunci
                                                    </x-confirm-button>
                                                @endcan
                                            @endif
                                            @if ($row['status'] === 'K')
                                                @can('periode.kunci')
                                                    <x-secondary-button type="button" class="px-3 py-1.5 text-sm" wire:click="bukaFormBukaKunci('{{ $row['periode'] }}')">Buka Kunci</x-secondary-button>
                                                @endcan
                                            @endif
                                            @if ($row['versi_sah'])
                                                <a href="{{ route('buku-besar.neraca-saldo', ['periode' => $row['periode']]) }}" wire:navigate
                                                    class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-lg text-brand-green hover:underline dark:text-brand-lime">Neraca Saldo</a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- MODAL POSTING --}}
    <x-modal name="posting-periode" size="full" height="full">
        <div class="flex flex-col h-full">
            <x-modul-dokumen.header judul="Sahkan & Posting {{ $periodePosting ? SnapshotJurnal::labelPeriode($periodePosting) : '' }}"
                ikon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" tutupAlpine="$wire.tutupPosting()">
                Versi yang disahkan diposting ke buku besar; posting SIRUS sebelumnya untuk periode ini dibatalkan.
            </x-modul-dokumen.header>

            <div class="flex-1 px-4 py-4 space-y-4 bg-surface-soft dark:bg-gray-950/20">
                @php $barisPosting = collect($this->periodeList)->firstWhere('periode', $periodePosting); @endphp
                @if ($barisPosting)
                    <x-border-form title="Pilih Versi Snapshot">
                        <div class="space-y-2">
                            @foreach (array_reverse($barisPosting['versi_list']) as $versi)
                                <label wire:key="versi-{{ $versi['snap_id'] }}"
                                    class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer border-hairline dark:border-gray-700 has-[:checked]:border-brand-green has-[:checked]:bg-surface-strong dark:has-[:checked]:border-brand-lime">
                                    <input type="radio" wire:model.live="snapIdPilih" value="{{ $versi['snap_id'] }}" class="text-brand-green focus:ring-brand-green">
                                    <span class="flex-1">
                                        <span class="font-medium text-ink dark:text-gray-100">Versi {{ $versi['versi'] }}</span>
                                        @if ((int) $versi['snap_id'] === (int) $barisPosting['snap_id_sah'])
                                            <x-badge variant="success" class="ml-1">sedang disahkan</x-badge>
                                        @endif
                                        <span class="block text-xs text-muted dark:text-gray-400">
                                            diambil {{ Format::waktu($versi['selesai_at']) }} · {{ number_format((int) $versi['jumlah_baris'], 0, ',', '.') }} baris · D = K Rp {{ Format::uang($versi['total_debit']) }}
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs text-muted dark:text-gray-400">
                            Ingin tahu beda versinya?
                            <a href="{{ route('cutoff.banding-versi', ['periode' => $periodePosting]) }}" wire:navigate class="font-medium text-brand-green hover:underline dark:text-brand-lime">Bandingkan Versi</a>
                        </p>
                    </x-border-form>

                    <x-border-form title="Pemeriksaan Peta Akun">
                        @if ($this->akunBelumSiap === [])
                            <p class="text-sm text-fg-success-strong" role="status">Semua akun SIRUS di versi ini sudah dipetakan ke akun Detail aktif.</p>
                        @else
                            <p class="text-sm text-fg-danger-strong" role="alert">
                                {{ count($this->akunBelumSiap) }} akun SIRUS belum siap: {{ implode(', ', array_slice($this->akunBelumSiap, 0, 15)) }}{{ count($this->akunBelumSiap) > 15 ? ', ...' : '' }}.
                                Lengkapi di <a href="{{ route('master.peta-akun', ['status' => 'MASALAH']) }}" wire:navigate class="underline">Peta Akun SIRUS</a>.
                            </p>
                        @endif
                    </x-border-form>
                @endif
            </div>

            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex justify-end gap-2">
                    <x-secondary-button type="button" wire:click="tutupPosting">Batal</x-secondary-button>
                    <x-primary-button type="button" wire:click="sahkanPosting" wire:loading.attr="disabled" wire:target="sahkanPosting"
                        :disabled="$this->akunBelumSiap !== [] || !$snapIdPilih || ($barisPosting && (int) $snapIdPilih === (int) $barisPosting['snap_id_sah'])">
                        <span wire:loading.remove wire:target="sahkanPosting">Sahkan & Posting</span>
                        <span wire:loading wire:target="sahkanPosting">Memposting...</span>
                    </x-primary-button>
                </div>
            </div>
        </div>
    </x-modal>

    {{-- MODAL BUKA KUNCI --}}
    <x-modal name="buka-kunci-periode" size="2xl" focusable>
        <div class="p-1">
            <h2 class="text-lg font-semibold text-ink dark:text-gray-100">Buka Kunci {{ $periodeBuka ? SnapshotJurnal::labelPeriode($periodeBuka) : '' }}</h2>
            <p class="mt-1 text-sm text-muted dark:text-gray-400">Periode akan kembali Buka: bisa diposting ulang. Alasan dicatat di log audit.</p>
            <div class="mt-4">
                <x-input-label for="alasanBuka" value="Alasan buka kunci *" />
                <x-textarea id="alasanBuka" wire:model="alasanBuka" rows="3" class="w-full mt-1" :error="$errors->has('alasanBuka')"
                    placeholder="mis. koreksi transaksi kasir tanggal 3 yang terlambat diinput" />
                <x-input-error :messages="$errors->get('alasanBuka')" class="mt-1" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <x-secondary-button type="button" wire:click="tutupBukaKunci">Batal</x-secondary-button>
                <x-warning-button type="button" wire:click="bukaKunci" wire:loading.attr="disabled" wire:target="bukaKunci">Buka Kunci</x-warning-button>
            </div>
        </div>
    </x-modal>
</div>
