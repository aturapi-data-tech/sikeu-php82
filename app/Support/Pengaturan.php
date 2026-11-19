<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Pengaturan aplikasi kunci-nilai (sikeumst_pengaturans, DDL 04_pengaturan.sql) — hal yang berbeda antar-instansi
 * disimpan di sini, bukan di kode. Dibaca sekali per request lalu disimpan di memori.
 *
 * Catatan Oracle: string kosong = NULL, jadi nilai kosong (mis. pemisah "tanpa") kembali sebagai ''.
 */
class Pengaturan
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public static function ambil(string $kunci, string $bawaan = ''): string
    {
        if (self::$cache === null) {
            self::$cache = DB::table('sikeumst_pengaturans')->pluck('nilai', 'kunci')
                ->map(fn ($nilai) => (string) $nilai)->all();
        }

        return array_key_exists($kunci, self::$cache) ? self::$cache[$kunci] : $bawaan;
    }

    public static function simpan(string $kunci, string $nilai): void
    {
        $ada = DB::table('sikeumst_pengaturans')->where('kunci', $kunci)->exists();
        if ($ada) {
            DB::table('sikeumst_pengaturans')->where('kunci', $kunci)
                ->update(['nilai' => $nilai, 'diubah_oleh' => auth()->id(), 'updated_at' => now()]);
        }
        if (!$ada) {
            DB::table('sikeumst_pengaturans')
                ->insert(['kunci' => $kunci, 'nilai' => $nilai, 'diubah_oleh' => auth()->id(), 'created_at' => now()]);
        }
        self::lupakan();
    }

    /** Buang cache memori (setelah simpan, atau di uji). */
    public static function lupakan(): void
    {
        self::$cache = null;
    }
}
