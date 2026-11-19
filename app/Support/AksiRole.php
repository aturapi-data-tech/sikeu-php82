<?php

namespace App\Support;

/**
 * SUMBER TUNGGAL daftar role untuk aksi yang dibatasi — DIKLASTER per area.
 *
 * Menggantikan penulisan literal berulang di tiap berkas:
 *
 *   SEBELUM  @hasanyrole('Admin|Manager Umum|Manager Medis')      (6 tempat, mudah melenceng)
 *   SESUDAH  @can('emr.logAktivitas')                             (1 sumber)
 *
 * Konstanta dikonsumsi lewat Gate yang didaftarkan di
 * App\Providers\AppServiceProvider::boot(). Pemakaian:
 *   - Blade  : @can('dokumen.hapus') ... @endcan
 *   - Server : auth()->user()?->can('dokumen.hapus')
 *
 * ATURAN PENTING — SATU KONSTANTA PER MAKSUD, BUKAN PER DAFTAR ROLE.
 * Dua aksi yang kebetulan punya daftar sama TETAP dipisah konstantanya, supaya
 * kelak bisa diberi role berbeda tanpa menyentuh kode pemanggil. Contoh nyata:
 * DOKUMEN_HAPUS dan EMR_LOG_AKTIVITAS sekarang isinya sama, tapi yang pertama
 * aksi destruktif dan yang kedua hanya melihat audit log — menggabungkannya
 * berarti menaikkan hak lihat log setiap kali kebijakan hapus dilonggarkan.
 *
 * MENAMBAH KLASTER BARU:
 *   1. tambah konstanta di sini (beri prefix area),
 *   2. daftarkan Gate-nya di AppServiceProvider,
 *   3. ganti @hasanyrole literal di blade dengan @can, DAN
 *   4. pastikan guard SERVER di method-nya ikut memakai ->can() — guard blade
 *      saja bisa ditembus karena wire:click memanggil method publik.
 *
 */
class AksiRole
{
    /* ─────────────── KEUANGAN ─────────────── */

    /** Role yang boleh MEMBACA laporan keuangan (buku besar, neraca, laba rugi, arus kas). */
    public const LAPORAN_LIHAT = ['Admin', 'Manager Keuangan', 'Staf Akuntansi', 'Direksi'];

    /** Role yang boleh mengambil snapshot jurnal dari SIRUS (cutoff versi baru). Membandingkan versi = LAPORAN_LIHAT. */
    public const SNAPSHOT_AMBIL = ['Admin', 'Manager Keuangan', 'Staf Akuntansi'];

    /** Role yang boleh membuat / mengubah jurnal umum (memorial, penyesuaian) selama periodenya belum dikunci. */
    public const JURNAL_ENTRI = ['Admin', 'Manager Keuangan', 'Staf Akuntansi'];

    /** Role yang boleh MENGUNCI / membuka kunci periode bulanan — aksi pengesahan. */
    public const PERIODE_KUNCI = ['Admin', 'Manager Keuangan'];

    /** Role yang boleh menjalankan tutup buku tahunan — aksi pengesahan. */
    public const TUTUP_BUKU = ['Admin', 'Manager Keuangan'];

    /** Role yang boleh MENGUBAH bagan akun & peta akun SIRUS (tambah, ubah, nonaktif, hapus). Melihat = LAPORAN_LIHAT. */
    public const AKUN_KELOLA = ['Admin', 'Manager Keuangan'];

    /* ─────────────── CONTOH ─────────────── */

    // /** Role yang boleh MENGHAPUS entri dokumen. */
    // public const DOKUMEN_HAPUS = ['Admin', 'Manager Umum', 'Manager Medis'];
}
