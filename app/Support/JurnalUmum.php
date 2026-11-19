<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * JURNAL UMUM / PENYESUAIAN — jurnal sumber MANUAL (koreksi, akrual, reklasifikasi, penyusutan sementara, dst.).
 * Kelas instance (yajra insertGetId butuh frame objek).
 *
 *   Draft (D)  : boleh disimpan walau belum seimbang; baris diganti utuh tiap simpan. Hak jurnal.entri.
 *   Posting (P): wajib seimbang & > 0, periode Buka. Hak jurnal.posting (pemisahan pembuat vs pengesah).
 *   Batal (B)  : jurnal P di periode Buka → status B + alasan (tetap tersimpan, keluar dari saldo).
 *                Periode sudah dikunci → buat jurnal pembalik di periode yang masih Buka (salin, D/K ditukar).
 *
 * Nomor: JU-YYYYMM-NNNN per periode. Baris: satu sisi saja (debit ATAU kredit) — aturan CHECK di DDL.
 * Jurnal dua baris mengisi akun_lawan_id masing-masing (dasar arus kas metode langsung).
 */
class JurnalUmum
{
    public const SUMBER = 'MANUAL';

    public const MAKS_BARIS = 200;

    public static function periodeDariTanggal(string $tanggal): string
    {
        return substr(str_replace('-', '', $tanggal), 0, 6);
    }

    private function wajibPeriodeBuka(string $periode): void
    {
        $periodeRow = DB::table('sikeumst_periodes')->where('periode', $periode)->first();
        if (!$periodeRow) {
            throw new RuntimeException('Periode ' . SnapshotJurnal::labelPeriode($periode) . ' belum dibuka — buka tahun bukunya di Periode & Pengesahan.');
        }
        if ($periodeRow->status !== BukuBesar::STATUS_BUKA) {
            throw new RuntimeException('Periode ' . SnapshotJurnal::labelPeriode($periode) . ' sudah dikunci — pilih tanggal di periode yang masih Buka.');
        }
    }

    private function jurnal(int $jurnalId): object
    {
        $jurnal = DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $jurnalId)->where('sumber', self::SUMBER)->first();
        if (!$jurnal) {
            throw new RuntimeException('Jurnal umum tidak ditemukan.');
        }

