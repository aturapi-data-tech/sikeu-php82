<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BUKU BESAR SIKEU — tahun buku, periode, posting snapshot SIRUS, kunci periode, neraca saldo, mutasi akun.
 * Kelas instance (bukan static) karena yajra insertGetId butuh frame objek.
 *
 * POSTING SNAPSHOT
 *   Versi snapshot (status S) yang DISAHKAN untuk satu periode diposting ke sikeutxn_jurnal_*:
 *   - satu jurnal per HARI (sumber SIRUS), baris = Σ per (tanggal, cabang, akun SIKEU, akun lawan SIKEU, sisi D/K);
 *     akun dari peta akun SIRUS (sikeumst_peta_akun_sirus). Baris SIRUS sudah berpasangan cermin (tiap transaksi
 *     muncul di akun & lawannya), jadi tiap hari otomatis seimbang. Rincian tetap di snapshot (telusur lewat cabang_kunci).
 *   - ditolak bila ada akun SIRUS di snapshot yang belum dipetakan / tujuannya bukan Detail aktif.
 *   - posting ulang (versi lain disahkan) hanya selama periode Buka: jurnal SIRUS lama periode itu → status B (batal,
 *     tetap tersimpan sebagai jejak), lalu jurnal versi baru diposting. Periode Kunci/Tutup menolak.
 *
 * SALDO (neraca saldo)
 *   saldo = Σ (debit − kredit) jurnal berstatus P; jurnal sumber SALDO_AWAL (App\Support\SaldoAwal) dihitung sebagai
 *   saldo AWAL periodenya, bukan mutasi. Akun Neraca (kelompok N) kumulatif sejak awal; akun Laba Rugi
 *   (kelompok L) hanya sejak Januari tahun berjalan — laba/rugi tahun-tahun sebelumnya yang belum ditutup buku
 *   ditampilkan sebagai satu baris "Laba (rugi) tahun lalu belum ditutup buku" supaya neraca saldo tetap seimbang.
 */
class BukuBesar
{
    public const STATUS_BUKA = 'B';
    public const STATUS_KUNCI = 'K';
    public const STATUS_TUTUP = 'T';

    public const SUMBER_SIRUS = 'SIRUS';

    public const ALASAN_MIN = 10;

    // ─── Tahun buku & periode ────────────────────────────────────────────────

    /** Buat tahun buku + 12 periode berstatus Buka (yang sudah ada dibiarkan). */
    public function bukaTahun(int $tahun, ?int $userId): void
    {
        if ($tahun < 2000 || $tahun > 2100) {
            throw new RuntimeException('Tahun tidak sah.');
        }
        DB::transaction(function () use ($tahun, $userId) {
            if (!DB::table('sikeumst_tahun_bukus')->where('tahun', $tahun)->exists()) {
                DB::table('sikeumst_tahun_bukus')->insert(['tahun' => $tahun, 'status' => self::STATUS_BUKA, 'created_at' => now()]);
                BaganAkun::catatAudit('BUKA_TAHUN', 'SIKEUMST_TAHUN_BUKUS', (string) $tahun, null, ['tahun' => $tahun]);
            }
            for ($bulan = 1; $bulan <= 12; $bulan++) {
                $periode = sprintf('%04d%02d', $tahun, $bulan);
                if (!DB::table('sikeumst_periodes')->where('periode', $periode)->exists()) {
                    DB::table('sikeumst_periodes')->insert([
                        'periode' => $periode, 'tahun' => $tahun, 'bulan' => $bulan, 'status' => self::STATUS_BUKA, 'created_at' => now(),
                    ]);
                }
            }
        });
    }

