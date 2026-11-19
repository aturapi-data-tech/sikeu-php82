<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Format kode akun — dari pengaturan (Master › Format Kode Akun), bukan hardcode.
 *
 *   akun.digit_per_tingkat "1,1,2,3"  -> 4 tingkat; kode tingkat n = kode induk + pemisah + n-digit angka
 *   akun.pemisah           "." / "-" / ""
 *   akun.awal_kontra       900 -> rincian tingkat terakhir >= 900 dicadangkan untuk akun kontra (0 = tidak ada)
 *
 * Tingkat 1 = kelompok (kodenya = sikeumst_akun_kelompoks.kelompok_id, maks 2 digit). Tingkat terakhir selalu Detail.
 */
class FormatKodeAkun
{
    public const PEMISAH_SAH = ['.', '-', ''];

    public const TINGKAT_MIN = 2;

    public const TINGKAT_MAKS = 6;

    public const DIGIT_MAKS = 4;

    public const DIGIT_KELOMPOK_MAKS = 2;  // kolom kelompok_id VARCHAR2(2)

    public const PANJANG_KODE_MAKS = 30;   // kolom akun_id VARCHAR2(30)

    /** @return array<int, int> [tingkat => jumlah digit], mulai tingkat 1 */
    public static function digitPerTingkat(): array
    {
        return self::uraiDigit(Pengaturan::ambil('akun.digit_per_tingkat', '1,1,2,3'));
    }

    public static function pemisah(): string
    {
        return Pengaturan::ambil('akun.pemisah', '.');
    }

    public static function awalKontra(): int
    {
        return (int) Pengaturan::ambil('akun.awal_kontra', '0');
    }

    public static function jumlahTingkat(): int
    {
        return count(self::digitPerTingkat());
    }

    /** "1,1,2,3" -> [1 => 1, 2 => 1, 3 => 2, 4 => 3]; isian rusak -> [] */
    public static function uraiDigit(string $teks): array
    {
        $digitList = [];
        foreach (explode(',', $teks) as $indeks => $bagian) {
            if (!ctype_digit(trim($bagian))) {
                return [];
            }
            $digitList[$indeks + 1] = (int) trim($bagian);
        }

        return $digitList;
    }

    /** Contoh kode tingkat terakhir, mis. 1.1.01.001 */
    public static function contoh(?array $digitList = null, ?string $pemisah = null): string
    {
        $digitList ??= self::digitPerTingkat();
        $pemisah ??= self::pemisah();

        return implode($pemisah, array_map(fn (int $digit) => str_pad('1', $digit, '0', STR_PAD_LEFT), $digitList));
    }

    /** Pola regex kode akun tingkat tertentu di bawah induknya (tingkat 1: tanpa induk). */
    public static function pola(int $tingkat, ?string $indukId, ?array $digitList = null, ?string $pemisah = null): ?string
    {
        $digitList ??= self::digitPerTingkat();
        $pemisah ??= self::pemisah();
        if (!isset($digitList[$tingkat])) {
            return null;
        }
        $segmen = '\d{' . $digitList[$tingkat] . '}';
        if ($tingkat === 1) {
            return '/^' . $segmen . '$/';
        }

        return '/^' . preg_quote((string) $indukId, '/') . preg_quote($pemisah, '/') . $segmen . '$/';
    }

    public static function kodeSah(string $kode, int $tingkat, ?string $indukId, ?array $digitList = null, ?string $pemisah = null): bool
    {
        $pola = self::pola($tingkat, $indukId, $digitList, $pemisah);

        return $pola !== null && (bool) preg_match($pola, $kode);
    }

    /** Pesan cara menulis kode yang benar, untuk galat validasi. */
    public static function petunjuk(int $tingkat, ?string $indukId): string
    {
        $digit = self::digitPerTingkat()[$tingkat] ?? 0;
        $contohSegmen = str_pad('1', $digit, '0', STR_PAD_LEFT);
        if ($tingkat === 1) {
            return "Kode kelompok harus {$digit} digit angka, mis. {$contohSegmen}.";
        }

        return "Kode harus {$indukId}" . self::pemisah() . "{$contohSegmen} ({$digit} digit di belakang kode induk).";
    }

    /** Kode berikutnya yang belum terpakai di bawah induk (tingkat 1: kelompok berikutnya). Nomor kontra dilewati. */
    public static function saranKode(?string $indukId, int $tingkat): string
    {
        $digit = self::digitPerTingkat()[$tingkat];
        $awalKode = $tingkat === 1 ? '' : $indukId . self::pemisah();
        $kodeList = $tingkat === 1
            ? DB::table('sikeumst_akun_kelompoks')->pluck('kelompok_id')
            : DB::table('sikeumst_akuns')->where('induk_id', $indukId)->pluck('akun_id');
        $batasKontra = $tingkat === self::jumlahTingkat() ? self::awalKontra() : 0;
        $nomorList = $kodeList->map(fn ($kode) => substr((string) $kode, strlen($awalKode)))
            ->filter(fn ($segmen) => ctype_digit($segmen))
            ->map(fn ($segmen) => (int) $segmen)
            ->filter(fn ($nomor) => $batasKontra <= 0 || $nomor < $batasKontra);
        $nomorMaks = ($batasKontra > 0 ? $batasKontra : 10 ** $digit) - 1;
        $nomorBaru = $nomorList->isEmpty() ? 1 : $nomorList->max() + 1;
        if ($nomorBaru > $nomorMaks) {
            // Nomor urut habis: cari celah yang belum terpakai; tidak ada = kosong (pemakai isi sendiri / galat validasi)
            $nomorBaru = collect(range(1, $nomorMaks))->first(fn ($nomor) => !$nomorList->contains($nomor));
            if ($nomorBaru === null) {
                return '';
            }
        }

        return $awalKode . str_pad((string) $nomorBaru, $digit, '0', STR_PAD_LEFT);
    }

    /**
     * Akun yang TIDAK cocok dengan format usulan — format hanya boleh diganti bila daftar ini kosong.
     * @return array<int, string>
     */
    public static function akunTakCocok(array $digitList, string $pemisah, int $batas = 5): array
    {
        $takCocok = [];
        $rows = DB::table('sikeumst_akuns')->orderBy('akun_id')->get(['akun_id', 'induk_id', 'tingkat']);
        foreach ($rows as $row) {
            if (!self::kodeSah((string) $row->akun_id, (int) $row->tingkat, $row->induk_id, $digitList, $pemisah)) {
                $takCocok[] = (string) $row->akun_id;
                if (count($takCocok) >= $batas) {
                    break;
                }
            }
        }

        return $takCocok;
    }
}
