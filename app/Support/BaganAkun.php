<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aturan bersama Bagan Akun (sikeumst_akuns) & Peta Akun SIRUS (sikeumst_peta_akun_sirus).
 *
 * Format kode (jumlah tingkat, digit, pemisah, nomor kontra) TIDAK di sini — dari pengaturan, lihat
 * App\Support\FormatKodeAkun. Tingkat 1 = kelompok (dibuat di Master › Kelompok Akun). Di bawahnya akun dibuat
 * dari Bagan Akun di bawah header mana pun; tingkat terakhir selalu Detail, tingkat di atasnya boleh Header/Detail.
 * Hanya akun Detail yang boleh dijurnal.
 */
class BaganAkun
{
    private const CACHE_AKUN_SIRUS = 'sikeu.sirus-akun-list';

    public const LABEL_ARUS_KAS = ['O' => 'Operasi', 'I' => 'Investasi', 'F' => 'Pendanaan'];

    public const LABEL_KONTROL_HP = ['H' => 'Hutang', 'P' => 'Piutang'];

    /** Tingkat akun baru = tingkat induk + 1; induk sah = Header yang belum di tingkat terakhir. */
    public static function tingkatAnak(object $induk): ?int
    {
        $tingkatAnak = (int) $induk->tingkat + 1;

        return $induk->tipe === 'H' && $tingkatAnak <= FormatKodeAkun::jumlahTingkat() ? $tingkatAnak : null;
    }

    /** Header aktif yang boleh menjadi induk akun baru (semua tingkat kecuali terakhir). */
    public static function indukList(): array
    {
        return DB::table('sikeumst_akuns')->where('tipe', 'H')->where('tingkat', '<', FormatKodeAkun::jumlahTingkat())
            ->where('aktif', '1')->orderBy('akun_id')->get(['akun_id', 'nama', 'tingkat'])->all();
    }

    /**
     * Akun detail aktif dikelompokkan per golongan — untuk pilihan tujuan peta akun.
     * @return array<string, array{nama: string, akun: array<string, string>}>
     */
    public static function detailPerGolongan(): array
    {
        $golonganList = [];
        $rows = DB::table('sikeumst_akuns as a')->join('sikeumst_akuns as g', 'g.akun_id', '=', 'a.induk_id')
            ->where('a.tipe', 'D')->where('a.aktif', '1')->orderBy('a.akun_id')
            ->get(['a.akun_id', 'a.nama', 'a.induk_id', 'g.nama as nama_induk']);
        foreach ($rows as $row) {
            $golonganList[$row->induk_id]['nama'] = $row->nama_induk;
            $golonganList[$row->induk_id]['akun'][$row->akun_id] = $row->nama;
        }

        return $golonganList;
    }

    /** Sudah pernah dijurnal / punya saldo? Akun seperti ini tak boleh diganti saldo normal / sifat kasnya, apalagi dihapus. */
    public static function sudahDipakai(string $akunId): bool
    {
        return DB::table('sikeutxn_jurnal_dtls')->where('akun_id', $akunId)->exists()
            || DB::table('sikeutxn_jurnal_dtls')->where('akun_lawan_id', $akunId)->exists()
            || DB::table('sikeutxn_saldo_akuns')->where('akun_id', $akunId)->exists();
    }

    /** Teks aman untuk kolom Oracle WE8ISO8859P1: ASCII cetak saja (simbol seperti — atau ≤ berubah jadi ¿). */
    public static function teksAman(string $teks): bool
    {
        return (bool) preg_match('/^[\x20-\x7E]*$/', $teks);
    }

    /**
     * Daftar akun SIRUS lewat API /akun, di-cache 10 menit supaya buka-tutup layar tidak memanggil API terus.
     * @return array<string, array{acc_id: string, acc_name: string, acc_group: string, active_status: string, dk_status: string}>
     */
    public static function akunSirusList(bool $segarkan = false): array
    {
        if ($segarkan) {
            Cache::forget(self::CACHE_AKUN_SIRUS);
        }

        return Cache::remember(self::CACHE_AKUN_SIRUS, 600, function () {
            $akunList = [];
            foreach (SirusApi::get('akun')['akun'] ?? [] as $akun) {
                $akunId = trim((string) ($akun['acc_id'] ?? ''));
                if ($akunId === '') {
                    continue;
                }
                $akunList[$akunId] = [
                    'acc_id' => $akunId,
                    'acc_name' => trim((string) ($akun['acc_name'] ?? '')),
                    'acc_group' => trim((string) ($akun['acc_group'] ?? '')),
                    'active_status' => trim((string) ($akun['active_status'] ?? '')),
                    'dk_status' => trim((string) ($akun['dk_status'] ?? '')),
                ];
            }

            return $akunList;
        });
    }

    /** Catat perubahan master ke sikeutxn_audit_logs. */
    public static function catatAudit(string $aksi, string $tabel, string $kunci, ?array $dataLama, ?array $dataBaru, ?string $keterangan = null): void
    {
        DB::table('sikeutxn_audit_logs')->insert([
            'user_id' => auth()->id(),
            'aksi' => $aksi,
            'tabel' => $tabel,
            'kunci' => $kunci,
            'data_lama' => $dataLama === null ? null : json_encode($dataLama, JSON_UNESCAPED_UNICODE),
            'data_baru' => $dataBaru === null ? null : json_encode($dataBaru, JSON_UNESCAPED_UNICODE),
            'keterangan' => $keterangan,
        ]);
    }
}
