<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * AppMenu — sumber tunggal definisi menu aplikasi (dashboard + sidebar).
 *
 * Setiap entri punya 'izin' = nama permission (katalog App\Support\HakAkses) yang wajib dimiliki user, atau
 * null = semua user yang sudah login. Siapa punya izin apa diatur di Sistem › Peran & Hak Akses, bukan di sini.
 *
 * - `all()`            semua entri (route yang tidak terdaftar dilewati)
 * - `forUser($user)`   entri yang boleh dilihat user
 * - `grouped($user)`   forUser + urut + kelompokkan per 'group' untuk render UI
 * - `bolehBuka()`      dipakai middleware CekAksesMenu — halaman di menu tak bisa ditembus lewat URL
 */
class AppMenu
{
    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        $entry = function (array $menu): ?array {
            if (!Route::has($menu['route'])) {
                return null;
            }
            $menu['href'] = route($menu['route'], $menu['params'] ?? []);
            return $menu;
        };

        return array_values(array_filter(self::definitions($entry)));
    }

    /** @return array<int, array<string, mixed>> */
    public static function forUser(?object $user): array
    {
        return array_values(array_filter(
            self::all(),
            fn ($menu) => $menu['izin'] === null ? $user !== null : (bool) $user?->can($menu['izin']),
        ));
    }

    /** Halaman di menu hanya untuk user ber-izin; route di luar menu tidak dibatasi di sini (lihat CekAksesMenu). */
    public static function bolehBuka(string $route, ?object $user): bool
    {
        if (!collect(self::all())->contains('route', $route)) {
            return true;
        }

        return collect(self::forUser($user))->contains('route', $route);
    }

    /** Sorted + grouped by 'group'. Cocok untuk render sidebar/dashboard. */
    public static function grouped(?object $user): Collection
    {
        return collect(self::forUser($user))
            ->sortBy([['groupOrder', 'asc'], ['order', 'asc']])
            ->groupBy('group');
    }

    /**
     * Definisi semua menu. Helper $entry guard route existence + inject 'href'.
     * Setiap entry: ['group','groupOrder','order','route','title','desc','izin','badge'].
     *
     * @param  callable(array):?array  $entry
     * @return array<int, ?array<string, mixed>>
     */
    private static function definitions(callable $entry): array
    {
        return [
            // 'izin' = permission (App\Support\HakAkses::DAFTAR); null = semua user login.
            // Urutan grup = alur kerja: pembukuan (ambil & sahkan jurnal SIRUS ada di Tarik Data SIRUS) -> laporan;
            // data induk & sistem (jarang dipakai) di bawah. Jangan menambah menu kembar untuk fungsi yang sudah ada.

            // ── 1. Pembukuan ───────────────────────────────────────────
            $entry(['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 1, 'route' => 'buku-besar.pengaturan', 'title' => 'Pengaturan Pembukuan', 'desc' => 'Langkah pertama: tahun pertama SIKEU (set ulang), buka tahun buku, akun saldo laba', 'izin' => 'laporan.lihat', 'badge' => 'PP']),
            $entry(['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 3, 'route' => 'buku-besar.periode', 'title' => 'Tarik Data SIRUS', 'desc' => 'Tarik jurnal SIRUS per bulan / setahun, sahkan ke buku besar, kunci periode; buka tahun buku', 'izin' => 'laporan.lihat', 'badge' => 'TDS']),
            $entry(['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 4, 'route' => 'cutoff.banding-versi', 'title' => 'Bandingkan Versi', 'desc' => 'Cari transaksi SIRUS yang baru, hilang, atau berubah antara dua hasil tarikan', 'izin' => 'laporan.lihat', 'badge' => 'BV']),
            $entry(['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 2, 'route' => 'buku-besar.saldo-awal', 'title' => 'Saldo Awal', 'desc' => 'Saldo akun per 1 Januari tahun pertama SIKEU: isi form neraca, seimbangkan, sahkan; tahun berikutnya diteruskan otomatis', 'izin' => 'laporan.lihat', 'badge' => 'SA']),
            $entry(['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 5, 'route' => 'buku-besar.jurnal-umum', 'title' => 'Jurnal Umum', 'desc' => 'Jurnal penyesuaian & koreksi: konsep, posting (wajib seimbang), batal / pembalik', 'izin' => 'laporan.lihat', 'badge' => 'JU']),
            $entry(['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 6, 'route' => 'buku-besar.neraca-saldo', 'title' => 'Neraca Saldo', 'desc' => 'Saldo awal, mutasi & saldo akhir tiap akun per periode - cek debit = kredit', 'izin' => 'laporan.lihat', 'badge' => 'NS']),
            $entry(['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 7, 'route' => 'buku-besar.buku-besar', 'title' => 'Buku Besar', 'desc' => 'Mutasi & saldo berjalan per akun, rinci sampai transaksi SIRUS', 'izin' => 'laporan.lihat', 'badge' => 'BB']),

            // ── 2. Laporan Keuangan ────────────────────────────────────
            $entry(['group' => 'Laporan Keuangan', 'groupOrder' => 2, 'order' => 1, 'route' => 'laporan.laba-rugi', 'title' => 'Laba Rugi', 'desc' => 'Laporan laba rugi bulan berjalan & kumulatif - susunan dari template laporan', 'izin' => 'laporan.lihat', 'badge' => 'LR']),
            $entry(['group' => 'Laporan Keuangan', 'groupOrder' => 2, 'order' => 2, 'route' => 'laporan.neraca', 'title' => 'Neraca', 'desc' => 'Laporan posisi keuangan per akhir bulan vs 1 Januari - cek Aset = Liabilitas + Ekuitas', 'izin' => 'laporan.lihat', 'badge' => 'NRC']),

            // ── 3. Data Induk (urutan menyiapkan) ──────────────────────
            $entry(['group' => 'Data Induk', 'groupOrder' => 3, 'order' => 1, 'route' => 'master.format-kode', 'title' => 'Format Kode Akun', 'desc' => 'Jumlah tingkat, digit per tingkat, pemisah & nomor akun kontra', 'izin' => 'laporan.lihat', 'badge' => 'FK']),
            $entry(['group' => 'Data Induk', 'groupOrder' => 3, 'order' => 2, 'route' => 'master.kelompok-akun', 'title' => 'Kelompok Akun', 'desc' => 'Pembagian akun Neraca & Laba Rugi per kelompok (tingkat 1 kode akun)', 'izin' => 'laporan.lihat', 'badge' => 'KA']),
            $entry(['group' => 'Data Induk', 'groupOrder' => 3, 'order' => 3, 'route' => 'master.bagan-akun', 'title' => 'Bagan Akun', 'desc' => 'Daftar akun buku besar (SAK EP): tambah, ubah, nonaktifkan akun', 'izin' => 'laporan.lihat', 'badge' => 'BA']),
            $entry(['group' => 'Data Induk', 'groupOrder' => 3, 'order' => 4, 'route' => 'master.peta-akun', 'title' => 'Peta Akun SIRUS', 'desc' => 'Pasangan akun SIRUS ke akun SIKEU - pengesahan ditolak bila ada yang belum dipetakan', 'izin' => 'laporan.lihat', 'badge' => 'PA']),
            $entry(['group' => 'Data Induk', 'groupOrder' => 3, 'order' => 5, 'route' => 'master.susunan-laporan', 'title' => 'Susunan Laporan', 'desc' => 'Template baris Laba Rugi & Neraca: Umum / Internal, salin, atur baris & akun, pemeriksaan akun yatim / dobel', 'izin' => 'laporan.lihat', 'badge' => 'SL']),

            // ── 4. Panduan ─────────────────────────────────────────────
            $entry(['group' => 'Panduan', 'groupOrder' => 4, 'order' => 1, 'route' => 'panduan.cara-pakai', 'title' => 'Cara Pakai SIKEU', 'desc' => 'Langkah tutup bulan, aturan kunci / buka kunci berurutan, SIRUS berubah setelah dikunci, arti warna tombol, siapa boleh apa', 'izin' => null, 'badge' => '?']),
            $entry(['group' => 'Panduan', 'groupOrder' => 4, 'order' => 2, 'route' => 'panduan.istilah-keuangan', 'title' => 'Istilah Keuangan', 'desc' => 'Glosarium: debit/kredit, aset, liabilitas, ekuitas, laba rugi, akrual, istilah SIKEU - dengan contoh rumah sakit', 'izin' => null, 'badge' => '?']),

            // ── 5. Sistem ──────────────────────────────────────────────
            $entry(['group' => 'Sistem', 'groupOrder' => 5, 'order' => 1, 'route' => 'sistem.identitas', 'title' => 'Identitas Instansi', 'desc' => 'Logo, nama & profil instansi (kop cetakan, halaman depan)', 'izin' => 'sistem.kelola', 'badge' => 'ID']),
            $entry(['group' => 'Sistem', 'groupOrder' => 5, 'order' => 2, 'route' => 'sistem.user-control', 'title' => 'Pengguna', 'desc' => 'Kelola akun login pengguna, peran & tanda tangan', 'izin' => 'sistem.kelola', 'badge' => 'PGN']),
            $entry(['group' => 'Sistem', 'groupOrder' => 5, 'order' => 3, 'route' => 'sistem.role-control', 'title' => 'Peran & Hak Akses', 'desc' => 'Kelola peran (role) & hak akses yang dicentang per peran', 'izin' => 'sistem.kelola', 'badge' => 'HAK']),
            $entry(['group' => 'Sistem', 'groupOrder' => 5, 'order' => 4, 'route' => 'sistem.sambungan-sirus', 'title' => 'Sambungan SIRUS', 'desc' => 'Uji sambungan API SIRUS: login token, katalog cabang jurnal & bagan akun', 'izin' => 'sistem.kelola', 'badge' => 'API']),
        ];
    }
}
