<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Support\BaganAkun;
use App\Support\Format;
use App\Support\JurnalUmum;
use App\Support\SnapshotJurnal;

/**
 * JURNAL UMUM / PENYESUAIAN — jurnal manual (sumber MANUAL): akrual, koreksi, reklasifikasi, penyusutan sementara.
 * Draft (jurnal.entri) → Posting (jurnal.posting, wajib seimbang, periode Buka) → Batal (periode Buka, alasan) /
 * Salin sebagai pembalik (periode sudah dikunci). Logika di App\Support\JurnalUmum.
 */
new class extends Component {
    #[Url(as: 'periode', except: '')]
    public string $periode = '';

    #[Url(as: 'status', except: '')]
    public string $filterStatus = '';

    public string $searchKeyword = '';

    /** Form */
    public ?int $jurnalId = null;
    public string $formStatus = 'D';
    public string $formNomor = '';
    public string $formTanggal = '';
    public string $formKeterangan = '';
    public array $formBaris = [];

    /** Batal */
    public ?int $jurnalBatalId = null;
    public string $alasanBatal = '';

    public function mount(): void
    {
        if (!SnapshotJurnal::periodeValid($this->periode)) {
            $this->periode = (string) (DB::table('sikeumst_periodes')->where('status', 'B')->min('periode')
                ?? DB::table('sikeumst_periodes')->max('periode') ?? now()->format('Ym'));
        }
    }

    #[Computed]
    public function periodeList(): array
    {
        return DB::table('sikeumst_periodes')->orderByDesc('periode')->pluck('status', 'periode')->all();
    }

    #[Computed]
    public function rows()
    {
        $query = DB::table('sikeutxn_jurnal_hdrs as h')->leftJoin('users as u', 'u.id', '=', 'h.dibuat_oleh')
            ->where('h.sumber', JurnalUmum::SUMBER)->where('h.periode', $this->periode)
            ->orderByDesc('h.tanggal')->orderByDesc('h.jurnal_id')
            ->select('h.jurnal_id', 'h.no_jurnal', 'h.tanggal', 'h.keterangan', 'h.total_debit', 'h.total_kredit', 'h.status', 'h.alasan_batal', 'u.name as pembuat');
        if (in_array($this->filterStatus, ['D', 'P', 'B'], true)) {
            $query->where('h.status', $this->filterStatus);
        }
        $keyword = mb_strtoupper(trim($this->searchKeyword));
        if ($keyword !== '') {
            $query->where(fn ($subQuery) => $subQuery->whereRaw('UPPER(h.no_jurnal) LIKE ?', ["%{$keyword}%"])->orWhereRaw('UPPER(h.keterangan) LIKE ?', ["%{$keyword}%"]));
        }

        return $query->get();
    }

    #[Computed]
    public function akunPilihan(): array
    {
        return BaganAkun::detailPerGolongan();
    }

    // ─── Form ─────────────────────────────────────────────────────────────────
    private function barisKosong(): array
    {
        return ['akun_id' => '', 'debit' => '', 'kredit' => '', 'keterangan' => ''];
    }

    private function bukaForm(): void
    {
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'form-jurnal-umum');
    }

    public function buatBaru(): void
    {
        abort_unless(auth()->user()?->can('jurnal.entri'), 403);
        $this->reset(['jurnalId', 'formNomor', 'formKeterangan']);
        $this->formStatus = 'D';
        $akhirBulan = Carbon::createFromFormat('Ym', $this->periode)->endOfMonth();
        $this->formTanggal = ($akhirBulan->isFuture() ? now() : $akhirBulan)->format('d/m/Y');
        $this->formBaris = [$this->barisKosong(), $this->barisKosong()];
        $this->bukaForm();
    }

    public function buka(int $jurnalId): void
    {
        try {
            $data = (new JurnalUmum())->ambil($jurnalId);
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
            return;
        }
        $this->jurnalId = $jurnalId;
        $this->formStatus = (string) $data['jurnal']->status;
        $this->formNomor = (string) $data['jurnal']->no_jurnal;
        $this->formTanggal = Format::tanggal($data['jurnal']->tanggal);
        $this->formKeterangan = (string) $data['jurnal']->keterangan;
        $this->formBaris = array_map(fn (array $baris) => [
            'akun_id' => (string) $baris['akun_id'],
            'debit' => (float) $baris['debit'] > 0 ? Format::uang($baris['debit']) : '',
            'kredit' => (float) $baris['kredit'] > 0 ? Format::uang($baris['kredit']) : '',
            'keterangan' => (string) ($baris['keterangan'] ?? ''),
        ], $data['baris']);
        $this->bukaForm();
    }

    /** Salin jurnal jadi draft baru (belum disimpan). Pembalik = debit/kredit ditukar, untuk membatalkan jurnal di periode terkunci. */
    public function salin(int $jurnalId, bool $pembalik = false): void
    {
        abort_unless(auth()->user()?->can('jurnal.entri'), 403);
        $this->buka($jurnalId);
        $nomorAsal = $this->formNomor;
        $this->reset(['jurnalId', 'formNomor']);
        $this->formStatus = 'D';
        if ($pembalik) {
            $this->formBaris = array_map(fn (array $baris) => ['akun_id' => $baris['akun_id'], 'debit' => $baris['kredit'], 'kredit' => $baris['debit'], 'keterangan' => $baris['keterangan']], $this->formBaris);
            $this->formKeterangan = mb_substr("Pembalik {$nomorAsal}: {$this->formKeterangan}", 0, 500);
            $this->formTanggal = '';
        }
    }

    public function tambahBaris(): void
    {
        $this->formBaris[] = $this->barisKosong();
    }

    public function hapusBaris(int $indeks): void
    {
        unset($this->formBaris[$indeks]);
        $this->formBaris = array_values($this->formBaris);
        if (count($this->formBaris) < 2) {
            $this->formBaris[] = $this->barisKosong();
        }
    }

    /** Total form (nominal tak terbaca dihitung 0; galatnya muncul saat simpan). */
    #[Computed]
    public function totalForm(): array
    {
        $debit = 0.0;
        $kredit = 0.0;
        foreach ($this->formBaris as $baris) {
            $debit += Format::uraiUang((string) $baris['debit']) ?? 0;
            $kredit += Format::uraiUang((string) $baris['kredit']) ?? 0;
        }

        return ['debit' => round($debit, 2), 'kredit' => round($kredit, 2), 'selisih' => round($debit - $kredit, 2)];
    }

    public function simpan(bool $langsungPosting = false): void
    {
        abort_unless(auth()->user()?->can('jurnal.entri'), 403);
        if ($langsungPosting) {
            abort_unless(auth()->user()?->can('jurnal.posting'), 403);
        }
        $this->resetValidation();
        try {
            $tanggal = Carbon::createFromFormat('d/m/Y', trim($this->formTanggal));
        } catch (\Throwable) {
            $tanggal = null;
        }
        if (!$tanggal || $tanggal->format('d/m/Y') !== trim($this->formTanggal)) {
            $this->addError('formTanggal', 'Tanggal harus dd/mm/yyyy, mis. 31/07/2026.');
            return;
        }
        $barisList = [];
        foreach ($this->formBaris as $indeks => $baris) {
            $debit = Format::uraiUang((string) $baris['debit']);
            $kredit = Format::uraiUang((string) $baris['kredit']);
            if ($debit === null || $kredit === null) {
                $this->addError("formBaris.{$indeks}.debit", 'Nominal harus angka, mis. 1.250.000 atau 1.250.000,50.');
                return;
            }
            $barisList[] = ['akun_id' => $baris['akun_id'], 'debit' => $debit, 'kredit' => $kredit, 'keterangan' => $baris['keterangan']];
        }
        try {
            $jurnalUmum = new JurnalUmum();
            $this->jurnalId = $jurnalUmum->simpanDraft($this->jurnalId, $tanggal->format('Y-m-d'), $this->formKeterangan, $barisList, auth()->id());
            if ($langsungPosting) {
                $jurnalUmum->posting($this->jurnalId, auth()->id());
            }
            $nomor = DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $this->jurnalId)->value('no_jurnal');
            $this->dispatch('toast', type: 'success', message: $langsungPosting ? "Jurnal {$nomor} diposting." : "Draft {$nomor} disimpan.");
            $this->periode = JurnalUmum::periodeDariTanggal($tanggal->format('Y-m-d'));
            $this->dispatch('jurnal-umum.disimpan');
            $this->tutupForm();
        } catch (\Throwable $exception) {
            $this->addError('formUmum', $exception->getMessage());
        }
        unset($this->rows);
    }

    public function tutupForm(): void
    {
        $this->reset(['jurnalId', 'formNomor', 'formTanggal', 'formKeterangan', 'formBaris']);
        $this->formStatus = 'D';
        $this->resetValidation();
        $this->dispatch('close-modal', name: 'form-jurnal-umum');
    }

    // ─── Aksi daftar ──────────────────────────────────────────────────────────
    public function posting(int $jurnalId): void
    {
        abort_unless(auth()->user()?->can('jurnal.posting'), 403);
        try {
            (new JurnalUmum())->posting($jurnalId, auth()->id());
            $this->dispatch('toast', type: 'success', message: 'Jurnal diposting.');
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        unset($this->rows);
    }

    public function hapusDraft(int $jurnalId): void
    {
        abort_unless(auth()->user()?->can('jurnal.entri'), 403);
        try {
            (new JurnalUmum())->hapusDraft($jurnalId);
            $this->dispatch('toast', type: 'success', message: 'Draft dihapus.');
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        unset($this->rows);
    }

    public function bukaBatal(int $jurnalId): void
    {
        abort_unless(auth()->user()?->can('jurnal.posting'), 403);
        $this->jurnalBatalId = $jurnalId;
        $this->alasanBatal = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'batal-jurnal-umum');
    }

    public function batal(): void
    {
        abort_unless(auth()->user()?->can('jurnal.posting'), 403);
        try {
            (new JurnalUmum())->batal((int) $this->jurnalBatalId, $this->alasanBatal, auth()->id());
            $this->dispatch('toast', type: 'success', message: 'Jurnal dibatalkan.');
            $this->reset(['jurnalBatalId', 'alasanBatal']);
            $this->dispatch('close-modal', name: 'batal-jurnal-umum');
        } catch (\Throwable $exception) {
            $this->addError('alasanBatal', $exception->getMessage());
        }
        unset($this->rows);
    }
};
?>

