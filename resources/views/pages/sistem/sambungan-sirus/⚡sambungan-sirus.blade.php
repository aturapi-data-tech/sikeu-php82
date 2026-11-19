<?php

use Livewire\Component;
use Illuminate\Support\Facades\DB;

/**
 * SAMBUNGAN SIRUS — cek koneksi 'sirus' (config/database.php): login user SIKEU,
 * CURRENT_SCHEMA dialihkan ke skema SIRUS, hak hanya SELECT.
 * Empat pemeriksaan: tersambung, jumlah objek ber-hak baca, contoh baca, dan TULIS DITOLAK.
 * Uji tulis = DELETE … WHERE 1 = 0 di dalam transaksi yang di-rollback: tak ada baris tersentuh
 * walau hak tulis ternyata ada, dan Oracle memeriksa hak saat parse sehingga ORA-01031 tetap muncul.
 */
new class extends Component {
    /** @var array<int, array{judul: string, lulus: bool, keterangan: string}> */
    public array $hasilPeriksa = [];

    public ?string $waktuPeriksa = null;

    public function periksa(): void
    {
        $this->hasilPeriksa = [];
        $skemaSirus = strtoupper((string) config('database.connections.sirus.schema'));

        try {
            // yajra langsung membuka sambungan saat koneksi dibuat → harus di dalam try.
            $koneksi = DB::connection('sirus');
            $sesi = $koneksi->selectOne("SELECT USER AS pengguna, SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA') AS skema FROM dual");
        } catch (\Throwable $e) {
            $this->tambahHasil('Tersambung ke Oracle', false, $e->getMessage());
            $this->waktuPeriksa = now()->format('d/m/Y H:i:s');
            return;
        }

        $penggunaSama = strtoupper((string) $sesi->pengguna) === $skemaSirus;
        $this->tambahHasil(
            'Tersambung ke Oracle',
            !$penggunaSama,
            $penggunaSama
                ? "Login sebagai {$sesi->pengguna} = PEMILIK skema SIRUS. Ganti DB_USERNAME ke user sikeu supaya hak tulis tidak ikut."
                : "Login {$sesi->pengguna}, skema aktif {$sesi->skema}.",
        );

        try {
            $jumlahObjek = (int) $koneksi->selectOne(
                'SELECT COUNT(*) AS c FROM all_tab_privs WHERE grantee = USER AND table_schema = ? AND privilege = ?',
                [$skemaSirus, 'SELECT'],
            )->c;
            $this->tambahHasil(
                'Hak baca ke skema SIRUS',
                $jumlahObjek > 0,
                $jumlahObjek > 0
                    ? number_format($jumlahObjek, 0, ',', '.') . ' tabel/view bisa dibaca.'
                    : 'Belum ada hak baca — jalankan database/sql/01_grant_baca_sirus.sql.',
            );
        } catch (\Throwable $e) {
            $this->tambahHasil('Hak baca ke skema SIRUS', false, $e->getMessage());
        }

        try {
            $jumlahAkun = (int) $koneksi->table('acmst_accounts')->count();
            $this->tambahHasil('Contoh baca: acmst_accounts', true, number_format($jumlahAkun, 0, ',', '.') . ' akun terbaca.');
        } catch (\Throwable $e) {
            $this->tambahHasil('Contoh baca: acmst_accounts', false, $e->getMessage());
        }

        try {
            $koneksi->beginTransaction();
            $koneksi->statement('DELETE FROM acmst_accounts WHERE 1 = 0');
            $koneksi->rollBack();
            $this->tambahHasil('Tulis ke SIRUS ditolak', false, 'BAHAYA: perintah tulis DITERIMA. Cabut hak INSERT/UPDATE/DELETE user ini di skema SIRUS.');
        } catch (\Throwable $e) {
            if ($koneksi->transactionLevel() > 0) {
                $koneksi->rollBack();
            }
            $ditolakHak = str_contains($e->getMessage(), 'ORA-01031');
            $this->tambahHasil(
                'Tulis ke SIRUS ditolak',
                $ditolakHak,
                $ditolakHak ? 'Oracle menolak (ORA-01031 insufficient privileges) — sesuai harapan.' : $e->getMessage(),
            );
        }

        $this->waktuPeriksa = now()->format('d/m/Y H:i:s');
    }

    private function tambahHasil(string $judul, bool $lulus, string $keterangan): void
    {
        $this->hasilPeriksa[] = ['judul' => $judul, 'lulus' => $lulus, 'keterangan' => $keterangan];
    }
};
?>

<div>
    <x-page-title title="Sambungan SIRUS"
        subtitle="Cek koneksi baca-saja SIKEU ke skema SIRUS — tersambung, hak baca, dan tulis wajib ditolak" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="max-w-3xl px-6 pt-4 pb-6 mx-auto space-y-4">

            <div class="p-4 text-sm border rounded-xl border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-800/40 dark:bg-blue-900/20 dark:text-blue-100">
                SIKEU membaca data keuangan langsung dari tabel SIRUS lewat koneksi <code>sirus</code>.
                Login memakai user <b>sikeu</b> yang hanya punya hak <b>SELECT</b>, jadi tulis ke SIRUS ditolak Oracle.
                Skema tujuan: <b>{{ config('database.connections.sirus.schema') }}</b>.
            </div>

            <div class="flex items-center gap-3">
                <x-primary-button type="button" wire:click="periksa" wire:loading.attr="disabled" wire:target="periksa">
                    <span wire:loading.remove wire:target="periksa">Periksa Sambungan</span>
                    <span wire:loading wire:target="periksa">Memeriksa…</span>
                </x-primary-button>
                @if ($waktuPeriksa)
                    <span class="text-xs text-muted dark:text-gray-400">Diperiksa {{ $waktuPeriksa }}</span>
                @endif
            </div>

            @if ($hasilPeriksa)
                <ul class="overflow-hidden border divide-y rounded-xl border-hairline divide-hairline bg-surface-card dark:bg-gray-800 dark:border-gray-700 dark:divide-gray-700">
                    @foreach ($hasilPeriksa as $hasil)
                        <li class="flex items-start gap-3 px-4 py-3">
                            <span @class([
                                'mt-0.5 inline-flex shrink-0 items-center justify-center w-6 h-6 rounded-full text-xs font-bold',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $hasil['lulus'],
                                'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' => !$hasil['lulus'],
                            ]) aria-hidden="true">{{ $hasil['lulus'] ? '✓' : '!' }}</span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink dark:text-white">
                                    {{ $hasil['judul'] }}
                                    <span class="sr-only">{{ $hasil['lulus'] ? '(lulus)' : '(gagal)' }}</span>
                                </p>
                                <p class="text-sm break-words text-body dark:text-gray-300">{{ $hasil['keterangan'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
