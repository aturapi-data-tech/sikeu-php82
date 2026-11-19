<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Format tampilan tanggal SIKEU — satu standar untuk semua layar & cetakan:
 *   tanggal       → dd/mm/yyyy            (08/10/2026)
 *   bulan         → mm/yyyy               (10/2026)
 *   tanggal+waktu → dd/mm/yyyy hh24:mi:ss (08/10/2026 15:42:07)
 *   jam saja      → hh24:mi               (07:05) — jam masuk/pulang absensi
 *   uang          → 1.234.567,89 (pemisah ribuan titik, desimal koma; 0 tampil 0,00)
 *
 * Nilai kosong → '' (tampilan memutuskan sendiri mau '—' atau tidak).
 * Padanan Oracle: TO_CHAR(kolom, Format::ORACLE_WAKTU).
 */
class Format
{
    public const TANGGAL = 'd/m/Y';
    public const BULAN   = 'm/Y';
    public const WAKTU   = 'd/m/Y H:i:s';
    public const JAM     = 'H:i';

    public const ORACLE_TANGGAL = 'dd/mm/yyyy';
    public const ORACLE_WAKTU   = 'dd/mm/yyyy HH24:MI:SS';

    public static function tanggal(DateTimeInterface|string|null $nilai): string
    {
        return self::format($nilai, self::TANGGAL);
    }

    public static function bulan(DateTimeInterface|string|null $nilai): string
    {
        return self::format($nilai, self::BULAN);
    }

    public static function waktu(DateTimeInterface|string|null $nilai): string
    {
        return self::format($nilai, self::WAKTU);
    }

    public static function jam(DateTimeInterface|string|null $nilai): string
    {
        return self::format($nilai, self::JAM);
    }

    /** Nama hari Indonesia lengkap (Senin, Selasa, …) untuk mendampingi tanggal. */
    public static function hari(DateTimeInterface|string|null $nilai): string
    {
        return $nilai ? Carbon::parse($nilai)->locale('id')->translatedFormat('l') : '';
    }

    private static function format(DateTimeInterface|string|null $nilai, string $pola): string
    {
        if ($nilai === null || $nilai === '') {
            return '';
        }

        return ($nilai instanceof DateTimeInterface ? Carbon::instance($nilai) : Carbon::parse($nilai))->format($pola);
    }

    /**
     * Nominal: 1,234,567.89 (koma = ribuan, titik = desimal) — SAMA dengan isian <x-text-input-number>,
     * supaya angka yang diketik dan yang ditampilkan tidak berbeda gaya (keputusan user 2026-11-19).
     * Nilai negatif tetap bertanda minus.
     */
    public static function uang(float|int|string|null $nilai, int $desimal = 2): string
    {
        return number_format((float) $nilai, $desimal, '.', ',');
    }

    /**
     * Kebalikan uang(): terima "1,234,567.89" (gaya aplikasi), "1.234.567,89" (gaya lama/Indonesia), "1234567.89",
     * "1234567" → float ≥ 0; teks kosong → 0; selain angka → null.
     * Aturan: bila ada titik DAN koma, yang paling kanan = pemisah desimal. Bila hanya satu jenis: muncul lebih dari
     * sekali atau diikuti tepat 3 angka di ujung = pemisah ribuan; selain itu = desimal.
     */
    public static function uraiUang(?string $teks): ?float
    {
        $teks = str_replace([' ', 'Rp', 'rp'], '', trim((string) $teks));
        if ($teks === '') {
            return 0.0;
        }
        $adaTitik = str_contains($teks, '.');
        $adaKoma = str_contains($teks, ',');
        if ($adaTitik && $adaKoma) {
            $desimal = strrpos($teks, '.') > strrpos($teks, ',') ? '.' : ',';
            $ribuan = $desimal === '.' ? ',' : '.';
            $teks = str_replace([$ribuan, $desimal], ['', '.'], $teks);
        } elseif ($adaTitik || $adaKoma) {
            $tanda = $adaTitik ? '.' : ',';
            $ribuan = substr_count($teks, $tanda) > 1 || preg_match('/' . preg_quote($tanda, '/') . '\d{3}$/', $teks);
            $teks = $ribuan ? str_replace($tanda, '', $teks) : str_replace($tanda, '.', $teks);
        }

        return is_numeric($teks) && (float) $teks >= 0 ? round((float) $teks, 2) : null;
    }

    /** Nilai isian nominal: angka (dari <x-text-input-number>) dipakai langsung; teks diurai uraiUang(). */
    public static function angka(mixed $nilai): ?float
    {
        if ($nilai === null || $nilai === '') {
            return 0.0;
        }
        if (is_int($nilai) || is_float($nilai)) {
            return $nilai >= 0 ? round((float) $nilai, 2) : null;
        }

        return self::uraiUang((string) $nilai);
    }
}