<div>

    <x-page-title
        title="Jurnal Umum"
        subtitle="Jurnal penyesuaian & koreksi manual — draft dibuat staf, diposting pemegang hak posting, wajib seimbang" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="w-full sm:w-44">
                            <x-input-label for="periode" value="Periode" class="sr-only" />
                            <x-select-input id="periode" wire:model.live="periode" aria-label="Periode">
                                @forelse ($this->periodeList as $kodePeriode => $statusPeriode)
                                    <option value="{{ $kodePeriode }}">{{ SnapshotJurnal::labelPeriode((string) $kodePeriode) }}{{ $statusPeriode === 'K' ? ' (kunci)' : '' }}</option>
                                @empty
                                    <option value="{{ $periode }}">{{ SnapshotJurnal::labelPeriode($periode) }}</option>
                                @endforelse
                            </x-select-input>
                        </div>
                        <div class="w-full sm:w-40">
                            <x-input-label for="filterStatus" value="Status" class="sr-only" />
                            <x-select-input id="filterStatus" wire:model.live="filterStatus" aria-label="Status">
                                <option value="">Semua status</option>
                                <option value="D">Draft</option>
                                <option value="P">Posting</option>
                                <option value="B">Batal</option>
                            </x-select-input>
                        </div>
                        <div class="w-full sm:w-72">
                            <x-input-label for="searchKeyword" value="Cari" class="sr-only" />
                            <x-text-input id="searchKeyword" wire:model.live.debounce.300ms="searchKeyword" placeholder="Cari nomor / keterangan..." />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        @can('jurnal.entri')
                            <x-primary-button type="button" wire:click="buatBaru" :disabled="($this->periodeList[$periode] ?? 'B') !== 'B'">+ Jurnal Baru</x-primary-button>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- TABLE --}}
            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex-1 min-h-0 overflow-auto rounded-2xl">
                    <table class="ds-table ds-table-entri">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th>No. Jurnal</th>
                                <th>Tanggal</th>
                                <th>Keterangan</th>
                                <th class="text-right">Jumlah</th>
                                <th class="ds-c">Status</th>
                                <th>Dibuat</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                <tr wire:key="ju-{{ $row->jurnal_id }}" class="{{ $row->status === 'B' ? 'opacity-60' : '' }}">
                                    <td class="ds-td-token">{{ $row->no_jurnal }}</td>
                                    <td class="whitespace-nowrap">{{ Format::tanggal($row->tanggal) }}</td>
                                    <td class="text-body dark:text-gray-300">
                                        {{ $row->keterangan }}
                                        @if ($row->status === 'B' && $row->alasan_batal)
                                            <div class="text-xs text-muted dark:text-gray-400">Batal: {{ $row->alasan_batal }}</div>
                                        @endif
                                        @if ($row->status === 'D' && round((float) $row->total_debit - (float) $row->total_kredit, 2) != 0.0)
                                            <div class="text-xs text-fg-danger-strong">Belum seimbang (selisih {{ Format::uang(abs((float) $row->total_debit - (float) $row->total_kredit)) }})</div>
                                        @endif
                                    </td>
                                    <td class="text-right tabular-nums">{{ Format::uang($row->total_debit) }}</td>
                                    <td class="ds-c">
                                        @switch($row->status)
                                            @case('D') <x-badge variant="warning">Draft</x-badge> @break
                                            @case('P') <x-badge variant="success">Posting</x-badge> @break
                                            @case('B') <x-badge variant="gray">Batal</x-badge> @break
                                        @endswitch
                                    </td>
                                    <td class="text-xs text-muted dark:text-gray-400">{{ $row->pembuat ?? '-' }}</td>
                                    <td class="ds-c">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <x-secondary-button type="button" class="px-2.5 py-1.5 text-sm" wire:click="buka({{ $row->jurnal_id }})">{{ $row->status === 'D' ? 'Ubah' : 'Lihat' }}</x-secondary-button>
                                            @if ($row->status === 'D')
                                                @can('jurnal.posting')
                                                    <x-confirm-button variant="primary" :action="'posting(' . $row->jurnal_id . ')'" class="px-2.5 py-1.5"
                                                        title="Posting {{ $row->no_jurnal }}" message="Jurnal masuk buku besar & laporan. Sesudah diposting tidak bisa diubah, hanya dibatalkan / dibalik.">Posting</x-confirm-button>
                                                @endcan
                                                @can('jurnal.entri')
                                                    <x-action-delete :action="'hapusDraft(' . $row->jurnal_id . ')'" title="Hapus Draft" message="Hapus draft {{ $row->no_jurnal }}?" />
                                                @endcan
                                            @endif
                                            @if ($row->status === 'P' && ($this->periodeList[$periode] ?? '') === 'B')
                                                @can('jurnal.posting')
                                                    <x-secondary-button type="button" class="px-2.5 py-1.5 text-sm" wire:click="bukaBatal({{ $row->jurnal_id }})">Batalkan</x-secondary-button>
                                                @endcan
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-6 py-10 text-center text-muted dark:text-gray-400">Belum ada jurnal umum di {{ SnapshotJurnal::labelPeriode($periode) }}.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="mt-2 text-xs text-muted dark:text-gray-400">
                Contoh penyesuaian akhir bulan: jasa medis dokter terutang (rekap SIAP), pendapatan rawat inap pasien yang masih dirawat, listrik/air terutang,
                penyusutan, cadangan piutang, koreksi salah akun. Lihat Panduan › Istilah Keuangan bagian Prinsip.
            </p>
        </div>
    </div>

    {{-- MODAL FORM --}}
    <x-modal name="form-jurnal-umum" size="full" height="full" focusable>
        @php $bacaSaja = $formStatus !== 'D' || !auth()->user()?->can('jurnal.entri'); $total = $this->totalForm; @endphp
        <x-dirty-modal-content name="form-jurnal-umum" event="jurnal-umum.disimpan" label="Jurnal"
            :wireKey="'form-ju-' . ($jurnalId ?? 'baru')">

            <x-modul-dokumen.header :judul="$jurnalId ? ($bacaSaja ? 'Jurnal ' : 'Ubah Draft ') . $formNomor : 'Jurnal Umum Baru'"
                ikon="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" tutupAlpine="tryClose()">
                Satu baris = satu sisi (debit ATAU kredit). Jumlah debit harus sama dengan jumlah kredit sebelum diposting.
                <x-slot:badge>
                    @switch($formStatus)
                        @case('D') <x-badge variant="warning">Draft</x-badge> @break
                        @case('P') <x-badge variant="success">Posting</x-badge> @break
                        @case('B') <x-badge variant="gray">Batal</x-badge> @break
                    @endswitch
                </x-slot:badge>
            </x-modul-dokumen.header>

            <div class="flex-1 px-4 py-4 space-y-4 bg-surface-soft dark:bg-gray-950/20">
                @error('formUmum')
                    <div role="alert" class="px-4 py-2 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">{{ $message }}</div>
                @enderror

                <x-border-form title="Jurnal">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <div>
                            <x-input-label for="formTanggal" value="Tanggal *" />
                            <x-text-input id="formTanggal" wire:model="formTanggal" placeholder="dd/mm/yyyy" :disabled="$bacaSaja"
                                :error="$errors->has('formTanggal')" class="mt-1" />
                            <x-input-error :messages="$errors->get('formTanggal')" class="mt-1" />
                        </div>
                        <div class="md:col-span-3">
                            <x-input-label for="formKeterangan" value="Keterangan / dasar jurnal *" />
                            <x-text-input id="formKeterangan" wire:model="formKeterangan" maxlength="500" :disabled="$bacaSaja"
                                placeholder="mis. Akrual jasa medis dokter Juli 2026 sesuai rekap SIAP" class="mt-1" />
                        </div>
                    </div>
                </x-border-form>

                <x-border-form title="Baris Jurnal">
                    <div class="overflow-x-auto">
                        <table class="ds-table ds-table-entri">
                            <thead>
                                <tr>
                                    <th class="w-8">#</th>
                                    <th class="min-w-[22rem]">Akun</th>
                                    <th class="w-44 text-right">Debit</th>
                                    <th class="w-44 text-right">Kredit</th>
                                    <th>Keterangan baris</th>
                                    @if (!$bacaSaja)
                                        <th class="w-12"></th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($formBaris as $indeks => $baris)
                                    <tr wire:key="baris-{{ $indeks }}">
                                        <td class="text-xs text-muted">{{ $indeks + 1 }}</td>
                                        <td>
                                            <x-select-input wire:model="formBaris.{{ $indeks }}.akun_id" :disabled="$bacaSaja" aria-label="Akun baris {{ $indeks + 1 }}">
                                                <option value="">- pilih akun -</option>
                                                @foreach ($this->akunPilihan as $golonganId => $golongan)
                                                    <optgroup label="{{ $golonganId }} {{ $golongan['nama'] }}">
                                                        @foreach ($golongan['akun'] as $akunId => $namaAkun)
                                                            <option value="{{ $akunId }}">{{ $akunId }} {{ $namaAkun }}</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endforeach
                                            </x-select-input>
                                        </td>
                                        <td>
                                            <x-text-input wire:model.blur="formBaris.{{ $indeks }}.debit" inputmode="decimal" :disabled="$bacaSaja"
                                                :error="$errors->has('formBaris.' . $indeks . '.debit')" class="text-right tabular-nums" aria-label="Debit baris {{ $indeks + 1 }}" />
                                            <x-input-error :messages="$errors->get('formBaris.' . $indeks . '.debit')" class="mt-1" />
                                        </td>
                                        <td>
                                            <x-text-input wire:model.blur="formBaris.{{ $indeks }}.kredit" inputmode="decimal" :disabled="$bacaSaja"
                                                class="text-right tabular-nums" aria-label="Kredit baris {{ $indeks + 1 }}" />
                                        </td>
                                        <td>
                                            <x-text-input wire:model="formBaris.{{ $indeks }}.keterangan" maxlength="500" :disabled="$bacaSaja" aria-label="Keterangan baris {{ $indeks + 1 }}" />
                                        </td>
                                        @if (!$bacaSaja)
                                            <td>
                                                <x-icon-button type="button" wire:click="hapusBaris({{ $indeks }})" aria-label="Hapus baris {{ $indeks + 1 }}">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                                </x-icon-button>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="font-bold bg-surface-card text-ink dark:bg-gray-800 dark:text-gray-100">
                                    <td colspan="2" class="px-3 py-3">
                                        @if (!$bacaSaja)
                                            <x-secondary-button type="button" wire:click="tambahBaris" class="px-3 py-1.5 text-sm">+ Baris</x-secondary-button>
                                        @else
                                            Jumlah
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ Format::uang($total['debit']) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ Format::uang($total['kredit']) }}</td>
                                    <td colspan="{{ $bacaSaja ? 1 : 2 }}" class="px-3 py-3">
                                        @if ($total['selisih'] == 0.0 && $total['debit'] > 0)
                                            <x-badge variant="success">Seimbang</x-badge>
                                        @elseif ($total['selisih'] != 0.0)
                                            <x-badge variant="danger">Selisih {{ Format::uang(abs($total['selisih'])) }}</x-badge>
                                        @endif
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="mt-2 text-[11px] text-muted dark:text-gray-400">Nominal ditulis gaya Indonesia: 1.250.000 atau 1.250.000,50. Jumlah dihitung ulang saat kursor meninggalkan kotak nominal.</p>
                </x-border-form>
            </div>

            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-wrap items-center justify-end gap-2">
                    @if ($jurnalId && $formStatus !== 'D')
                        @can('jurnal.entri')
                            <x-secondary-button type="button" wire:click="salin({{ $jurnalId }})">Salin jadi draft baru</x-secondary-button>
                            <x-secondary-button type="button" wire:click="salin({{ $jurnalId }}, true)">Salin sebagai pembalik</x-secondary-button>
                        @endcan
                    @endif
                    <x-secondary-button type="button" x-on:click="tryClose()">{{ $bacaSaja ? 'Tutup' : 'Batal' }}</x-secondary-button>
                    @if (!$bacaSaja)
                        <x-secondary-button type="button" wire:click="simpan(false)" wire:loading.attr="disabled" wire:target="simpan">Simpan Draft</x-secondary-button>
                        @can('jurnal.posting')
                            <x-primary-button type="button" wire:click="simpan(true)" wire:loading.attr="disabled" wire:target="simpan"
                                :disabled="$total['selisih'] != 0.0 || $total['debit'] <= 0">Simpan & Posting</x-primary-button>
                        @endcan
                    @endif
                </div>
            </div>
        </x-dirty-modal-content>
    </x-modal>

    {{-- MODAL BATAL --}}
    <x-modal name="batal-jurnal-umum" size="2xl" focusable>
        <div class="p-1 space-y-4">
            <h2 class="text-lg font-semibold text-ink dark:text-gray-100">Batalkan Jurnal</h2>
            <p class="text-sm text-muted dark:text-gray-400">Jurnal tetap tersimpan berstatus Batal dan keluar dari saldo & laporan. Alasan dicatat di log audit.</p>
            <div>
                <x-input-label for="alasanBatal" value="Alasan *" />
                <x-textarea id="alasanBatal" wire:model="alasanBatal" rows="3" class="w-full mt-1" :error="$errors->has('alasanBatal')" />
                <x-input-error :messages="$errors->get('alasanBatal')" class="mt-1" />
            </div>
            <div class="flex justify-end gap-2">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'batal-jurnal-umum' })">Tutup</x-secondary-button>
                <x-danger-button type="button" wire:click="batal" wire:loading.attr="disabled" wire:target="batal">Batalkan Jurnal</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
