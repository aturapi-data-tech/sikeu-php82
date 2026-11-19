<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use App\Support\BukuBesar;
use App\Support\Format;
use App\Support\SnapshotJurnal;

/**
 * NERACA SALDO — saldo awal, mutasi, saldo akhir tiap akun untuk satu periode (jurnal berstatus Posting).
 * Pemeriksaan pertama setelah posting: total debit harus sama dengan total kredit, dan saldo tiap akun masuk akal
 * sebelum periode dikunci. Hitungan di App\Support\BukuBesar::neracaSaldo.
 */
new class extends Component {
    #[Url(as: 'periode', except: '')]
    public string $periode = '';

    #[Url(as: 'laporan', except: '')]
    public string $filterLaporan = '';

    public string $searchKeyword = '';

    /** Hanya akun yang saldo akhirnya berlawanan dengan saldo normalnya. */
    public bool $hanyaBerlawanan = false;

    public static function berlawanan(array $akun): bool
    {
        return ($akun['akhir'] < 0 && $akun['saldo_normal'] === 'D') || ($akun['akhir'] > 0 && $akun['saldo_normal'] === 'K');
    }

    /** Akun setelah filter laporan & pencarian (sebelum saring "berlawanan") — dasar badge & tabel. */
    #[Computed]
    public function akunTersaring()
    {
        $keyword = mb_strtoupper(trim($this->searchKeyword));

        return collect($this->hasil['akun'])
            ->filter(fn (array $akun) => $this->filterLaporan === '' || $akun['jenis_laporan'] === $this->filterLaporan)
            ->filter(fn (array $akun) => $keyword === '' || str_starts_with($akun['akun_id'], $keyword) || str_contains(mb_strtoupper($akun['nama']), $keyword));
    }

    #[Computed]
    public function jumlahBerlawanan(): int
    {
        return $this->akunTersaring->filter(fn (array $akun) => self::berlawanan($akun))->count();
    }

    public function mount(): void
    {
        if (!SnapshotJurnal::periodeValid($this->periode)) {
            $this->periode = (string) (DB::table('sikeumst_periodes')->whereNotNull('snap_id_sah')->max('periode')
                ?? DB::table('sikeumst_periodes')->max('periode') ?? now()->format('Ym'));
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['filterLaporan', 'searchKeyword', 'hanyaBerlawanan']);
    }

    #[Computed]
    public function periodeList(): array
    {
        return DB::table('sikeumst_periodes')->orderByDesc('periode')->pluck('status', 'periode')->all();
    }

    #[Computed]
    public function hasil(): array
    {
        return (new BukuBesar())->neracaSaldo($this->periode);
    }

    #[Computed]
    public function kelompokList()
    {
        return DB::table('sikeumst_akun_kelompoks')->orderBy('urut')->orderBy('kelompok_id')->get()->keyBy('kelompok_id');
    }

    /** Baris akun setelah filter, dikelompokkan per kelompok akun dengan subtotal. */
    #[Computed]
    public function kelompokRows(): array
    {
        $akunList = $this->akunTersaring->filter(fn (array $akun) => !$this->hanyaBerlawanan || self::berlawanan($akun));

        $hasilList = [];
        foreach ($this->kelompokList as $kelompokId => $kelompok) {
            $anggota = $akunList->where('kelompok_id', (string) $kelompokId)->values();
            if ($anggota->isEmpty()) {
                continue;
            }
            $hasilList[] = [
                'kelompok' => $kelompok,
                'akun' => $anggota->all(),
                'awal' => $anggota->sum('awal'),
                'mutasi_debit' => $anggota->sum('mutasi_debit'),
                'mutasi_kredit' => $anggota->sum('mutasi_kredit'),
                'akhir' => $anggota->sum('akhir'),
            ];
        }

        return $hasilList;
    }

    #[Computed]
    public function seimbang(): bool
    {
        $total = $this->hasil['total'];

        return round($total['mutasi_debit'] - $total['mutasi_kredit'], 2) == 0.0
            && round($total['akhir_debit'] - $total['akhir_kredit'], 2) == 0.0
            && round($total['awal_debit'] - $total['awal_kredit'], 2) == 0.0;
    }
};
?>

