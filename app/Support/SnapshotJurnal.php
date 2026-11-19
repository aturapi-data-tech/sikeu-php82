<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SNAPSHOT JURNAL (cutoff) — jurnal SIRUS satu periode disalin ke SIKEU sebagai VERSI, lalu dibandingkan antar versi.
 *
 * Alur ambil (dipakai layar "Ambil dari SIRUS" lewat wire:poll, dan perintah `php artisan sikeu:ambil-jurnal`):
 *   1. mulai(periode)        → katalog /cabang dari SIRUS, header versi baru (status P), satu baris per cabang (status B)
 *   2. prosesBerikut(snapId) → ambil cabang berstatus B berikutnya (GET /jurnal/{tahun}/{bulan}?cabang=N), simpan barisnya,
 *                              tandai cabang S. Diulang sampai habis; aman dipanggil dari dua tab sekaligus (kunci baris).
 *   3. selesai otomatis     → total & checksum header dihitung dari cabang, status S.
 * Galat API → header G + pesan; versi G tidak dipakai pembanding.
 *
 * Pembanding (bandingkan): cabang yang checksum-nya sama dilewati; cabang beda dibandingkan per kunci_baris
 * (FULL OUTER JOIN di Oracle). Hasil: baru / hilang / berubah. Pasangan hilang+baru berlabel sama di cabang yang sama
 * digabung jadi "berubah" (mis. tanggal atau akun transaksinya diganti).
 *
 * Kelas INSTANCE (bukan statis): yajra insertGetId butuh frame objek pemanggil.
 */
class SnapshotJurnal
{
    public const STATUS_PROSES = 'P';
    public const STATUS_SELESAI = 'S';
    public const STATUS_GAGAL = 'G';

    public const LABEL_STATUS = ['P' => 'Proses', 'S' => 'Selesai', 'G' => 'Gagal'];

    /** Batas baris selisih yang ditampilkan sekali banding (sisanya disebut jumlahnya saja). */
    public const BATAS_BARIS_SELISIH = 3000;

    private const UKURAN_SISIP = 250;

    public static function periodeValid(?string $periode): bool
    {
        return is_string($periode) && preg_match('/^\d{4}(0[1-9]|1[0-2])$/', $periode) === 1;
    }

    /** 202612 → 202701 */
    public static function periodeBerikutnya(string $periode): string
    {
        $tahun = (int) substr($periode, 0, 4);
        $bulan = (int) substr($periode, 4, 2) + 1;

        return $bulan > 12 ? sprintf('%04d01', $tahun + 1) : sprintf('%04d%02d', $tahun, $bulan);
    }

    public static function labelPeriode(string $periode): string
    {
        return self::periodeValid($periode) ? Carbon::createFromFormat('Ym d', "{$periode} 01")->format('m/Y') : $periode;
    }

    /** Kunci baris: identitas transaksi TANPA nominal → nominal beda = "berubah", bukan baru+hilang. */
    public static function kunciBaris(string $cabangKunci, array $baris): string
    {
        return sha1(implode('|', [
            $cabangKunci, $baris['txn_name'] ?? '', $baris['txn_acc'] ?? '', $baris['txn_acc_k'] ?? '',
            $baris['txn_date'] ?? '', $baris['shift'] ?? '',
        ]));
    }

    private static function nominal($nilai): string
    {
        return number_format(round((float) $nilai, 2), 2, '.', '');
    }

    // ─── Ambil ────────────────────────────────────────────────────────────────