        return $jurnal;
    }

    /**
     * Validasi & normalisasi baris: [{akun_id, debit, kredit, keterangan}] → baris bersih (nilai 2 desimal).
     * @return array<int, array{akun_id: string, debit: float, kredit: float, keterangan: ?string}>
     */
    public function bersihkanBaris(array $barisList): array
    {
        $akunSah = DB::table('sikeumst_akuns')->where('tipe', 'D')->where('aktif', '1')->pluck('nama', 'akun_id')->all();
        $hasilList = [];
        foreach (array_values($barisList) as $nomor => $baris) {
            $akunId = trim((string) ($baris['akun_id'] ?? ''));
            $debit = round((float) ($baris['debit'] ?? 0), 2);
            $kredit = round((float) ($baris['kredit'] ?? 0), 2);
            $keterangan = trim((string) ($baris['keterangan'] ?? ''));
            if ($akunId === '' && $debit == 0.0 && $kredit == 0.0) {
                continue; // baris kosong diabaikan
            }
            $urutan = $nomor + 1;
            if (!isset($akunSah[$akunId])) {
                throw new RuntimeException("Baris {$urutan}: akun harus akun Detail yang aktif.");
            }
            if ($debit < 0 || $kredit < 0) {
                throw new RuntimeException("Baris {$urutan}: nominal tidak boleh negatif — pakai sisi sebaliknya.");
            }
            if (($debit > 0) === ($kredit > 0)) {
                throw new RuntimeException("Baris {$urutan}: isi debit ATAU kredit (salah satu, lebih dari nol).");
            }
            if (!BaganAkun::teksAman($keterangan)) {
                throw new RuntimeException("Baris {$urutan}: keterangan hanya huruf, angka & tanda baca biasa.");
            }
            $hasilList[] = ['akun_id' => $akunId, 'debit' => $debit, 'kredit' => $kredit, 'keterangan' => $keterangan !== '' ? mb_substr($keterangan, 0, 500) : null];
        }
        if (count($hasilList) > self::MAKS_BARIS) {
            throw new RuntimeException('Maksimal ' . self::MAKS_BARIS . ' baris per jurnal.');
        }

        return $hasilList;
    }

    /** Simpan draft baru / ubah draft. @return int jurnal_id */
    public function simpanDraft(?int $jurnalId, string $tanggal, string $keterangan, array $barisList, ?int $userId): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || !checkdate((int) substr($tanggal, 5, 2), (int) substr($tanggal, 8, 2), (int) substr($tanggal, 0, 4))) {
            throw new RuntimeException('Tanggal tidak sah.');
        }
        $keterangan = trim($keterangan);
        if (mb_strlen($keterangan) < 5 || !BaganAkun::teksAman($keterangan)) {
            throw new RuntimeException('Keterangan jurnal wajib (minimal 5 karakter, tanpa simbol khusus) — tulis dasar / alasannya.');
        }
        $barisBersih = $this->bersihkanBaris($barisList);
        if (count($barisBersih) < 2) {
            throw new RuntimeException('Jurnal minimal dua baris (satu debit, satu kredit).');
        }
        $periode = self::periodeDariTanggal($tanggal);

        return DB::transaction(function () use ($jurnalId, $tanggal, $keterangan, $barisBersih, $periode, $userId) {
            $this->wajibPeriodeBuka($periode);
            $sekarang = now();
            if ($jurnalId !== null) {
                $lama = $this->jurnal($jurnalId);
                if ($lama->status !== 'D') {
                    throw new RuntimeException('Hanya draft yang bisa diubah — jurnal terposting dibatalkan / dibalik.');
                }
                $isian = ['tanggal' => $tanggal, 'keterangan' => mb_substr($keterangan, 0, 500), 'updated_at' => $sekarang];
                if ($lama->periode !== $periode) {
                    $isian += ['periode' => $periode, 'no_jurnal' => $this->nomorBaru($periode)];
                }
                DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $jurnalId)->update($isian);
                DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $jurnalId)->delete();
            }
            if ($jurnalId === null) {
                $jurnalId = (int) DB::table('sikeutxn_jurnal_hdrs')->insertGetId([
                    'no_jurnal' => $this->nomorBaru($periode), 'periode' => $periode, 'tanggal' => $tanggal,
                    'sumber' => self::SUMBER, 'keterangan' => mb_substr($keterangan, 0, 500), 'status' => 'D',
                    'dibuat_oleh' => $userId, 'created_at' => $sekarang,
                ], 'jurnal_id');
            }
            $duaBaris = count($barisBersih) === 2;
            foreach ($barisBersih as $indeks => $baris) {
                DB::table('sikeutxn_jurnal_dtls')->insert([
                    'jurnal_id' => $jurnalId, 'urut' => $indeks + 1, 'akun_id' => $baris['akun_id'],
                    'akun_lawan_id' => $duaBaris ? $barisBersih[1 - $indeks]['akun_id'] : null,
                    'debit' => $baris['debit'], 'kredit' => $baris['kredit'], 'keterangan' => $baris['keterangan'],
                ]);
            }
            DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $jurnalId)->update([
                'total_debit' => round(array_sum(array_column($barisBersih, 'debit')), 2),
                'total_kredit' => round(array_sum(array_column($barisBersih, 'kredit')), 2),
            ]);
            BaganAkun::catatAudit('SIMPAN_DRAFT', 'SIKEUTXN_JURNAL_HDRS', (string) $jurnalId, null,
                ['tanggal' => $tanggal, 'baris' => count($barisBersih)]);

            return $jurnalId;
        });
    }

    /** Nomor JU-YYYYMM-NNNN berikutnya (unik per periode; urutan dari nomor tertinggi yang ada). */
    private function nomorBaru(string $periode): string
    {
        $awalan = "JU-{$periode}-";
        $terakhir = DB::table('sikeutxn_jurnal_hdrs')->where('no_jurnal', 'like', $awalan . '%')->pluck('no_jurnal')
            ->map(fn ($nomor) => (int) substr((string) $nomor, strlen($awalan)))->max() ?? 0;

        return $awalan . str_pad((string) ($terakhir + 1), 4, '0', STR_PAD_LEFT);
    }

    public function posting(int $jurnalId, ?int $userId): void
    {
        DB::transaction(function () use ($jurnalId, $userId) {
            $jurnal = DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $jurnalId)->where('sumber', self::SUMBER)->lockForUpdate()->first();
            if (!$jurnal || $jurnal->status !== 'D') {
                throw new RuntimeException('Hanya draft yang bisa diposting.');
            }
            $this->wajibPeriodeBuka((string) $jurnal->periode);
            $total = DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $jurnalId)
                ->selectRaw('COUNT(*) AS baris, NVL(SUM(debit), 0) AS debit, NVL(SUM(kredit), 0) AS kredit')->first();
            if ((int) $total->baris < 2 || (float) $total->debit <= 0) {
                throw new RuntimeException('Jurnal kosong / kurang dari dua baris.');
            }
            if (round((float) $total->debit - (float) $total->kredit, 2) != 0.0) {
                throw new RuntimeException('Jurnal belum seimbang: debit ' . Format::uang($total->debit) . ' vs kredit ' . Format::uang($total->kredit) . '.');
            }
            $sekarang = now();
            DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $jurnalId)->update([
                'total_debit' => $total->debit, 'total_kredit' => $total->kredit, 'status' => 'P',
                'diposting_oleh' => $userId, 'diposting_at' => $sekarang, 'updated_at' => $sekarang,
            ]);
            BaganAkun::catatAudit('POSTING', 'SIKEUTXN_JURNAL_HDRS', (string) $jurnalId, ['status' => 'D'], ['status' => 'P', 'total' => (float) $total->debit]);
        });
    }

    public function batal(int $jurnalId, string $alasan, ?int $userId): void
    {
        $alasan = trim($alasan);
        if (mb_strlen($alasan) < BukuBesar::ALASAN_MIN || !BaganAkun::teksAman($alasan)) {
            throw new RuntimeException('Alasan batal wajib, minimal ' . BukuBesar::ALASAN_MIN . ' karakter, tanpa simbol khusus.');
        }
        DB::transaction(function () use ($jurnalId, $alasan, $userId) {
            $jurnal = DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $jurnalId)->where('sumber', self::SUMBER)->lockForUpdate()->first();
            if (!$jurnal || $jurnal->status !== 'P') {
                throw new RuntimeException('Hanya jurnal terposting yang bisa dibatalkan.');
            }
            $this->wajibPeriodeBuka((string) $jurnal->periode);
            $sekarang = now();
            DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $jurnalId)->update([
                'status' => 'B', 'dibatalkan_oleh' => $userId, 'dibatalkan_at' => $sekarang, 'alasan_batal' => $alasan, 'updated_at' => $sekarang,
            ]);
            BaganAkun::catatAudit('BATAL', 'SIKEUTXN_JURNAL_HDRS', (string) $jurnalId, ['status' => 'P'], ['status' => 'B'], $alasan);
        });
    }

    public function hapusDraft(int $jurnalId): void
    {
        DB::transaction(function () use ($jurnalId) {
            $jurnal = $this->jurnal($jurnalId);
            if ($jurnal->status !== 'D') {
                throw new RuntimeException('Hanya draft yang bisa dihapus.');
            }
            DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $jurnalId)->delete();
            DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $jurnalId)->delete();
            BaganAkun::catatAudit('HAPUS_DRAFT', 'SIKEUTXN_JURNAL_HDRS', (string) $jurnalId, ['no_jurnal' => $jurnal->no_jurnal], null);
        });
    }

    /** Header + baris satu jurnal umum (untuk form / tampilan). */
    public function ambil(int $jurnalId): array
    {
        $jurnal = $this->jurnal($jurnalId);
        $barisList = DB::table('sikeutxn_jurnal_dtls as d')->leftJoin('sikeumst_akuns as a', 'a.akun_id', '=', 'd.akun_id')
            ->where('d.jurnal_id', $jurnalId)->orderBy('d.urut')
            ->get(['d.akun_id', 'a.nama', 'd.debit', 'd.kredit', 'd.keterangan'])->map(fn ($row) => (array) $row)->all();

        return ['jurnal' => $jurnal, 'baris' => $barisList];
    }
}