    /**
     * Ringkasan 12 periode satu tahun untuk layar Periode & Posting.
     * @return array<int, array<string, mixed>>
     */
    public function periodeList(int $tahun): array
    {
        $periodeRows = DB::table('sikeumst_periodes as p')
            ->leftJoin('sikeutxn_snap_hdrs as s', 's.snap_id', '=', 'p.snap_id_sah')
            ->where('p.tahun', $tahun)->orderBy('p.periode')
            ->get(['p.periode', 'p.bulan', 'p.status', 'p.snap_id_sah', 'p.dikunci_at', 'p.dibuka_at', 'p.alasan_buka', 's.versi as versi_sah']);
        $snapshotList = DB::table('sikeutxn_snap_hdrs')->where('periode', 'like', $tahun . '%')->where('status', 'S')
            ->orderBy('versi')->get(['snap_id', 'periode', 'versi', 'jumlah_baris', 'total_debit', 'selesai_at'])->groupBy('periode');
        $jurnalList = DB::table('sikeutxn_jurnal_hdrs')->where('periode', 'like', $tahun . '%')->where('status', 'P')
            ->groupBy('periode', 'sumber')
            ->selectRaw('periode, sumber, COUNT(*) AS jumlah, SUM(total_debit) AS debit, SUM(total_kredit) AS kredit, MAX(diposting_at) AS terakhir')
            ->get()->groupBy('periode');

        return $periodeRows->map(function ($row) use ($snapshotList, $jurnalList) {
            $versiList = $snapshotList[$row->periode] ?? collect();
            $jurnal = $jurnalList[$row->periode] ?? collect();
            $versiTerbaru = $versiList->last();

            return [
                'periode' => $row->periode,
                'bulan' => (int) $row->bulan,
                'status' => $row->status,
                'snap_id_sah' => $row->snap_id_sah ? (int) $row->snap_id_sah : null,
                'versi_sah' => $row->versi_sah ? (int) $row->versi_sah : null,
                'versi_list' => $versiList->map(fn ($versi) => (array) $versi)->values()->all(),
                'ada_versi_baru' => $versiTerbaru && $row->versi_sah && (int) $versiTerbaru->versi > (int) $row->versi_sah,
                'jurnal_sirus' => $jurnal->firstWhere('sumber', self::SUMBER_SIRUS),
                'total_debit' => (float) $jurnal->sum('debit'),
                'total_kredit' => (float) $jurnal->sum('kredit'),
                'dikunci_at' => $row->dikunci_at,
                'alasan_buka' => $row->alasan_buka,
            ];
        })->all();
    }

    // ─── Posting snapshot ────────────────────────────────────────────────────

    /**
     * Akun SIRUS di snapshot yang belum bisa diposting (tanpa peta / tujuan bukan Detail aktif).
     * @return array<int, string>
     */
    public function akunBelumSiap(int $snapId): array
    {
        $rows = DB::select(
            "SELECT DISTINCT x.acc FROM (
                SELECT txn_acc AS acc FROM sikeutxn_snap_dtls WHERE snap_id = ? AND (txn_d <> 0 OR txn_k <> 0)
                UNION
                SELECT txn_acc_k FROM sikeutxn_snap_dtls WHERE snap_id = ? AND (txn_d <> 0 OR txn_k <> 0)
             ) x
             LEFT JOIN sikeumst_peta_akun_sirus p ON p.acc_id_sirus = x.acc
             LEFT JOIN sikeumst_akuns a ON a.akun_id = p.akun_id
             WHERE x.acc IS NOT NULL AND (a.akun_id IS NULL OR a.tipe <> 'D' OR a.aktif <> '1')
             ORDER BY x.acc",
            [$snapId, $snapId]
        );