    /**
     * Mulai snapshot periode. Bila masih ada versi berstatus P untuk periode itu, versi itu yang dilanjutkan
     * (tidak membuat versi ganda). @return int snap_id
     */
    public function mulai(string $periode, ?int $userId): int
    {
        if (!self::periodeValid($periode)) {
            throw new RuntimeException('Periode harus YYYYMM.');
        }
        $sedangProses = DB::table('sikeutxn_snap_hdrs')->where('periode', $periode)->where('status', self::STATUS_PROSES)->value('snap_id');
        if ($sedangProses) {
            return (int) $sedangProses;
        }

        $cabangList = SirusApi::get('cabang')['cabang'] ?? [];
        if ($cabangList === []) {
            throw new RuntimeException('Katalog cabang jurnal dari SIRUS kosong.');
        }

        return DB::transaction(function () use ($periode, $userId, $cabangList) {
            $versi = (int) DB::table('sikeutxn_snap_hdrs')->where('periode', $periode)->max('versi') + 1;
            $snapId = (int) DB::table('sikeutxn_snap_hdrs')->insertGetId([
                'periode' => $periode, 'versi' => $versi, 'status' => self::STATUS_PROSES,
                'jumlah_cabang' => count($cabangList), 'diambil_oleh' => $userId,
                'mulai_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ], 'snap_id');

            foreach (array_chunk($cabangList, self::UKURAN_SISIP) as $kelompok) {
                DB::table('sikeutxn_snap_cabangs')->insert(array_map(fn (array $cabang) => [
                    'snap_id' => $snapId, 'cabang_kunci' => (string) $cabang['kunci'], 'cabang_no' => (int) $cabang['no'],
                    'sumber' => (string) ($cabang['sumber'] ?? ''), 'label_cabang' => mb_substr((string) ($cabang['label'] ?? ''), 0, 1000),
                    'status' => 'B',
                ], $kelompok));
            }

            return $snapId;
        });
    }

    /**
     * Proses cabang berikutnya sampai $batasDetik habis (minimal satu cabang).
     * @return array{status: string, cabang_selesai: int, jumlah_cabang: int, pesan: ?string}
     */
    public function prosesBerikut(int $snapId, int $batasDetik = 8): array
    {
        $header = DB::table('sikeutxn_snap_hdrs')->where('snap_id', $snapId)->first();
        if (!$header) {
            throw new RuntimeException('Snapshot tidak ditemukan.');
        }
        if ($header->status !== self::STATUS_PROSES) {
            return $this->kemajuan($snapId);
        }

        $tahun = substr($header->periode, 0, 4);
        $bulan = substr($header->periode, 4, 2);
        $mulai = microtime(true);

        try {
            do {
                $cabang = DB::table('sikeutxn_snap_cabangs')->where('snap_id', $snapId)->where('status', 'B')
                    ->orderBy('cabang_no')->first();
                if (!$cabang) {
                    $this->selesaikan($snapId);
                    break;
                }
                $data = SirusApi::get("jurnal/{$tahun}/{$bulan}", ['cabang' => $cabang->cabang_no]);
                if (($data['cabang']['kunci'] ?? null) !== $cabang->cabang_kunci) {
                    throw new RuntimeException("Katalog cabang SIRUS berubah selama pengambilan (cabang no {$cabang->cabang_no}). Ambil ulang dari awal.");
                }
                $this->simpanCabang($snapId, $cabang->cabang_kunci, $data['baris'] ?? []);
            } while (microtime(true) - $mulai < $batasDetik);
        } catch (\Throwable $exception) {
            DB::table('sikeutxn_snap_hdrs')->where('snap_id', $snapId)->update([
                'status' => self::STATUS_GAGAL, 'keterangan' => mb_substr($exception->getMessage(), 0, 1000), 'updated_at' => now(),
            ]);
        }

        return $this->kemajuan($snapId);
    }

    /** Simpan baris satu cabang. Baris cabang dikunci (FOR UPDATE) → dua pemroses bersamaan tidak menyisipkan dua kali. */
    private function simpanCabang(int $snapId, string $cabangKunci, array $barisList): void
    {
        $sisipList = [];
        $jejakList = [];
        $totalDebit = 0.0;
        $totalKredit = 0.0;
        foreach ($barisList as $baris) {
            $kunci = self::kunciBaris($cabangKunci, $baris);
            $debit = self::nominal($baris['txn_d'] ?? 0);
            $kredit = self::nominal($baris['txn_k'] ?? 0);
            $totalDebit += (float) $debit;
            $totalKredit += (float) $kredit;
            $jejakList[] = "{$kunci}|{$debit}|{$kredit}";
            $sisipList[] = [
                'snap_id' => $snapId, 'cabang_kunci' => $cabangKunci, 'kunci_baris' => $kunci,
                'txn_name' => mb_substr((string) ($baris['txn_name'] ?? ''), 0, 500),
                'txn_acc' => $baris['txn_acc'] ?? null, 'txn_acc_k' => $baris['txn_acc_k'] ?? null,
                'shift' => $baris['shift'] ?? null, 'txn_date' => $baris['txn_date'] ?? null,
                'txn_d' => $debit, 'txn_k' => $kredit,
            ];
        }
        sort($jejakList);

        DB::transaction(function () use ($snapId, $cabangKunci, $sisipList, $jejakList, $totalDebit, $totalKredit) {
            $masihBelum = DB::table('sikeutxn_snap_cabangs')->where('snap_id', $snapId)->where('cabang_kunci', $cabangKunci)
                ->where('status', 'B')->lockForUpdate()->exists();
            if (!$masihBelum) {
                return; // sudah diproses pemroses lain
            }
            foreach (array_chunk($sisipList, self::UKURAN_SISIP) as $kelompok) {
                DB::table('sikeutxn_snap_dtls')->insert($kelompok);
            }
            DB::table('sikeutxn_snap_cabangs')->where('snap_id', $snapId)->where('cabang_kunci', $cabangKunci)->update([
                'status' => 'S', 'jumlah_baris' => count($sisipList),
                'total_debit' => round($totalDebit, 2), 'total_kredit' => round($totalKredit, 2),
                'checksum' => sha1(implode("\n", $jejakList)),
            ]);
            DB::table('sikeutxn_snap_hdrs')->where('snap_id', $snapId)->update([
                'cabang_selesai' => DB::table('sikeutxn_snap_cabangs')->where('snap_id', $snapId)->where('status', 'S')->count(),
                'updated_at' => now(),
            ]);
        });
    }

    private function selesaikan(int $snapId): void
    {
        $cabangList = DB::table('sikeutxn_snap_cabangs')->where('snap_id', $snapId)->orderBy('cabang_kunci')
            ->get(['cabang_kunci', 'checksum', 'jumlah_baris', 'total_debit', 'total_kredit']);
        DB::table('sikeutxn_snap_hdrs')->where('snap_id', $snapId)->where('status', self::STATUS_PROSES)->update([
            'status' => self::STATUS_SELESAI,
            'cabang_selesai' => $cabangList->count(),
            'jumlah_baris' => (int) $cabangList->sum('jumlah_baris'),
            'total_debit' => round((float) $cabangList->sum('total_debit'), 2),
            'total_kredit' => round((float) $cabangList->sum('total_kredit'), 2),
            'checksum' => sha1($cabangList->map(fn ($cabang) => "{$cabang->cabang_kunci}|{$cabang->checksum}")->implode("\n")),
            'selesai_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{status: string, cabang_selesai: int, jumlah_cabang: int, pesan: ?string} */
    public function kemajuan(int $snapId): array
    {
        $header = DB::table('sikeutxn_snap_hdrs')->where('snap_id', $snapId)->first();

        return [
            'status' => (string) ($header->status ?? self::STATUS_GAGAL),
            'cabang_selesai' => (int) ($header->cabang_selesai ?? 0),
            'jumlah_cabang' => (int) ($header->jumlah_cabang ?? 0),
            'pesan' => $header->keterangan ?? null,
        ];
    }

    // ─── Banding ──────────────────────────────────────────────────────────────

    /**
     * Bandingkan dua versi (snapshot lama → baru).
     * @return array{cabang: array<int, object>, baris: array<int, array>, jumlah_selisih: int, terpotong: bool}
     */
    public function bandingkan(int $snapLama, int $snapBaru): array
    {
        $cabangBeda = DB::select(
            'SELECT NVL(a.cabang_kunci, b.cabang_kunci) cabang_kunci, NVL(a.cabang_no, b.cabang_no) cabang_no,
                    NVL(a.label_cabang, b.label_cabang) label_cabang,
                    a.jumlah_baris baris_lama, b.jumlah_baris baris_baru,
                    a.total_debit debit_lama, b.total_debit debit_baru, a.total_kredit kredit_lama, b.total_kredit kredit_baru
               FROM (SELECT * FROM sikeutxn_snap_cabangs WHERE snap_id = ?) a
               FULL OUTER JOIN (SELECT * FROM sikeutxn_snap_cabangs WHERE snap_id = ?) b ON a.cabang_kunci = b.cabang_kunci
              WHERE a.checksum IS NULL OR b.checksum IS NULL OR a.checksum <> b.checksum
              ORDER BY 2',
            [$snapLama, $snapBaru],
        );

        $barisSelisih = [];
        $jumlahSelisih = 0;
        foreach (array_chunk(array_map(fn ($cabang) => $cabang->cabang_kunci, $cabangBeda), 900) as $kunciCabangList) {
            $tempat = implode(',', array_fill(0, count($kunciCabangList), '?'));
            $agregat = "SELECT kunci_baris, cabang_kunci, MAX(txn_name) txn_name, MAX(txn_acc) txn_acc, MAX(txn_acc_k) txn_acc_k,
                               MAX(shift) shift, MAX(txn_date) txn_date, COUNT(*) jumlah, SUM(txn_d) debit, SUM(txn_k) kredit
                          FROM sikeutxn_snap_dtls WHERE snap_id = ? AND cabang_kunci IN ({$tempat})
                         GROUP BY kunci_baris, cabang_kunci";
            $sql = "SELECT * FROM (
                      SELECT NVL(a.cabang_kunci, b.cabang_kunci) cabang_kunci, NVL(a.txn_name, b.txn_name) txn_name,
                             a.kunci_baris kunci_lama, b.kunci_baris kunci_baru,
                             a.txn_acc acc_lama, b.txn_acc acc_baru, a.txn_acc_k lawan_lama, b.txn_acc_k lawan_baru,
                             a.shift shift_lama, b.shift shift_baru, a.txn_date tanggal_lama, b.txn_date tanggal_baru,
                             a.jumlah jumlah_lama, b.jumlah jumlah_baru, a.debit debit_lama, b.debit debit_baru,
                             a.kredit kredit_lama, b.kredit kredit_baru
                        FROM ({$agregat}) a FULL OUTER JOIN ({$agregat}) b ON a.kunci_baris = b.kunci_baris
                       WHERE a.kunci_baris IS NULL OR b.kunci_baris IS NULL
                          OR a.jumlah <> b.jumlah OR a.debit <> b.debit OR a.kredit <> b.kredit
                       ORDER BY NVL(a.txn_date, b.txn_date), 2
                    ) WHERE ROWNUM <= ?";
            $rows = DB::select($sql, [$snapLama, ...$kunciCabangList, $snapBaru, ...$kunciCabangList, self::BATAS_BARIS_SELISIH + 1]);
            $jumlahSelisih += count($rows);
            foreach ($rows as $row) {
                $barisSelisih[] = (array) $row;
            }
        }

        $terpotong = count($barisSelisih) > self::BATAS_BARIS_SELISIH;
        $barisSelisih = array_slice($barisSelisih, 0, self::BATAS_BARIS_SELISIH);

        return [
            'cabang' => $cabangBeda,
            'baris' => self::golongkan($barisSelisih),
            'jumlah_selisih' => $jumlahSelisih,
            'terpotong' => $terpotong,
        ];
    }

    /**
     * Beri jenis tiap baris selisih: baru / hilang / berubah. Pasangan hilang+baru dengan cabang & label sama
     * dipasangkan berurutan jadi satu baris "berubah" (tanggal / akun / shift diganti).
     */
    private static function golongkan(array $barisSelisih): array
    {
        $hasil = [];
        $hilangPerLabel = [];
        $baruPerLabel = [];
        foreach ($barisSelisih as $baris) {
            if ($baris['kunci_lama'] !== null && $baris['kunci_baru'] !== null) {
                $hasil[] = ['jenis' => 'berubah'] + $baris;
            } elseif ($baris['kunci_baru'] === null) {
                $hilangPerLabel[$baris['cabang_kunci'] . '|' . $baris['txn_name']][] = $baris;
            } else {
                $baruPerLabel[$baris['cabang_kunci'] . '|' . $baris['txn_name']][] = $baris;
            }
        }

        foreach ($hilangPerLabel as $label => $hilangList) {
            foreach ($hilangList as $hilang) {
                // Satu label bisa punya beberapa baris (mis. resep berisi banyak obat): pasangkan dengan baris baru
                // yang PALING MIRIP, bukan sekadar urutan — nominal sama dulu, lalu akun, lalu tanggal.
                $urutanTerbaik = null;
                $skorTerbaik = -1;
                foreach ($baruPerLabel[$label] ?? [] as $urutan => $kandidat) {
                    $skor = ((float) $kandidat['debit_baru'] === (float) $hilang['debit_lama'] && (float) $kandidat['kredit_baru'] === (float) $hilang['kredit_lama'] ? 4 : 0)
                        + ($kandidat['acc_baru'] === $hilang['acc_lama'] && $kandidat['lawan_baru'] === $hilang['lawan_lama'] ? 2 : 0)
                        + ($kandidat['tanggal_baru'] === $hilang['tanggal_lama'] ? 1 : 0);
                    if ($skor > $skorTerbaik) {
                        $skorTerbaik = $skor;
                        $urutanTerbaik = $urutan;
                    }
                }
                if ($urutanTerbaik === null) {
                    $hasil[] = ['jenis' => 'hilang'] + $hilang;
                    continue;
                }
                $baru = $baruPerLabel[$label][$urutanTerbaik];
                unset($baruPerLabel[$label][$urutanTerbaik]);
                $gabung = $hilang;
                foreach (['kunci_baru', 'acc_baru', 'lawan_baru', 'shift_baru', 'tanggal_baru', 'jumlah_baru', 'debit_baru', 'kredit_baru'] as $kolom) {
                    $gabung[$kolom] = $baru[$kolom];
                }
                $hasil[] = ['jenis' => 'berubah'] + $gabung;
            }
        }
        foreach ($baruPerLabel as $baruList) {
            foreach ($baruList as $baru) {
                $hasil[] = ['jenis' => 'baru'] + $baru;
            }
        }

        usort($hasil, fn ($kiri, $kanan) => [$kiri['tanggal_lama'] ?? $kiri['tanggal_baru'], $kiri['txn_name']]
            <=> [$kanan['tanggal_lama'] ?? $kanan['tanggal_baru'], $kanan['txn_name']]);

        return $hasil;
    }

    /** Apa saja yang berubah pada baris "berubah" — untuk keterangan di layar. */
    public static function perubahan(array $baris): array
    {
        $daftar = [];
        if (($baris['tanggal_lama'] ?? null) !== ($baris['tanggal_baru'] ?? null)) { $daftar[] = 'tanggal'; }
        if (($baris['acc_lama'] ?? null) !== ($baris['acc_baru'] ?? null) || ($baris['lawan_lama'] ?? null) !== ($baris['lawan_baru'] ?? null)) { $daftar[] = 'akun'; }
        if (($baris['shift_lama'] ?? null) !== ($baris['shift_baru'] ?? null)) { $daftar[] = 'shift'; }
        if ((float) ($baris['debit_lama'] ?? 0) !== (float) ($baris['debit_baru'] ?? 0) || (float) ($baris['kredit_lama'] ?? 0) !== (float) ($baris['kredit_baru'] ?? 0)) { $daftar[] = 'nominal'; }
        if ((int) ($baris['jumlah_lama'] ?? 0) !== (int) ($baris['jumlah_baru'] ?? 0)) { $daftar[] = 'jumlah baris'; }

        return $daftar;
    }
}
