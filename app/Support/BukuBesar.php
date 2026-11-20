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
 *
 * TUTUP BUKU TAHUNAN (tutupBuku / batalTutupBuku)
 *   Syarat: 12 bulan terkunci, tahun sebelumnya sudah tutup buku (kecuali tahun pertama), akun laba ditahan diatur.
 *   Jurnal sumber PENUTUP di periode Desember (31 Des): tiap akun Laba Rugi dinolkan, selisihnya ke akun laba ditahan;
 *   tahun buku & 12 periodenya berstatus T. Penyajian: jurnal penutup tahun X TIDAK ikut laporan tahun X (Laba Rugi,
 *   Neraca, Neraca Saldo, Buku Besar s/d Desember X tetap = sebelum penutupan, jadi laporan final tidak bergeser) dan
 *   baru terbaca mulai tahun X+1 — persis seperti saldo awal. Hasilnya: baris "laba tahun lalu belum ditutup" hilang,
 *   angkanya pindah ke akun laba ditahan; total Neraca tidak berubah.
 */
class BukuBesar
{
    public const STATUS_BUKA = 'B';
    public const STATUS_KUNCI = 'K';
    public const STATUS_TUTUP = 'T';

    public const SUMBER_SIRUS = 'SIRUS';
    public const SUMBER_PENUTUP = 'PENUTUP';

    public const ALASAN_MIN = 10;

    /** Awalan nama transaksi SIRUS (teks sebelum "(", mis. "OBAT RJ", "BAYAR_RJ") — dipakai SQL_KETERANGAN. */
    private const SQL_AWALAN = "TRIM(SUBSTR(d.txn_name, 1, INSTR(d.txn_name || '(', '(') - 1))";

    /** Keterangan baris jurnal SIRUS: "OBAT RJ (12 trx)" / "CI Bayar listrik dkk (3 trx)". Dipakai di GROUP BY snapshot. */
    private const SQL_KETERANGAN = "SUBSTR(CASE WHEN MIN(" . self::SQL_AWALAN . ") = MAX(" . self::SQL_AWALAN . ") THEN MIN(" . self::SQL_AWALAN . ")"
        . " ELSE MIN(" . self::SQL_AWALAN . ") || ' dkk' END || ' (' || COUNT(*) || ' trx)', 1, 500)";

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
     * Dampak Reset Tahun: posting SIRUS dibatalkan (versi sah dilepas, hasil tarikan tetap), jurnal umum terposting
     * dikembalikan jadi DRAFT (pekerjaan manual tidak hilang), saldo awal TIDAK disentuh. Ditolak bila ada bulan
     * terkunci di tahun itu atau sesudahnya (buka kunci dulu dari bulan terbaru).
     * @return array{tahun: int, periode_posting: array<int, string>, jurnal_sirus: int, jurnal_umum: int, terkunci: array<int, string>, boleh: bool, galat: ?string}
     */
    public function dampakResetTahun(int $tahun): array
    {
        $dari = sprintf('%04d01', $tahun);
        $sampai = sprintf('%04d12', $tahun);
        $hasil = [
            'tahun' => $tahun,
            'periode_posting' => DB::table('sikeumst_periodes')->whereBetween('periode', [$dari, $sampai])->whereNotNull('snap_id_sah')
                ->orderBy('periode')->pluck('periode')->map(fn ($nilai) => (string) $nilai)->all(),
            'jurnal_sirus' => DB::table('sikeutxn_jurnal_hdrs')->whereBetween('periode', [$dari, $sampai])->where('sumber', self::SUMBER_SIRUS)->where('status', 'P')->count(),
            'jurnal_umum' => DB::table('sikeutxn_jurnal_hdrs')->whereBetween('periode', [$dari, $sampai])->where('sumber', JurnalUmum::SUMBER)->where('status', 'P')->count(),
            'terkunci' => DB::table('sikeumst_periodes')->where('periode', '>=', $dari)->whereIn('status', [self::STATUS_KUNCI, self::STATUS_TUTUP])
                ->orderBy('periode')->pluck('periode')->map(fn ($nilai) => (string) $nilai)->all(),
            'boleh' => false, 'galat' => null,
        ];
        if (!DB::table('sikeumst_tahun_bukus')->where('tahun', $tahun)->exists()) {
            $hasil['galat'] = "Tahun buku {$tahun} belum dibuka.";
        } elseif ($hasil['terkunci'] !== []) {
            $hasil['galat'] = 'Masih ada ' . count($hasil['terkunci']) . " bulan terkunci di {$tahun} atau sesudahnya (terakhir "
                . SnapshotJurnal::labelPeriode((string) end($hasil['terkunci'])) . ') — buka kuncinya dulu dari bulan terbaru.';
        } elseif ($hasil['periode_posting'] === [] && $hasil['jurnal_umum'] === 0) {
            $hasil['galat'] = "Tidak ada posting di {$tahun} — tidak ada yang perlu direset.";
        } else {
            $hasil['boleh'] = true;
        }

        return $hasil;
    }

