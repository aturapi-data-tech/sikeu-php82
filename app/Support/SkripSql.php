<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menjalankan file DDL `database/sql/NN_*.sql` (gaya SQL*Plus) lewat koneksi Laravel — dipakai migrasi pemasangan
 * supaya server baru cukup `php artisan migrate --seed`.
 *
 * Aturan pemecahan:
 * - Pernyataan biasa diakhiri `;` di luar tanda kutip (titik koma di dalam string tetap aman).
 * - Blok PL/SQL (CREATE [OR REPLACE] TRIGGER/PROCEDURE/FUNCTION/PACKAGE, BEGIN, DECLARE) diakhiri baris `/`.
 * - Komentar `--` di luar kutip dibuang.
 * - Perintah SQL*Plus satu baris (SET DEFINE OFF, PROMPT) dibuang.
 * - Yang DILEWATI: SELECT / WITH (bagian periksa & verifikasi), COMMIT (tiap pernyataan sudah
 *   auto-commit di yajra oci8).
 */
class SkripSql
{
    private const POLA_PLSQL = '/^(CREATE\s+(OR\s+REPLACE\s+)?(TRIGGER|PROCEDURE|FUNCTION|PACKAGE|TYPE)\b|BEGIN\b|DECLARE\b)/i';

    private const POLA_LEWATI = '/^(SELECT|WITH|COMMIT)\b/i';

    /** Jalankan satu file; mengembalikan jumlah pernyataan yang dieksekusi. */
    public static function jalankan(string $path, ?string $koneksi = null): int
    {
        if (!is_file($path)) {
            throw new RuntimeException("File SQL tidak ditemukan: {$path}");
        }

        $jumlah = 0;
        foreach (self::pecah((string) file_get_contents($path)) as $urut => $pernyataan) {
            if (preg_match(self::POLA_LEWATI, $pernyataan)) {
                continue;
            }
            try {
                DB::connection($koneksi)->unprepared($pernyataan);
            } catch (\Throwable $e) {
                $cuplikan = mb_substr(preg_replace('/\s+/', ' ', $pernyataan), 0, 200);
                throw new RuntimeException(basename($path) . " pernyataan ke-" . ($urut + 1) . " gagal: {$e->getMessage()}\n{$cuplikan}", 0, $e);
            }
            $jumlah++;
        }

        return $jumlah;
    }

    /** @return list<string> */
    public static function pecah(string $isi): array
    {
        $hasil = [];
        $buffer = '';
        $dalamKutip = false;

        foreach (preg_split('/\r\n|\r|\n/', $isi) as $baris) {
            // Perintah SQL*Plus satu baris tanpa titik koma (SET DEFINE OFF, PROMPT ...) — bukan SQL.
            if (!$dalamKutip && trim($buffer) === '' && preg_match('/^\s*(SET|PROMPT|SPOOL|WHENEVER)\b/i', $baris)) {
                continue;
            }

            if (!$dalamKutip && trim($baris) === '/') {
                if (trim($buffer) !== '') {
                    $hasil[] = rtrim(trim($buffer));
                }
                $buffer = '';
                continue;
            }

            // Buang komentar -- di luar kutip, sambil melacak status kutip.
            $bersih = '';
            $panjang = strlen($baris);
            for ($i = 0; $i < $panjang; $i++) {
                $c = $baris[$i];
                if ($c === "'") {
                    $dalamKutip = !$dalamKutip;
                } elseif (!$dalamKutip && $c === '-' && ($baris[$i + 1] ?? '') === '-') {
                    break;
                }
                $bersih .= $c;
            }

            if (trim($bersih) === '' && trim($buffer) === '') {
                continue;
            }
            $buffer .= $bersih . "\n";

            $modePlsql = (bool) preg_match(self::POLA_PLSQL, ltrim($buffer));
            if (!$dalamKutip && !$modePlsql && str_ends_with(rtrim($bersih), ';')) {
                $hasil[] = rtrim(rtrim(trim($buffer)), ';');
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            $hasil[] = rtrim(rtrim(trim($buffer)), ';');
        }

        return $hasil;
    }
}