        return array_map(fn ($row) => (string) $row->acc, $rows);
    }

    /**
     * Sahkan versi snapshot untuk periodenya lalu posting ke buku besar (mengganti posting SIRUS sebelumnya).
     * @return array{jurnal: int, baris: int, debit: float, kredit: float, dibatalkan: int}
     */
    public function sahkanDanPosting(int $snapId, ?int $userId): array
    {
        $snapshot = DB::table('sikeutxn_snap_hdrs')->where('snap_id', $snapId)->first();
        if (!$snapshot || $snapshot->status !== 'S') {
            throw new RuntimeException('Snapshot belum selesai diambil — hanya versi berstatus Selesai yang bisa disahkan.');
        }
        $periode = (string) $snapshot->periode;
        $belumSiap = $this->akunBelumSiap($snapId);
        if ($belumSiap !== []) {
            throw new RuntimeException('Akun SIRUS belum dipetakan ke akun Detail aktif: ' . implode(', ', array_slice($belumSiap, 0, 10))
                . (count($belumSiap) > 10 ? ' (+' . (count($belumSiap) - 10) . ' lagi)' : '') . '. Lengkapi di Master › Peta Akun SIRUS.');
        }

        return DB::transaction(function () use ($snapshot, $periode, $userId) {
            $periodeRow = DB::table('sikeumst_periodes')->where('periode', $periode)->lockForUpdate()->first();
            if (!$periodeRow) {
                throw new RuntimeException('Periode ' . SnapshotJurnal::labelPeriode($periode) . ' belum dibuka — buka tahun bukunya dulu.');
            }
            if ($periodeRow->status !== self::STATUS_BUKA) {
                throw new RuntimeException('Periode ' . SnapshotJurnal::labelPeriode($periode) . ' sudah dikunci — buka kuncinya dulu (wajib alasan).');
            }
            if ((int) $periodeRow->snap_id_sah === (int) $snapshot->snap_id) {
                throw new RuntimeException("Versi {$snapshot->versi} sudah disahkan & diposting untuk periode ini.");
            }

            $sekarang = now();
            $dibatalkan = DB::table('sikeutxn_jurnal_hdrs')
                ->where('periode', $periode)->where('sumber', self::SUMBER_SIRUS)->where('status', 'P')
                ->update([
                    'status' => 'B', 'dibatalkan_oleh' => $userId, 'dibatalkan_at' => $sekarang,
                    'alasan_batal' => "Diganti posting snapshot versi {$snapshot->versi}", 'updated_at' => $sekarang,
                ]);

            $postingKe = 1 + (int) DB::table('sikeutxn_jurnal_hdrs')->where('periode', $periode)->where('sumber', self::SUMBER_SIRUS)
                ->distinct()->count('referensi_id');
            $tanggalList = DB::select(
                "SELECT DISTINCT TRUNC(txn_date) AS tanggal FROM sikeutxn_snap_dtls
                  WHERE snap_id = ? AND (txn_d <> 0 OR txn_k <> 0) ORDER BY 1",
                [$snapshot->snap_id]
            );

            $jumlahJurnal = 0;
            $jumlahBaris = 0;
            $totalDebit = 0.0;
            $totalKredit = 0.0;
            foreach ($tanggalList as $tanggalRow) {
                $tanggal = substr((string) $tanggalRow->tanggal, 0, 10);
                $jurnalId = (int) DB::table('sikeutxn_jurnal_hdrs')->insertGetId([
                    'no_jurnal' => sprintf('SR-%s-P%02d-%s', $periode, $postingKe, substr($tanggal, 8, 2)),
                    'periode' => $periode,
                    'tanggal' => $tanggal,
                    'sumber' => self::SUMBER_SIRUS,
                    'referensi_tabel' => 'SIKEUTXN_SNAP_HDRS',
                    'referensi_id' => (string) $snapshot->snap_id,
                    'keterangan' => 'Jurnal SIRUS ' . date('d/m/Y', strtotime($tanggal)) . " (snapshot versi {$snapshot->versi})",
                    'status' => 'D',
                    'dibuat_oleh' => $userId,
                    'created_at' => $sekarang,
                ], 'jurnal_id');

                // Debit lalu kredit, masing-masing satu INSERT ... SELECT (tanpa UNION — wrong result Oracle 10.2.0.1)
                foreach (['txn_d' => ['debit', 'kredit'], 'txn_k' => ['kredit', 'debit']] as $kolomSirus => [$kolomIsi, $kolomNol]) {
                    $urutTerakhir = (int) DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $jurnalId)->count();
                    DB::insert(
                        "INSERT INTO sikeutxn_jurnal_dtls (jurnal_id, urut, akun_id, akun_lawan_id, {$kolomIsi}, {$kolomNol}, cabang_kunci)
                         SELECT ?, ? + ROWNUM, akun_id, akun_lawan_id, nilai, 0, cabang_kunci FROM (
                            SELECT pa.akun_id, pl.akun_id AS akun_lawan_id, d.cabang_kunci, SUM(d.{$kolomSirus}) AS nilai
                              FROM sikeutxn_snap_dtls d
                              JOIN sikeumst_peta_akun_sirus pa ON pa.acc_id_sirus = d.txn_acc
                              JOIN sikeumst_peta_akun_sirus pl ON pl.acc_id_sirus = d.txn_acc_k
                             WHERE d.snap_id = ? AND d.{$kolomSirus} > 0
                               AND d.txn_date >= TO_DATE(?, 'YYYY-MM-DD') AND d.txn_date < TO_DATE(?, 'YYYY-MM-DD') + 1
                             GROUP BY pa.akun_id, pl.akun_id, d.cabang_kunci
                             ORDER BY pa.akun_id, pl.akun_id, d.cabang_kunci)",
                        [$jurnalId, $urutTerakhir, $snapshot->snap_id, $tanggal, $tanggal]
                    );
                }

                $total = DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $jurnalId)
                    ->selectRaw('COUNT(*) AS baris, NVL(SUM(debit), 0) AS debit, NVL(SUM(kredit), 0) AS kredit')->first();
                if (round((float) $total->debit - (float) $total->kredit, 2) != 0.0) {
                    throw new RuntimeException("Jurnal {$tanggal} tidak seimbang (D {$total->debit} / K {$total->kredit}) — posting dibatalkan.");
                }
                DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $jurnalId)->update([
                    'total_debit' => $total->debit, 'total_kredit' => $total->kredit, 'status' => 'P',
                    'diposting_oleh' => $userId, 'diposting_at' => $sekarang,
                ]);
                $jumlahJurnal++;
                $jumlahBaris += (int) $total->baris;
                $totalDebit += (float) $total->debit;
                $totalKredit += (float) $total->kredit;
            }

            if (round($totalDebit - (float) $snapshot->total_debit, 2) != 0.0 || round($totalKredit - (float) $snapshot->total_kredit, 2) != 0.0) {
                throw new RuntimeException("Total posting (D {$totalDebit} / K {$totalKredit}) ≠ total snapshot (D {$snapshot->total_debit} / K {$snapshot->total_kredit}) — posting dibatalkan.");
            }

            DB::table('sikeumst_periodes')->where('periode', $periode)->update(['snap_id_sah' => $snapshot->snap_id, 'updated_at' => $sekarang]);
            BaganAkun::catatAudit('SAHKAN_POSTING', 'SIKEUMST_PERIODES', $periode,
                ['snap_id_sah' => $periodeRow->snap_id_sah], ['snap_id_sah' => (int) $snapshot->snap_id, 'versi' => (int) $snapshot->versi,
                    'jurnal' => $jumlahJurnal, 'baris' => $jumlahBaris, 'debit' => $totalDebit, 'jurnal_dibatalkan' => $dibatalkan]);

            return ['jurnal' => $jumlahJurnal, 'baris' => $jumlahBaris, 'debit' => $totalDebit, 'kredit' => $totalKredit, 'dibatalkan' => $dibatalkan];
        });
    }

    // ─── Kunci periode ───────────────────────────────────────────────────────

    /** Kunci periode: wajib sudah diposting & periode sebelumnya sudah dikunci. Saldo akun periode disimpan. */
    public function kunci(string $periode, ?int $userId): void
    {
        DB::transaction(function () use ($periode, $userId) {
            $periodeRow = DB::table('sikeumst_periodes')->where('periode', $periode)->lockForUpdate()->first();
            if (!$periodeRow || $periodeRow->status !== self::STATUS_BUKA) {
                throw new RuntimeException('Hanya periode berstatus Buka yang bisa dikunci.');
            }
            if (!$periodeRow->snap_id_sah) {
                throw new RuntimeException('Periode belum diposting — sahkan versi snapshot dulu.');
            }
            $sebelumnyaBuka = DB::table('sikeumst_periodes')->where('periode', '<', $periode)->where('status', self::STATUS_BUKA)
                ->orderBy('periode')->value('periode');
            if ($sebelumnyaBuka) {
                throw new RuntimeException('Periode ' . SnapshotJurnal::labelPeriode((string) $sebelumnyaBuka) . ' masih Buka — kunci berurutan dari bulan terlama.');
            }

            DB::table('sikeutxn_saldo_akuns')->where('periode', $periode)->delete();
            $sekarang = now();
            foreach (array_chunk($this->neracaSaldo($periode)['akun'], 200) as $potongan) {
                DB::table('sikeutxn_saldo_akuns')->insert(array_map(fn (array $row) => [
                    'periode' => $periode, 'akun_id' => $row['akun_id'],
                    'awal_debit' => max($row['awal'], 0), 'awal_kredit' => max(-$row['awal'], 0),
                    'mutasi_debit' => $row['mutasi_debit'], 'mutasi_kredit' => $row['mutasi_kredit'],
                    'akhir_debit' => max($row['akhir'], 0), 'akhir_kredit' => max(-$row['akhir'], 0),
                    'dihitung_at' => $sekarang,
                ], $potongan));
            }
            DB::table('sikeumst_periodes')->where('periode', $periode)
                ->update(['status' => self::STATUS_KUNCI, 'dikunci_oleh' => $userId, 'dikunci_at' => $sekarang, 'updated_at' => $sekarang]);
            BaganAkun::catatAudit('KUNCI', 'SIKEUMST_PERIODES', $periode, ['status' => self::STATUS_BUKA], ['status' => self::STATUS_KUNCI]);
        });
    }

    /** Buka kunci: hanya periode terkunci terakhir (bulan sesudahnya masih Buka), alasan wajib. */
    public function bukaKunci(string $periode, string $alasan, ?int $userId): void
    {
        $alasan = trim($alasan);
        if (mb_strlen($alasan) < self::ALASAN_MIN || !BaganAkun::teksAman($alasan)) {
            throw new RuntimeException('Alasan buka kunci wajib, minimal ' . self::ALASAN_MIN . ' karakter, tanpa simbol khusus.');
        }
        DB::transaction(function () use ($periode, $alasan, $userId) {
            $periodeRow = DB::table('sikeumst_periodes')->where('periode', $periode)->lockForUpdate()->first();
            if (!$periodeRow || $periodeRow->status !== self::STATUS_KUNCI) {
                throw new RuntimeException('Hanya periode berstatus Kunci yang bisa dibuka.');
            }
            $sesudahnyaKunci = DB::table('sikeumst_periodes')->where('periode', '>', $periode)
                ->whereIn('status', [self::STATUS_KUNCI, self::STATUS_TUTUP])->orderByDesc('periode')->value('periode');
            if ($sesudahnyaKunci) {
                throw new RuntimeException('Periode ' . SnapshotJurnal::labelPeriode((string) $sesudahnyaKunci) . ' masih terkunci — buka dari bulan terbaru.');
            }
            DB::table('sikeutxn_saldo_akuns')->where('periode', $periode)->delete();
            $sekarang = now();
            DB::table('sikeumst_periodes')->where('periode', $periode)->update([
                'status' => self::STATUS_BUKA, 'dibuka_oleh' => $userId, 'dibuka_at' => $sekarang, 'alasan_buka' => $alasan, 'updated_at' => $sekarang,
            ]);
            BaganAkun::catatAudit('BUKA_KUNCI', 'SIKEUMST_PERIODES', $periode, ['status' => self::STATUS_KUNCI], ['status' => self::STATUS_BUKA], $alasan);
        });
    }

    // ─── Neraca saldo & mutasi ───────────────────────────────────────────────

    /**
     * Neraca saldo satu periode: per akun Detail yang punya saldo/mutasi, nilai bertanda (+ debit / − kredit).
     * @return array{akun: array<int, array<string, mixed>>, laba_tahun_lalu: float, total: array<string, float>}
     */
    public function neracaSaldo(string $periode): array
    {
        $awalTahun = substr($periode, 0, 4) . '01';
        $rows = DB::select(
            "SELECT a.akun_id, a.nama, a.kelompok_id, a.saldo_normal, k.jenis_laporan,
                    NVL(SUM(CASE WHEN h.periode < ? OR (h.periode = ? AND h.sumber = 'SALDO_AWAL') THEN d.debit - d.kredit END), 0) AS awal,
                    NVL(SUM(CASE WHEN h.periode = ? AND h.sumber <> 'SALDO_AWAL' THEN d.debit END), 0) AS mutasi_debit,
                    NVL(SUM(CASE WHEN h.periode = ? AND h.sumber <> 'SALDO_AWAL' THEN d.kredit END), 0) AS mutasi_kredit
               FROM sikeutxn_jurnal_dtls d
               JOIN sikeutxn_jurnal_hdrs h ON h.jurnal_id = d.jurnal_id
               JOIN sikeumst_akuns a ON a.akun_id = d.akun_id
               JOIN sikeumst_akun_kelompoks k ON k.kelompok_id = a.kelompok_id
              WHERE h.status = 'P' AND h.periode <= ?
                AND (k.jenis_laporan = 'N' OR h.periode >= ?)
              GROUP BY a.akun_id, a.nama, a.kelompok_id, a.saldo_normal, k.jenis_laporan
              ORDER BY a.akun_id",
            [$periode, $periode, $periode, $periode, $periode, $awalTahun]
        );
        $labaTahunLalu = (float) (DB::selectOne(
            "SELECT NVL(SUM(d.kredit - d.debit), 0) AS nilai
               FROM sikeutxn_jurnal_dtls d
               JOIN sikeutxn_jurnal_hdrs h ON h.jurnal_id = d.jurnal_id
               JOIN sikeumst_akuns a ON a.akun_id = d.akun_id
               JOIN sikeumst_akun_kelompoks k ON k.kelompok_id = a.kelompok_id
              WHERE h.status = 'P' AND h.periode < ? AND k.jenis_laporan = 'L'",
            [$awalTahun]
        )->nilai ?? 0);

        $akunList = [];
        $total = ['awal_debit' => 0.0, 'awal_kredit' => 0.0, 'mutasi_debit' => 0.0, 'mutasi_kredit' => 0.0, 'akhir_debit' => 0.0, 'akhir_kredit' => 0.0];
        foreach ($rows as $row) {
            $awal = round((float) $row->awal, 2);
            $mutasiDebit = round((float) $row->mutasi_debit, 2);
            $mutasiKredit = round((float) $row->mutasi_kredit, 2);
            $akhir = round($awal + $mutasiDebit - $mutasiKredit, 2);
            if ($awal == 0.0 && $mutasiDebit == 0.0 && $mutasiKredit == 0.0) {
                continue;
            }
            $akunList[] = [
                'akun_id' => $row->akun_id, 'nama' => $row->nama, 'kelompok_id' => $row->kelompok_id,
                'saldo_normal' => $row->saldo_normal, 'jenis_laporan' => $row->jenis_laporan,
                'awal' => $awal, 'mutasi_debit' => $mutasiDebit, 'mutasi_kredit' => $mutasiKredit, 'akhir' => $akhir,
            ];
            $total['awal_debit'] += max($awal, 0);
            $total['awal_kredit'] += max(-$awal, 0);
            $total['mutasi_debit'] += $mutasiDebit;
            $total['mutasi_kredit'] += $mutasiKredit;
            $total['akhir_debit'] += max($akhir, 0);
            $total['akhir_kredit'] += max(-$akhir, 0);
        }
        // Laba tahun lalu belum ditutup = saldo kredit di sisi ekuitas (rugi = debit)
        $total['awal_debit'] += max(-$labaTahunLalu, 0);
        $total['awal_kredit'] += max($labaTahunLalu, 0);
        $total['akhir_debit'] += max(-$labaTahunLalu, 0);
        $total['akhir_kredit'] += max($labaTahunLalu, 0);

        return ['akun' => $akunList, 'laba_tahun_lalu' => round($labaTahunLalu, 2), 'total' => array_map(fn ($nilai) => round($nilai, 2), $total)];
    }

    /**
     * Mutasi satu akun dalam rentang periode: saldo awal + baris jurnal terposting (diringkas per jurnal & lawan & cabang).
     * @return array{saldo_awal: float, baris: array<int, array<string, mixed>>}
     */
    public function mutasiAkun(string $akunId, string $periodeDari, string $periodeSampai): array
    {
        $akun = DB::table('sikeumst_akuns as a')->join('sikeumst_akun_kelompoks as k', 'k.kelompok_id', '=', 'a.kelompok_id')
            ->where('a.akun_id', $akunId)->first(['a.akun_id', 'k.jenis_laporan']);
        if (!$akun) {
            return ['saldo_awal' => 0.0, 'baris' => []];
        }
        $awalTahun = substr($periodeDari, 0, 4) . '01';
        $saldoAwal = (float) (DB::selectOne(
            "SELECT NVL(SUM(d.debit - d.kredit), 0) AS nilai FROM sikeutxn_jurnal_dtls d
               JOIN sikeutxn_jurnal_hdrs h ON h.jurnal_id = d.jurnal_id
              WHERE h.status = 'P' AND d.akun_id = ? AND (h.periode < ? OR (h.periode = ? AND h.sumber = 'SALDO_AWAL'))"
                . ($akun->jenis_laporan === 'L' ? ' AND h.periode >= ?' : ''),
            $akun->jenis_laporan === 'L' ? [$akunId, $periodeDari, $periodeDari, $awalTahun] : [$akunId, $periodeDari, $periodeDari]
        )->nilai ?? 0);

        $rows = DB::select(
            "SELECT h.jurnal_id, h.no_jurnal, h.tanggal, h.sumber, h.referensi_id, d.akun_lawan_id, l.nama AS nama_lawan, d.cabang_kunci,
                    MAX(c.label_cabang) AS label_cabang, SUM(d.debit) AS debit, SUM(d.kredit) AS kredit
               FROM sikeutxn_jurnal_dtls d
               JOIN sikeutxn_jurnal_hdrs h ON h.jurnal_id = d.jurnal_id
               LEFT JOIN sikeumst_akuns l ON l.akun_id = d.akun_lawan_id
               LEFT JOIN sikeutxn_snap_cabangs c ON h.sumber = 'SIRUS' AND c.snap_id = TO_NUMBER(h.referensi_id) AND c.cabang_kunci = d.cabang_kunci
              WHERE h.status = 'P' AND d.akun_id = ? AND h.periode >= ? AND h.periode <= ?
                AND NOT (h.sumber = 'SALDO_AWAL' AND h.periode = ?)
              GROUP BY h.jurnal_id, h.no_jurnal, h.tanggal, h.sumber, h.referensi_id, d.akun_lawan_id, l.nama, d.cabang_kunci
              ORDER BY h.tanggal, h.jurnal_id, d.cabang_kunci, d.akun_lawan_id",
            [$akunId, $periodeDari, $periodeSampai, $periodeDari]
        );

        return ['saldo_awal' => round($saldoAwal, 2), 'baris' => array_map(fn ($row) => (array) $row, $rows)];
    }

    /**
     * Rincian transaksi SIRUS di balik satu baris buku besar (dari snapshot yang diposting).
     * @return array<int, array<string, mixed>>
     */
    public function rincianSirus(int $jurnalId, string $akunId, ?string $akunLawanId, string $cabangKunci, int $batas = 500): array
    {
        $jurnal = DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $jurnalId)->where('sumber', self::SUMBER_SIRUS)->first();
        if (!$jurnal) {
            return [];
        }
        $tanggal = substr((string) $jurnal->tanggal, 0, 10);

        return array_map(fn ($row) => (array) $row, DB::select(
            "SELECT * FROM (
                SELECT d.txn_name, d.txn_acc, d.txn_acc_k, d.shift, d.txn_date, d.txn_d, d.txn_k
                  FROM sikeutxn_snap_dtls d
                  JOIN sikeumst_peta_akun_sirus pa ON pa.acc_id_sirus = d.txn_acc
                  JOIN sikeumst_peta_akun_sirus pl ON pl.acc_id_sirus = d.txn_acc_k
                 WHERE d.snap_id = ? AND d.cabang_kunci = ? AND pa.akun_id = ? AND pl.akun_id = ?
                   AND (d.txn_d <> 0 OR d.txn_k <> 0)
                   AND d.txn_date >= TO_DATE(?, 'YYYY-MM-DD') AND d.txn_date < TO_DATE(?, 'YYYY-MM-DD') + 1
                 ORDER BY d.txn_date, d.txn_name)
              WHERE ROWNUM <= ?",
            [(int) $jurnal->referensi_id, $cabangKunci, $akunId, (string) $akunLawanId, $tanggal, $tanggal, $batas]
        ));
    }
}