    /** Reset Tahun (lihat dampakResetTahun). Alasan wajib, tercatat di audit. */
    public function resetTahun(int $tahun, string $alasan, ?int $userId): array
    {
        $alasan = trim($alasan);
        if (mb_strlen($alasan) < self::ALASAN_MIN || !BaganAkun::teksAman($alasan)) {
            throw new RuntimeException('Alasan wajib, minimal ' . self::ALASAN_MIN . ' karakter, tanpa simbol khusus.');
        }

        return DB::transaction(function () use ($tahun, $alasan, $userId) {
            $dampak = $this->dampakResetTahun($tahun);
            if (!$dampak['boleh']) {
                throw new RuntimeException($dampak['galat'] ?? 'Tidak bisa direset.');
            }
            $dari = sprintf('%04d01', $tahun);
            $sampai = sprintf('%04d12', $tahun);
            $sekarang = now();
            DB::table('sikeutxn_jurnal_hdrs')->whereBetween('periode', [$dari, $sampai])->where('sumber', self::SUMBER_SIRUS)->where('status', 'P')
                ->update(['status' => 'B', 'dibatalkan_oleh' => $userId, 'dibatalkan_at' => $sekarang,
                    'alasan_batal' => mb_substr("Reset tahun {$tahun}: {$alasan}", 0, 500), 'updated_at' => $sekarang]);
            DB::table('sikeutxn_jurnal_hdrs')->whereBetween('periode', [$dari, $sampai])->where('sumber', JurnalUmum::SUMBER)->where('status', 'P')
                ->update(['status' => 'D', 'diposting_oleh' => null, 'diposting_at' => null, 'updated_at' => $sekarang]);
            DB::table('sikeumst_periodes')->whereBetween('periode', [$dari, $sampai])->update(['snap_id_sah' => null, 'updated_at' => $sekarang]);
            BaganAkun::catatAudit('RESET_TAHUN', 'SIKEUMST_TAHUN_BUKUS', (string) $tahun, ['periode_posting' => $dampak['periode_posting']],
                ['jurnal_sirus_dibatalkan' => $dampak['jurnal_sirus'], 'jurnal_umum_jadi_draft' => $dampak['jurnal_umum']], $alasan);

            return $dampak;
        });
    }

    /**
     * Hapus tahun buku yang belum dipakai (kembali "Belum dibuka"): hanya bila tidak ada jurnal terposting / draft,
     * tidak ada jurnal umum atau saldo awal sama sekali, dan tidak ada bulan terkunci. Jurnal SIRUS yang sudah
     * dibatalkan ikut dihapus (bisa dibuat ulang dari hasil tarikan); hasil tarikan SIRUS tetap.
     */
    public function hapusTahunBuku(int $tahun, ?int $userId): void
    {
        DB::transaction(function () use ($tahun, $userId) {
            $dari = sprintf('%04d01', $tahun);
            $sampai = sprintf('%04d12', $tahun);
            if (!DB::table('sikeumst_tahun_bukus')->where('tahun', $tahun)->exists()) {
                throw new RuntimeException("Tahun buku {$tahun} belum dibuka.");
            }
            $jurnal = DB::table('sikeutxn_jurnal_hdrs')->whereBetween('periode', [$dari, $sampai]);
            if ((clone $jurnal)->whereIn('status', ['P', 'D'])->exists() || (clone $jurnal)->where('sumber', '<>', self::SUMBER_SIRUS)->exists()) {
                throw new RuntimeException("Tahun {$tahun} sudah punya jurnal (posting, draft, jurnal umum, atau saldo awal) — pakai Reset Tahun / batalkan dulu.");
            }
            if (DB::table('sikeumst_periodes')->whereBetween('periode', [$dari, $sampai])->where('status', '<>', self::STATUS_BUKA)->exists()
                || DB::table('sikeumst_periodes')->whereBetween('periode', [$dari, $sampai])->whereNotNull('snap_id_sah')->exists()) {
                throw new RuntimeException("Tahun {$tahun} masih punya bulan terkunci / terposting.");
            }
            foreach (['sikeutxn_aset_susuts', 'sikeutxn_hpp_hdrs', 'sikeutxn_saldo_akuns'] as $tabel) {
                if (DB::table($tabel)->whereBetween('periode', [$dari, $sampai])->exists()) {
                    throw new RuntimeException("Tahun {$tahun} sudah dipakai di {$tabel}.");
                }
            }
            $batalList = (clone $jurnal)->pluck('jurnal_id');
            if ($batalList->isNotEmpty()) {
                foreach ($batalList->chunk(500) as $potongan) {
                    DB::table('sikeutxn_jurnal_dtls')->whereIn('jurnal_id', $potongan->all())->delete();
                    DB::table('sikeutxn_jurnal_hdrs')->whereIn('jurnal_id', $potongan->all())->delete();
                }
            }
            DB::table('sikeumst_periodes')->whereBetween('periode', [$dari, $sampai])->delete();
            DB::table('sikeumst_tahun_bukus')->where('tahun', $tahun)->delete();
            BaganAkun::catatAudit('HAPUS_TAHUN', 'SIKEUMST_TAHUN_BUKUS', (string) $tahun, ['tahun' => $tahun, 'jurnal_batal_dihapus' => $batalList->count()], null);
        });
    }