<div>

    <x-page-title
        title="Neraca Saldo"
        subtitle="Saldo awal, mutasi & saldo akhir tiap akun per periode — dari jurnal yang sudah diposting" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="w-full sm:w-44">
                            <x-input-label for="periode" value="Periode" class="sr-only" />
                            <x-select-input id="periode" wire:model.live="periode" aria-label="Periode">
                                @forelse ($this->periodeList as $pilihanPeriode => $statusPeriode)
                                    <option value="{{ $pilihanPeriode }}">{{ SnapshotJurnal::labelPeriode((string) $pilihanPeriode) }}{{ $statusPeriode === 'K' ? ' (kunci)' : '' }}</option>
                                @empty
                                    <option value="{{ $periode }}">{{ SnapshotJurnal::labelPeriode($periode) }}</option>
                                @endforelse
                            </x-select-input>
                        </div>
                        <div class="w-full sm:w-48">
                            <x-input-label for="filterLaporan" value="Laporan" class="sr-only" />
                            <x-select-input id="filterLaporan" wire:model.live="filterLaporan" aria-label="Filter laporan">
                                <option value="">Neraca & Laba Rugi</option>
                                <option value="N">Akun Neraca</option>
                                <option value="L">Akun Laba Rugi</option>
                            </x-select-input>
                        </div>
                        <div class="w-full sm:w-72">
                            <x-input-label for="searchKeyword" value="Cari akun" class="sr-only" />
                            <x-text-input id="searchKeyword" wire:model.live.debounce.300ms="searchKeyword" placeholder="Cari kode (awalan) / nama akun..." />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($this->seimbang)
                            <x-badge variant="success">Seimbang (D = K)</x-badge>
                        @endif
                        @if ($this->jumlahBerlawanan > 0)
                            <button type="button" wire:click="$toggle('hanyaBerlawanan')" aria-pressed="{{ $hanyaBerlawanan ? 'true' : 'false' }}"
                                title="Saring akun yang saldonya berlawanan dengan saldo normal"
                                class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green {{ $hanyaBerlawanan ? 'bg-danger-strong text-white' : 'bg-danger-soft text-fg-danger-strong hover:ring-1 hover:ring-fg-danger-strong' }}">
                                <x-ikon-peringatan /> {{ $this->jumlahBerlawanan }} saldo berlawanan{{ $hanyaBerlawanan ? ' (disaring)' : '' }}
                            </button>
                        @else
                            <x-badge variant="danger">TIDAK seimbang</x-badge>
                        @endif
                        <x-toolbar-refresh-reset :label="null" />
                    </div>
                </div>
            </div>

            {{-- TABLE --}}
            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex-1 min-h-0 overflow-auto rounded-2xl">
                    <table class="ds-table ds-table-entri">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th rowspan="2">Kode</th>
                                <th rowspan="2">Nama Akun</th>
                                <th colspan="2" class="ds-c">Saldo Awal</th>
                                <th colspan="2" class="ds-c">Mutasi {{ SnapshotJurnal::labelPeriode($periode) }}</th>
                                <th colspan="2" class="ds-c">Saldo Akhir</th>
                            </tr>
                            <tr>
                                @foreach (['Debit', 'Kredit', 'Debit', 'Kredit', 'Debit', 'Kredit'] as $judulKolom)
                                    <th class="text-right w-36">{{ $judulKolom }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->kelompokRows as $grup)
                                <tr wire:key="kelompok-{{ $grup['kelompok']->kelompok_id }}" class="bg-surface-card dark:bg-gray-800">
                                    <td class="ds-td-token">{{ $grup['kelompok']->kelompok_id }}</td>
                                    <td colspan="7" class="font-bold uppercase text-ink dark:text-gray-100">
                                        {{ $grup['kelompok']->nama }}
                                        <x-badge class="ml-2 normal-case" :variant="$grup['kelompok']->jenis_laporan === 'N' ? 'info' : 'purple'">{{ $grup['kelompok']->jenis_laporan === 'N' ? 'Neraca' : 'Laba Rugi' }}</x-badge>
                                    </td>
                                </tr>
                                @foreach ($grup['akun'] as $akun)
                                    <tr wire:key="akun-{{ $akun['akun_id'] }}">
                                        <td class="ds-td-token">
                                            <button type="button" wire:click="$dispatch('buku-besar.buka-mutasi', { akunId: '{{ $akun['akun_id'] }}', dari: '{{ $periode }}', sampai: '{{ $periode }}' })"
                                                class="text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green rounded dark:text-brand-lime">{{ $akun['akun_id'] }}</button>
                                        </td>
                                        <td>
                                            <button type="button" wire:click="$dispatch('buku-besar.buka-mutasi', { akunId: '{{ $akun['akun_id'] }}', dari: '{{ $periode }}', sampai: '{{ $periode }}' })"
                                                class="text-left text-body hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green rounded dark:text-gray-300">{{ $akun['nama'] }}</button>
                                        </td>
                                        <td class="text-right tabular-nums">{{ $akun['awal'] > 0 ? Format::uang($akun['awal']) : '' }}</td>
                                        <td class="text-right tabular-nums">{{ $akun['awal'] < 0 ? Format::uang(-$akun['awal']) : '' }}</td>
                                        <td class="text-right tabular-nums">{{ $akun['mutasi_debit'] != 0 ? Format::uang($akun['mutasi_debit']) : '' }}</td>
                                        <td class="text-right tabular-nums">{{ $akun['mutasi_kredit'] != 0 ? Format::uang($akun['mutasi_kredit']) : '' }}</td>
                                        @php $berlawanan = $this::berlawanan($akun); @endphp
                                        @foreach ([$akun['akhir'] > 0 ? $akun['akhir'] : null, $akun['akhir'] < 0 ? -$akun['akhir'] : null] as $nilaiAkhir)
                                            <td class="text-right tabular-nums whitespace-nowrap {{ $berlawanan && $nilaiAkhir !== null ? 'bg-danger-soft text-fg-danger-strong font-semibold' : 'font-medium text-ink dark:text-gray-100' }}">
                                                @if ($nilaiAkhir !== null)
                                                    @if ($berlawanan) <x-ikon-peringatan label="Saldo berlawanan dengan saldo normal" /> @endif{{ Format::uang($nilaiAkhir) }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                                <tr wire:key="subtotal-{{ $grup['kelompok']->kelompok_id }}" class="text-xs">
                                    <td></td>
                                    <td class="italic text-muted dark:text-gray-400">Jumlah {{ $grup['kelompok']->nama }} (bersih)</td>
                                    <td class="text-right tabular-nums">{{ $grup['awal'] > 0 ? Format::uang($grup['awal']) : '' }}</td>
                                    <td class="text-right tabular-nums">{{ $grup['awal'] < 0 ? Format::uang(-$grup['awal']) : '' }}</td>
                                    <td class="text-right tabular-nums">{{ Format::uang($grup['mutasi_debit']) }}</td>
                                    <td class="text-right tabular-nums">{{ Format::uang($grup['mutasi_kredit']) }}</td>
                                    <td class="text-right tabular-nums">{{ $grup['akhir'] > 0 ? Format::uang($grup['akhir']) : '' }}</td>
                                    <td class="text-right tabular-nums">{{ $grup['akhir'] < 0 ? Format::uang(-$grup['akhir']) : '' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-10 text-center text-muted dark:text-gray-400">
                                        @if ($this->hasil['akun'] === [])
                                            Belum ada jurnal terposting sampai periode {{ SnapshotJurnal::labelPeriode($periode) }}.
                                            <a href="{{ route('buku-besar.periode', ['tahun' => substr($periode, 0, 4)]) }}" wire:navigate class="font-medium underline">Sahkan & posting di Tarik Data SIRUS</a>.
                                        @else
                                            Tidak ada akun yang cocok dengan filter — <button type="button" wire:click="resetFilters" class="underline">reset filter</button>.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse

                            @if ($this->hasil['laba_tahun_lalu'] != 0.0 && $filterLaporan !== 'L')
                                <tr class="text-sm">
                                    <td></td>
                                    <td class="italic text-body dark:text-gray-300">Laba (rugi) tahun lalu belum ditutup buku</td>
                                    <td class="text-right tabular-nums">{{ $this->hasil['laba_tahun_lalu'] < 0 ? Format::uang(-$this->hasil['laba_tahun_lalu']) : '' }}</td>
                                    <td class="text-right tabular-nums">{{ $this->hasil['laba_tahun_lalu'] > 0 ? Format::uang($this->hasil['laba_tahun_lalu']) : '' }}</td>
                                    <td></td><td></td>
                                    <td class="text-right tabular-nums">{{ $this->hasil['laba_tahun_lalu'] < 0 ? Format::uang(-$this->hasil['laba_tahun_lalu']) : '' }}</td>
                                    <td class="text-right tabular-nums">{{ $this->hasil['laba_tahun_lalu'] > 0 ? Format::uang($this->hasil['laba_tahun_lalu']) : '' }}</td>
                                </tr>
                            @endif
                        </tbody>
                        @if ($filterLaporan === '' && trim($searchKeyword) === '')
                            <tfoot class="sticky bottom-0">
                                <tr class="font-bold bg-surface-card text-ink dark:bg-gray-800 dark:text-gray-100">
                                    <td colspan="2" class="px-3 py-3">TOTAL</td>
                                    @foreach (['awal_debit', 'awal_kredit', 'mutasi_debit', 'mutasi_kredit', 'akhir_debit', 'akhir_kredit'] as $kolomTotal)
                                        <td class="px-3 py-3 text-right tabular-nums">{{ Format::uang($this->hasil['total'][$kolomTotal]) }}</td>
                                    @endforeach
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
            <p class="mt-2 text-xs text-muted dark:text-gray-400">
                Klik kode / nama akun untuk melihat mutasinya tanpa meninggalkan halaman ini.
                Sel merah bertanda segitiga peringatan = saldo berlawanan dengan saldo normal akunnya (mis. piutang / persediaan bersaldo kredit) — periksa sebelum mengunci periode; klik badge "saldo berlawanan" untuk menyaringnya.
                Saldo awal Januari berasal dari Pembukuan › Saldo Awal; tanpa saldo awal sah, saldo akun Neraca = akumulasi mutasi yang sudah diposting.
            </p>
        </div>
    </div>
    <livewire:pages::buku-besar.mutasi-akun.mutasi-akun-modal wire:key="mutasi-akun-modal" />
</div>

