<?php

namespace App\Support;

/**
 * KATALOG HAK AKSES (permission Spatie) — siapa boleh apa diatur di Sistem › Role Control dengan mencentang
 * permission per role, BUKAN dengan daftar nama role di kode. Dengan begitu instansi lain bebas memakai nama
 * jabatannya sendiri.
 *
 * Pemakaian: menu (AppMenu 'izin'), blade @can('akun.kelola'), server auth()->user()->can('akun.kelola').
 * Spatie mendaftarkan setiap permission ke Gate secara otomatis (config permission.register_permission_check_method).
 *
 * MENAMBAH HAK AKSES BARU: tambah di DAFTAR (label tampil di Role Control), buat migrasi yang membuat permission-nya
 * (lihat 2026_11_19_130000_hak_akses_awal_sikeu.php), lalu pakai lewat can()/@can + guard server di method-nya.
 */
class HakAkses
{
    /** Role ini selalu boleh semua (Gate::before) — pengaman supaya tidak ada yang mengunci semua admin keluar. */
    public const PERAN_SUPER = 'Admin';

    public const DAFTAR = [
        'laporan.lihat' => 'Lihat laporan keuangan, bagan akun, peta akun & banding versi',
        'snapshot.ambil' => 'Ambil snapshot jurnal dari SIRUS (cutoff versi baru)',
        'jurnal.entri' => 'Buat / ubah jurnal umum selama periode belum dikunci',
        'jurnal.posting' => 'Sahkan versi snapshot SIRUS & posting ke buku besar',
        'periode.kunci' => 'Buka tahun buku, kunci / buka kunci periode bulanan (pengesahan)',
        'tutupBuku.jalankan' => 'Jalankan tutup buku tahunan (pengesahan)',
        'akun.kelola' => 'Ubah kelompok, bagan akun, peta akun SIRUS & format kode akun',
        'sistem.kelola' => 'Kelola identitas instansi, user, role & sambungan SIRUS',
    ];

    public static function label(string $permission): string
    {
        return self::DAFTAR[$permission] ?? '';
    }
}