    /**
     * Ringkasan 12 periode satu tahun untuk layar Tarik Data SIRUS.
     * @return array<int, array<string, mixed>>
     */
    public function periodeList(int $tahun): array
    {
        $periodeRows = DB::table('sikeumst_periodes as p')
            ->leftJoin('sikeutxn_snap_hdrs as s', 's.snap_id', '=', 'p.snap_id_sah')
            ->where('p.tahun', $tahun)->orderBy('p.periode')
            ->get(['p.periode', 'p.bulan', 'p.status', 'p.snap_id_sah', 'p.dikunci_at', 'p.dibuka_at', 'p.alasan_buka', 's.versi as versi_sah']);
        $snapshotList = DB::table('sikeutxn_snap_hdrs')->where('periode', 'like', $tahun . '%')->where('status', 'S')
            ->orderBy('versi')->get(['snap_id', 'periode', 'versi', 'jumlah_baris', 'total_debit', 'checksum', 'selesai_at'])->groupBy('periode');
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
                // Tarikan terbaru dibanding versi yang disahkan: null (tidak ada yang lebih baru) / 'sama' / 'beda' (checksum)
                'versi_terbaru' => $versiTerbaru ? (int) $versiTerbaru->versi : null,
                'snap_id_terbaru' => $versiTerbaru ? (int) $versiTerbaru->snap_id : null,
                'status_versi_baru' => $this->statusVersiBaru($versiList, $row),
                'jurnal_sirus' => $jurnal->firstWhere('sumber', self::SUMBER_SIRUS),
                'total_debit' => (float) $jurnal->sum('debit'),
                'total_kredit' => (float) $jurnal->sum('kredit'),
                'dikunci_at' => $row->dikunci_at,
                'alasan_buka' => $row->alasan_buka,
            ];
        })->all();
    }

    private function statusVersiBaru($versiList, object $row): ?string
    {
        $versiTerbaru = $versiList->last();
        if (!$versiTerbaru || !$row->snap_id_sah || (int) $versiTerbaru->snap_id === (int) $row->snap_id_sah) {
            return null;
        }
        $versiSah = $versiList->firstWhere('snap_id', $row->snap_id_sah);

        return $versiSah && $versiSah->checksum === $versiTerbaru->checksum ? 'sama' : 'beda';
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
             WHERE x.acc IS NOT NULL
               AND (p.acc_id_sirus IS NULL OR (p.dibukukan = '1' AND (a.akun_id IS NULL OR a.tipe <> 'D' OR a.aktif <> '1')))
             ORDER BY x.acc",
            [$snapId, $snapId]
        );

        return array_map(fn ($row) => (string) $row->acc, $rows);
    }

    /**
     * Bulan sebelum tahun pertama SIKEU (pengaturan pembukuan.tahun_mulai) TIDAK boleh dibukukan: saldonya akan ikut
     * terbawa ke tahun pertama padahal saldo awal tahun pertama sudah diisi dari neraca penutup → terhitung dua kali.
     * Tarik data & bandingkan versi tetap boleh (hanya untuk dicek).
     */
    public static function wajibMulaiTahunPertama(string $periode): void
    {
        $tahunMulai = SaldoAwal::tahunMulai();
        if ((int) substr($periode, 0, 4) < $tahunMulai) {
            throw new RuntimeException('Periode ' . SnapshotJurnal::labelPeriode($periode) . " sebelum tahun pertama SIKEU ({$tahunMulai}) — hanya boleh ditarik untuk dicek, tidak dibukukan (saldonya sudah tercakup di saldo awal {$tahunMulai}).");
        }
    }

    /**
     * Batalkan seluruh posting (jurnal SIRUS & jurnal umum) di periode sebelum tahun pertama SIKEU yang terlanjur
     * dibukukan; versi sah dilepas, hasil tarikan tetap tersimpan. Periode terkunci ikut dibuka (tercatat di audit).
     * @return array{periode: array<int, string>, jurnal: int}
     */
    public function batalkanSebelumTahunMulai(?int $userId): array
    {
        $tahunMulai = SaldoAwal::tahunMulai();
        $batas = sprintf('%04d01', $tahunMulai);

        return DB::transaction(function () use ($batas, $tahunMulai, $userId) {
            $sekarang = now();
            $periodeList = DB::table('sikeumst_periodes')->where('periode', '<', $batas)
                ->where(fn ($subQuery) => $subQuery->whereNotNull('snap_id_sah')->orWhere('status', '<>', self::STATUS_BUKA))
                ->orderBy('periode')->pluck('periode')->map(fn ($periode) => (string) $periode)->all();
            $jumlahJurnal = DB::table('sikeutxn_jurnal_hdrs')->where('periode', '<', $batas)->where('status', 'P')
                ->where('sumber', '<>', SaldoAwal::SUMBER)
                ->update(['status' => 'B', 'dibatalkan_oleh' => $userId, 'dibatalkan_at' => $sekarang,
                    'alasan_batal' => "Sebelum tahun pertama SIKEU ({$tahunMulai})", 'updated_at' => $sekarang]);
            DB::table('sikeumst_periodes')->where('periode', '<', $batas)
                ->update(['snap_id_sah' => null, 'status' => self::STATUS_BUKA, 'updated_at' => $sekarang]);
            BaganAkun::catatAudit('BATAL_SEBELUM_TAHUN_MULAI', 'SIKEUMST_PERIODES', "<{$batas}", ['periode' => $periodeList],
                ['jurnal_dibatalkan' => $jumlahJurnal]);

            return ['periode' => $periodeList, 'jurnal' => $jumlahJurnal];
        });
    }

    /**
     * Akun SIRUS yang diabaikan (dibukukan = '0') dan nilainya di satu snapshot — untuk jendela pengesahan.
     * Nilai = jumlah debit baris MILIK akun itu (sisi lawannya ikut terbuang).
     * @return array<int, array{acc: string, nama: string, debit: float, kredit: float}>
     */
    public function akunDiabaikan(int $snapId): array
    {
        return array_map(fn ($row) => ['acc' => (string) $row->acc, 'nama' => (string) $row->nama, 'debit' => (float) $row->debit, 'kredit' => (float) $row->kredit],
            DB::select(
                "SELECT p.acc_id_sirus AS acc, p.nama_sirus AS nama, NVL(SUM(d.txn_d), 0) AS debit, NVL(SUM(d.txn_k), 0) AS kredit
                   FROM sikeumst_peta_akun_sirus p
                   LEFT JOIN sikeutxn_snap_dtls d ON d.snap_id = ? AND d.txn_acc = p.acc_id_sirus
                  WHERE p.dibukukan = '0'
                  GROUP BY p.acc_id_sirus, p.nama_sirus ORDER BY p.acc_id_sirus",
                [$snapId]
            ));
    }

    /**
     * Pratinjau dampak mengabaikan satu akun SIRUS: transaksi lawan yang ikut terbuang, dari tarikan selesai terakhir.
     * @return array{snap: ?object, lawan: array<int, array{acc: string, nama: ?string, debit: float, kredit: float}>, debit: float, kredit: float}
     */
    public function dampakAbaikan(string $accIdSirus): array
    {
        // tarikan selesai terakhir yang MEMUAT akun ini (tarikan terbaru bisa saja bulan tanpa transaksi akun tsb.)
        $snap = DB::table('sikeutxn_snap_hdrs as h')->where('h.status', 'S')
            ->whereExists(fn ($subQuery) => $subQuery->selectRaw('1')->from('sikeutxn_snap_dtls as d')
                ->whereColumn('d.snap_id', 'h.snap_id')->where('d.txn_acc', $accIdSirus))
            ->orderByDesc('h.selesai_at')->first(['h.snap_id', 'h.periode', 'h.versi']);
        if (!$snap) {
            return ['snap' => null, 'lawan' => [], 'debit' => 0.0, 'kredit' => 0.0];
        }
        $rows = DB::select(
            "SELECT d.txn_acc_k AS acc, MAX(p.nama_sirus) AS nama, NVL(SUM(d.txn_d), 0) AS debit, NVL(SUM(d.txn_k), 0) AS kredit
               FROM sikeutxn_snap_dtls d LEFT JOIN sikeumst_peta_akun_sirus p ON p.acc_id_sirus = d.txn_acc_k
              WHERE d.snap_id = ? AND d.txn_acc = ? AND (d.txn_d <> 0 OR d.txn_k <> 0)
              GROUP BY d.txn_acc_k ORDER BY NVL(SUM(d.txn_d), 0) + NVL(SUM(d.txn_k), 0) DESC",
            [$snap->snap_id, $accIdSirus]
        );
        $lawan = array_map(fn ($row) => ['acc' => (string) $row->acc, 'nama' => $row->nama, 'debit' => (float) $row->debit, 'kredit' => (float) $row->kredit], $rows);

        return ['snap' => $snap, 'lawan' => $lawan, 'debit' => array_sum(array_column($lawan, 'debit')), 'kredit' => array_sum(array_column($lawan, 'kredit'))];
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
        self::wajibMulaiTahunPertama($periode);
        $belumSiap = $this->akunBelumSiap($snapId);
        if ($belumSiap !== []) {
            throw new RuntimeException('Akun SIRUS belum dipetakan ke akun Detail aktif: ' . implode(', ', array_slice($belumSiap, 0, 10))
                . (count($belumSiap) > 10 ? ' (+' . (count($belumSiap) - 10) . ' lagi)' : '') . '. Lengkapi di Data Induk › Peta Akun SIRUS.');
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
                        "INSERT INTO sikeutxn_jurnal_dtls (jurnal_id, urut, akun_id, akun_lawan_id, {$kolomIsi}, {$kolomNol}, cabang_kunci, keterangan)
                         SELECT ?, ? + ROWNUM, akun_id, akun_lawan_id, nilai, 0, cabang_kunci, keterangan FROM (
                            SELECT pa.akun_id, pl.akun_id AS akun_lawan_id, d.cabang_kunci, SUM(d.{$kolomSirus}) AS nilai,
                                   " . self::SQL_KETERANGAN . " AS keterangan
                              FROM sikeutxn_snap_dtls d
                              JOIN sikeumst_peta_akun_sirus pa ON pa.acc_id_sirus = d.txn_acc
                              JOIN sikeumst_peta_akun_sirus pl ON pl.acc_id_sirus = d.txn_acc_k
                             WHERE d.snap_id = ? AND d.{$kolomSirus} > 0
                               AND pa.dibukukan = '1' AND pl.dibukukan = '1'
                               AND d.txn_date >= TO_DATE(?, 'YYYY-MM-DD') AND d.txn_date < TO_DATE(?, 'YYYY-MM-DD') + 1
                             GROUP BY pa.akun_id, pl.akun_id, d.cabang_kunci
                             ORDER BY pa.akun_id, pl.akun_id, d.cabang_kunci)",
                        [$jurnalId, $urutTerakhir, $snapshot->snap_id, $tanggal, $tanggal]
                    );
                }

                $total = DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $jurnalId)
                    ->selectRaw('COUNT(*) AS baris, NVL(SUM(debit), 0) AS debit, NVL(SUM(kredit), 0) AS kredit')->first();
                if ((int) $total->baris === 0) {
                    DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $jurnalId)->delete(); // seluruh baris hari itu diabaikan
                    continue;
                }
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

            // Pembanding = total snapshot TANPA baris yang menyentuh akun SIRUS yang diabaikan (dibukukan = '0')
            $diharapkan = DB::selectOne(
                "SELECT NVL(SUM(d.txn_d), 0) AS debit, NVL(SUM(d.txn_k), 0) AS kredit, COUNT(*) AS baris
                   FROM sikeutxn_snap_dtls d
                   JOIN sikeumst_peta_akun_sirus pa ON pa.acc_id_sirus = d.txn_acc
                   JOIN sikeumst_peta_akun_sirus pl ON pl.acc_id_sirus = d.txn_acc_k
                  WHERE d.snap_id = ? AND pa.dibukukan = '1' AND pl.dibukukan = '1'",
                [$snapshot->snap_id]
            );
            if (round($totalDebit - (float) $diharapkan->debit, 2) != 0.0 || round($totalKredit - (float) $diharapkan->kredit, 2) != 0.0) {
                throw new RuntimeException("Total posting (D {$totalDebit} / K {$totalKredit}) ≠ total snapshot yang dibukukan (D {$diharapkan->debit} / K {$diharapkan->kredit}) — posting dibatalkan.");
            }
            $diabaikan = ['debit' => round((float) $snapshot->total_debit - (float) $diharapkan->debit, 2),
                'kredit' => round((float) $snapshot->total_kredit - (float) $diharapkan->kredit, 2)];

            DB::table('sikeumst_periodes')->where('periode', $periode)->update(['snap_id_sah' => $snapshot->snap_id, 'updated_at' => $sekarang]);
            BaganAkun::catatAudit('SAHKAN_POSTING', 'SIKEUMST_PERIODES', $periode,
                ['snap_id_sah' => $periodeRow->snap_id_sah], ['snap_id_sah' => (int) $snapshot->snap_id, 'versi' => (int) $snapshot->versi,
                    'jurnal' => $jumlahJurnal, 'baris' => $jumlahBaris, 'debit' => $totalDebit, 'jurnal_dibatalkan' => $dibatalkan,
                    'tidak_dibukukan' => $diabaikan]);

            return ['jurnal' => $jumlahJurnal, 'baris' => $jumlahBaris, 'debit' => $totalDebit, 'kredit' => $totalKredit, 'dibatalkan' => $dibatalkan,
                'diabaikan' => $diabaikan];
        });
    }

    /**
     * Isi keterangan baris jurnal SIRUS yang masih kosong (posting sebelum keterangan diisi otomatis) dari snapshotnya.
     * @return int jumlah baris yang diisi
     */
    public function isiKeteranganSirus(string $periode): int
    {
        $jumlah = 0;
        $jurnalList = DB::table('sikeutxn_jurnal_hdrs')->where('periode', $periode)->where('sumber', self::SUMBER_SIRUS)
            ->where('status', 'P')->get(['jurnal_id', 'referensi_id', 'tanggal']);
        foreach ($jurnalList as $jurnal) {
            $tanggal = substr((string) $jurnal->tanggal, 0, 10);
            foreach (['debit' => 'txn_d', 'kredit' => 'txn_k'] as $kolomJurnal => $kolomSirus) {
                $jumlah += DB::update(
                    "UPDATE sikeutxn_jurnal_dtls j SET keterangan = (
                        SELECT " . self::SQL_KETERANGAN . "
                          FROM sikeutxn_snap_dtls d
                          JOIN sikeumst_peta_akun_sirus pa ON pa.acc_id_sirus = d.txn_acc
                          JOIN sikeumst_peta_akun_sirus pl ON pl.acc_id_sirus = d.txn_acc_k
                         WHERE d.snap_id = ? AND d.cabang_kunci = j.cabang_kunci AND pa.akun_id = j.akun_id AND pl.akun_id = j.akun_lawan_id
                           AND d.{$kolomSirus} > 0
                           AND d.txn_date >= TO_DATE(?, 'YYYY-MM-DD') AND d.txn_date < TO_DATE(?, 'YYYY-MM-DD') + 1)
                     WHERE j.jurnal_id = ? AND j.keterangan IS NULL AND j.{$kolomJurnal} > 0",
                    [(int) $jurnal->referensi_id, $tanggal, $tanggal, $jurnal->jurnal_id]
                );
            }
        }

        return $jumlah;
    }

    /** Label cabang SIRUS yang bisa dibaca: literal pertama ekspresi label ('BAYAR_RJ (' || ... → "BAYAR_RJ"). */
    public static function labelCabangBaca(?string $labelCabang): string
    {
        if ($labelCabang !== null && preg_match("/^'([^']*)'/", trim($labelCabang), $cocok)) {
            return trim($cocok[1], " (");
        }

        return '';
    }

    // ─── Kunci periode ───────────────────────────────────────────────────────

    /**
     * Bulan yang wajib dikunci berikutnya: bulan pertama sejak 01/tahun pertama SIKEU yang belum Kunci/Tutup —
     * termasuk bulan di tahun yang BELUM DIBUKA (tidak dilewati), jadi 2024 harus terkunci penuh sebelum 2025, dst.
     * @return array{periode: string, ada: bool} ada = baris periodenya sudah ada (tahunnya sudah dibuka)
     */
    public static function giliranKunci(): array
    {
        $periode = sprintf('%04d01', SaldoAwal::tahunMulai());
        $terkunci = DB::table('sikeumst_periodes')->where('periode', '>=', $periode)
            ->whereIn('status', [self::STATUS_KUNCI, self::STATUS_TUTUP])->pluck('periode')
            ->map(fn ($nilai) => (string) $nilai)->flip()->all();
        while (isset($terkunci[$periode])) {
            $periode = SnapshotJurnal::periodeBerikutnya($periode);
        }

        return ['periode' => $periode, 'ada' => DB::table('sikeumst_periodes')->where('periode', $periode)->exists()];
    }

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
            $draftUmum = DB::table('sikeutxn_jurnal_hdrs')->where('periode', $periode)->where('status', 'D')->where('sumber', '<>', 'SALDO_AWAL')->count();
            if ($draftUmum > 0) {
                throw new RuntimeException("Masih ada {$draftUmum} draft jurnal di periode ini — posting atau hapus dulu sebelum mengunci.");
            }
            // urutan kunci mulai 01/tahun pertama SIKEU, tanpa melewati tahun yang belum dibuka
            self::wajibMulaiTahunPertama($periode);
            $giliran = self::giliranKunci();
            if ($giliran['periode'] !== $periode) {
                $label = SnapshotJurnal::labelPeriode($giliran['periode']);
                throw new RuntimeException($giliran['ada']
                    ? "Periode {$label} masih Buka — kunci berurutan dari bulan terlama."
                    : 'Tahun ' . substr($giliran['periode'], 0, 4) . " belum dibuka — buka tahun bukunya, proses, lalu kunci mulai {$label}.");
            }

            $this->simpanSaldoAkun($periode);
            $sekarang = now();
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
            if ($periodeRow && $periodeRow->status === self::STATUS_TUTUP) {
                throw new RuntimeException('Tahun ' . substr($periode, 0, 4) . ' sudah tutup buku — batalkan tutup bukunya dulu di Pengaturan Pembukuan.');
            }
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

    /** Simpan ulang saldo akun satu periode ke sikeutxn_saldo_akuns (saat kunci, dan saat tutup buku mengubah saldo awal). */
    private function simpanSaldoAkun(string $periode): void
    {
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
    }

    // ─── Tutup buku tahunan ──────────────────────────────────────────────────

    /**
     * Saldo akun Laba Rugi satu tahun (Jan–Des, jurnal P, tanpa jurnal penutup), bertanda + debit / − kredit.
     * @return array<int, array{akun_id: string, nama: string, saldo: float}>
     */
    public function saldoLabaRugiTahun(int $tahun): array
    {
        $rows = DB::select(
            "SELECT a.akun_id, a.nama, NVL(SUM(d.debit - d.kredit), 0) AS saldo
               FROM sikeutxn_jurnal_dtls d
               JOIN sikeutxn_jurnal_hdrs h ON h.jurnal_id = d.jurnal_id
               JOIN sikeumst_akuns a ON a.akun_id = d.akun_id
               JOIN sikeumst_akun_kelompoks k ON k.kelompok_id = a.kelompok_id
              WHERE h.status = 'P' AND h.periode >= ? AND h.periode <= ? AND k.jenis_laporan = 'L'
                AND h.sumber <> '" . self::SUMBER_PENUTUP . "'
              GROUP BY a.akun_id, a.nama
              ORDER BY a.akun_id",
            [sprintf('%04d01', $tahun), sprintf('%04d12', $tahun)]
        );
        $hasil = [];
        foreach ($rows as $row) {
            $saldo = round((float) $row->saldo, 2);
            if ($saldo != 0.0) {
                $hasil[] = ['akun_id' => (string) $row->akun_id, 'nama' => (string) $row->nama, 'saldo' => $saldo];
            }
        }

        return $hasil;
    }

    /**
     * Pratinjau & syarat Tutup Buku: tahun buku Buka, 12 bulan terkunci, tahun sebelumnya sudah tutup buku
     * (kecuali tahun pertama SIKEU), akun laba ditahan = Detail Neraca aktif. laba = + laba / − rugi.
     * @return array{tahun: int, boleh: bool, galat: ?string, akun: array, laba: float, akun_ditahan: ?string, nama_ditahan: ?string, bulan_sesudah_terkunci: int}
     */
    public function dampakTutupBuku(int $tahun): array
    {
        $hasil = ['tahun' => $tahun, 'boleh' => false, 'galat' => null, 'akun' => [], 'laba' => 0.0,
            'akun_ditahan' => null, 'nama_ditahan' => null, 'bulan_sesudah_terkunci' => 0];
        $tahunBuku = DB::table('sikeumst_tahun_bukus')->where('tahun', $tahun)->first();
        if (!$tahunBuku) {
            $hasil['galat'] = "Tahun buku {$tahun} belum dibuka.";
            return $hasil;
        }
        $hasil['akun'] = $this->saldoLabaRugiTahun($tahun);
        $hasil['laba'] = round(-array_sum(array_column($hasil['akun'], 'saldo')), 2);
        $akunDitahan = (string) (SaldoAwal::akunLaba()['ditahan'] ?? '');
        $ditahan = $akunDitahan === '' ? null : DB::table('sikeumst_akuns as a')
            ->join('sikeumst_akun_kelompoks as k', 'k.kelompok_id', '=', 'a.kelompok_id')
            ->where('a.akun_id', $akunDitahan)->where('a.tipe', 'D')->where('a.aktif', '1')->where('k.jenis_laporan', 'N')
            ->first(['a.akun_id', 'a.nama']);
        $hasil['akun_ditahan'] = $ditahan ? (string) $ditahan->akun_id : null;
        $hasil['nama_ditahan'] = $ditahan ? (string) $ditahan->nama : null;
        $hasil['bulan_sesudah_terkunci'] = DB::table('sikeumst_periodes')->where('periode', '>', sprintf('%04d12', $tahun))
            ->whereIn('status', [self::STATUS_KUNCI, self::STATUS_TUTUP])->count();

        $belumKunci = DB::table('sikeumst_periodes')->where('tahun', $tahun)->where('status', '<>', self::STATUS_KUNCI)
            ->orderBy('periode')->pluck('periode')->map(fn ($nilai) => (string) $nilai)->all();
        $tahunMulai = SaldoAwal::tahunMulai();
        if ($tahunBuku->status === self::STATUS_TUTUP) {
            $hasil['galat'] = "Tahun {$tahun} sudah tutup buku.";
        } elseif ($tahun < $tahunMulai) {
            $hasil['galat'] = "Tahun {$tahun} sebelum tahun pertama SIKEU ({$tahunMulai}) — tidak dibukukan.";
        } elseif ($tahun > $tahunMulai && DB::table('sikeumst_tahun_bukus')->where('tahun', $tahun - 1)->value('status') !== self::STATUS_TUTUP) {
            $hasil['galat'] = 'Tahun ' . ($tahun - 1) . ' belum tutup buku — tutup berurutan dari tahun terlama.';
        } elseif ($belumKunci !== [] || DB::table('sikeumst_periodes')->where('tahun', $tahun)->count() !== 12) {
            $hasil['galat'] = 'Masih ada ' . count($belumKunci) . ' bulan yang belum terkunci'
                . ($belumKunci !== [] ? ' (pertama ' . SnapshotJurnal::labelPeriode($belumKunci[0]) . ')' : '') . ' — kunci ke-12 bulan dulu di Tarik Data SIRUS.';
        } elseif ($hasil['akun_ditahan'] === null) {
            $hasil['galat'] = 'Akun laba ditahan belum diatur atau bukan akun Detail Neraca aktif — atur di bagian 3 halaman ini.';
        } else {
            $hasil['boleh'] = true;
        }

        return $hasil;
    }

    /**
     * Tutup buku: jurnal PENUTUP 31 Desember (akun Laba Rugi dinolkan ke akun laba ditahan), tahun buku & 12 periodenya
     * berstatus T, saldo tersimpan bulan-bulan terkunci sesudahnya dihitung ulang (saldo awalnya kini memuat laba ditahan).
     * @return array dampakTutupBuku + no_jurnal, bulan_dihitung
     */
    public function tutupBuku(int $tahun, ?int $userId): array
    {
        return DB::transaction(function () use ($tahun, $userId) {
            DB::table('sikeumst_tahun_bukus')->where('tahun', $tahun)->lockForUpdate()->first();
            $dampak = $this->dampakTutupBuku($tahun);
            if (!$dampak['boleh']) {
                throw new RuntimeException($dampak['galat'] ?? 'Tahun ini belum bisa ditutup buku.');
            }
            $periode = sprintf('%04d12', $tahun);
            $sekarang = now();
            $akunDitahan = (string) $dampak['akun_ditahan'];
            $jurnalId = null;
            $noJurnal = null;

            if ($dampak['akun'] !== []) {
                $barisList = array_map(fn (array $akun) => [
                    'akun_id' => $akun['akun_id'], 'akun_lawan_id' => $akunDitahan,
                    'debit' => max(-$akun['saldo'], 0), 'kredit' => max($akun['saldo'], 0),
                    'keterangan' => "Penutup tahun {$tahun}",
                ], $dampak['akun']);
                // Selisih ke laba ditahan: laba = kredit, rugi = debit
                $barisList[] = ['akun_id' => $akunDitahan, 'akun_lawan_id' => null,
                    'debit' => max(-$dampak['laba'], 0), 'kredit' => max($dampak['laba'], 0),
                    'keterangan' => ($dampak['laba'] >= 0 ? 'Laba' : 'Rugi') . " tahun {$tahun} ke saldo laba"];
                $barisList = array_values(array_filter($barisList, fn (array $baris) => $baris['debit'] != 0.0 || $baris['kredit'] != 0.0));

                $urutNomor = 1 + DB::table('sikeutxn_jurnal_hdrs')->where('periode', $periode)->where('sumber', self::SUMBER_PENUTUP)->count();
                $noJurnal = sprintf('%s-%s-%02d', self::SUMBER_PENUTUP, $periode, $urutNomor);
                $jurnalId = (int) DB::table('sikeutxn_jurnal_hdrs')->insertGetId([
                    'no_jurnal' => $noJurnal, 'periode' => $periode, 'tanggal' => sprintf('%04d-12-31', $tahun),
                    'sumber' => self::SUMBER_PENUTUP, 'referensi_tabel' => 'SIKEUMST_TAHUN_BUKUS', 'referensi_id' => (string) $tahun,
                    'keterangan' => "Jurnal penutup tahun {$tahun}: saldo akun Laba Rugi ke saldo laba (laba ditahan)",
                    'status' => 'D', 'dibuat_oleh' => $userId, 'created_at' => $sekarang,
                ], 'jurnal_id');
                foreach (array_chunk($barisList, 200, true) as $potongan) {
                    DB::table('sikeutxn_jurnal_dtls')->insert(array_map(fn (array $baris, int $indeks) => [
                        'jurnal_id' => $jurnalId, 'urut' => $indeks + 1, 'akun_id' => $baris['akun_id'], 'akun_lawan_id' => $baris['akun_lawan_id'],
                        'debit' => $baris['debit'], 'kredit' => $baris['kredit'], 'keterangan' => $baris['keterangan'],
                    ], $potongan, array_keys($potongan)));
                }
                $total = DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $jurnalId)
                    ->selectRaw('NVL(SUM(debit), 0) AS debit, NVL(SUM(kredit), 0) AS kredit')->first();
                if (round((float) $total->debit, 2) !== round((float) $total->kredit, 2)) {
                    throw new RuntimeException("Jurnal penutup tidak seimbang (D {$total->debit} / K {$total->kredit}) — tutup buku dibatalkan.");
                }
                DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $jurnalId)->update([
                    'total_debit' => $total->debit, 'total_kredit' => $total->kredit, 'status' => 'P',
                    'diposting_oleh' => $userId, 'diposting_at' => $sekarang, 'updated_at' => $sekarang,
                ]);
            }

            $akunBerjalan = (string) (SaldoAwal::akunLaba()['berjalan'] ?? '');
            DB::table('sikeumst_tahun_bukus')->where('tahun', $tahun)->update([
                'status' => self::STATUS_TUTUP, 'akun_laba_ditahan_id' => $akunDitahan,
                'akun_laba_berjalan_id' => DB::table('sikeumst_akuns')->where('akun_id', $akunBerjalan)->exists() ? $akunBerjalan : null,
                'jurnal_penutup_id' => $jurnalId, 'ditutup_oleh' => $userId, 'ditutup_at' => $sekarang, 'updated_at' => $sekarang,
            ]);
            DB::table('sikeumst_periodes')->where('tahun', $tahun)->update(['status' => self::STATUS_TUTUP, 'updated_at' => $sekarang]);
            $bulanDihitung = $this->hitungUlangSaldoSesudah($tahun);
            BaganAkun::catatAudit('TUTUP_BUKU', 'SIKEUMST_TAHUN_BUKUS', (string) $tahun, ['status' => self::STATUS_BUKA],
                ['status' => self::STATUS_TUTUP, 'jurnal' => $noJurnal, 'laba' => $dampak['laba'], 'akun_ditahan' => $akunDitahan]);

            return $dampak + ['no_jurnal' => $noJurnal, 'bulan_dihitung' => $bulanDihitung];
        });
    }

    /**
     * Syarat Batal Tutup Buku: tahun berstatus T dan tahun sesudahnya belum tutup buku (batal dari tahun terbaru).
     * @return array{tahun: int, boleh: bool, galat: ?string, no_jurnal: ?string, laba: float, ditutup_at: mixed}
     */
    public function dampakBatalTutupBuku(int $tahun): array
    {
        $tahunBuku = DB::table('sikeumst_tahun_bukus')->where('tahun', $tahun)->first();
        $jurnal = $tahunBuku && $tahunBuku->jurnal_penutup_id
            ? DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $tahunBuku->jurnal_penutup_id)->first(['no_jurnal']) : null;
        $hasil = ['tahun' => $tahun, 'boleh' => false, 'galat' => null, 'no_jurnal' => $jurnal->no_jurnal ?? null,
            'laba' => round(-array_sum(array_column($this->saldoLabaRugiTahun($tahun), 'saldo')), 2), 'ditutup_at' => $tahunBuku->ditutup_at ?? null];
        if (!$tahunBuku || $tahunBuku->status !== self::STATUS_TUTUP) {
            $hasil['galat'] = "Tahun {$tahun} belum tutup buku.";
        } elseif (DB::table('sikeumst_tahun_bukus')->where('tahun', '>', $tahun)->where('status', self::STATUS_TUTUP)->exists()) {
            $hasil['galat'] = 'Tahun sesudahnya sudah tutup buku — batalkan berurutan dari tahun terbaru.';
        } else {
            $hasil['boleh'] = true;
        }

        return $hasil;
    }

    /** Batal Tutup Buku: jurnal penutup → status B (tetap tersimpan), tahun & 12 periodenya kembali Kunci. Alasan wajib. */
    public function batalTutupBuku(int $tahun, string $alasan, ?int $userId): array
    {
        $alasan = trim($alasan);
        if (mb_strlen($alasan) < self::ALASAN_MIN || !BaganAkun::teksAman($alasan)) {
            throw new RuntimeException('Alasan wajib, minimal ' . self::ALASAN_MIN . ' karakter, tanpa simbol khusus.');
        }

        return DB::transaction(function () use ($tahun, $alasan, $userId) {
            $tahunBuku = DB::table('sikeumst_tahun_bukus')->where('tahun', $tahun)->lockForUpdate()->first();
            $dampak = $this->dampakBatalTutupBuku($tahun);
            if (!$dampak['boleh']) {
                throw new RuntimeException($dampak['galat'] ?? 'Tutup buku tidak bisa dibatalkan.');
            }
            $sekarang = now();
            if ($tahunBuku->jurnal_penutup_id) {
                DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $tahunBuku->jurnal_penutup_id)->where('status', 'P')->update([
                    'status' => 'B', 'dibatalkan_oleh' => $userId, 'dibatalkan_at' => $sekarang,
                    'alasan_batal' => mb_substr("Batal tutup buku {$tahun}: {$alasan}", 0, 500), 'updated_at' => $sekarang,
                ]);
            }
            DB::table('sikeumst_tahun_bukus')->where('tahun', $tahun)->update([
                'status' => self::STATUS_BUKA, 'jurnal_penutup_id' => null, 'ditutup_oleh' => null, 'ditutup_at' => null, 'updated_at' => $sekarang,
            ]);
            DB::table('sikeumst_periodes')->where('tahun', $tahun)->update(['status' => self::STATUS_KUNCI, 'updated_at' => $sekarang]);
            $bulanDihitung = $this->hitungUlangSaldoSesudah($tahun);
            BaganAkun::catatAudit('BATAL_TUTUP_BUKU', 'SIKEUMST_TAHUN_BUKUS', (string) $tahun, ['status' => self::STATUS_TUTUP, 'jurnal' => $dampak['no_jurnal']],
                ['status' => self::STATUS_BUKA], $alasan);

            return $dampak + ['bulan_dihitung' => $bulanDihitung];
        });
    }

    /** Hitung ulang saldo tersimpan bulan-bulan terkunci sesudah Desember $tahun (saldo awalnya berubah karena tutup buku). */
    private function hitungUlangSaldoSesudah(int $tahun): int
    {
        $periodeList = DB::table('sikeumst_periodes')->where('periode', '>', sprintf('%04d12', $tahun))
            ->whereIn('status', [self::STATUS_KUNCI, self::STATUS_TUTUP])->orderBy('periode')->pluck('periode');
        foreach ($periodeList as $periode) {
            $this->simpanSaldoAkun((string) $periode);
        }

        return $periodeList->count();
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
                AND NOT (h.sumber = '" . self::SUMBER_PENUTUP . "' AND h.periode >= ?)
              GROUP BY a.akun_id, a.nama, a.kelompok_id, a.saldo_normal, k.jenis_laporan
              ORDER BY a.akun_id",
            [$periode, $periode, $periode, $periode, $periode, $awalTahun, $awalTahun]
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
            "SELECT h.jurnal_id, h.no_jurnal, h.tanggal, h.sumber, h.referensi_id, h.keterangan AS keterangan_jurnal, d.akun_lawan_id, l.nama AS nama_lawan, d.cabang_kunci,
                    MAX(c.label_cabang) AS label_cabang, MAX(d.keterangan) AS keterangan, SUM(d.debit) AS debit, SUM(d.kredit) AS kredit
               FROM sikeutxn_jurnal_dtls d
               JOIN sikeutxn_jurnal_hdrs h ON h.jurnal_id = d.jurnal_id
               LEFT JOIN sikeumst_akuns l ON l.akun_id = d.akun_lawan_id
               LEFT JOIN sikeutxn_snap_cabangs c ON h.sumber = 'SIRUS' AND c.snap_id = TO_NUMBER(h.referensi_id) AND c.cabang_kunci = d.cabang_kunci
              WHERE h.status = 'P' AND d.akun_id = ? AND h.periode >= ? AND h.periode <= ?
                AND NOT (h.sumber = 'SALDO_AWAL' AND h.periode = ?)
                AND NOT (h.sumber = '" . self::SUMBER_PENUTUP . "' AND h.periode >= ?)
              GROUP BY h.jurnal_id, h.no_jurnal, h.tanggal, h.sumber, h.referensi_id, h.keterangan, d.akun_lawan_id, l.nama, d.cabang_kunci
              ORDER BY h.tanggal, h.jurnal_id, d.cabang_kunci, d.akun_lawan_id",
            [$akunId, $periodeDari, $periodeSampai, $periodeDari, substr($periodeSampai, 0, 4) . '01']
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
