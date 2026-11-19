<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * AppMenu — sumber tunggal definisi menu aplikasi (dashboard + sidebar).
 *
 * Setiap entri punya 'izin' = nama permission (katalog App\Support\HakAkses) yang wajib dimiliki user, atau
 * null = semua user yang sudah login. Siapa punya izin apa diatur di Sistem › Role Control, bukan di sini.
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

            // ── Master ─────────────────────────────────────────────────
            $entry(['group' => 'Master', 'groupOrder' => 0, 'order' => 0, 'route' => 'master.kelompok-akun', 'title' => 'Kelompok Akun', 'desc' => 'Setup akun Neraca & Laba Rugi per kelompok (digit pertama kode akun)', 'izin' => 'laporan.lihat', 'badge' => 'N/L']),
            $entry(['group' => 'Master', 'groupOrder' => 0, 'order' => 1, 'route' => 'master.bagan-akun', 'title' => 'Bagan Akun', 'desc' => 'Daftar akun buku besar (SAK EP): tambah, ubah, nonaktifkan akun', 'izin' => 'laporan.lihat', 'badge' => 'COA']),
            $entry(['group' => 'Master', 'groupOrder' => 0, 'order' => 3, 'route' => 'master.format-kode', 'title' => 'Format Kode Akun', 'desc' => 'Jumlah tingkat, digit per tingkat, pemisah & nomor akun kontra', 'izin' => 'laporan.lihat', 'badge' => 'FMT']),
            $entry(['group' => 'Master', 'groupOrder' => 0, 'order' => 2, 'route' => 'master.peta-akun', 'title' => 'Peta Akun SIRUS', 'desc' => 'Pasangan akun SIRUS ke akun SIKEU — posting snapshot ditolak bila ada yang belum dipetakan', 'izin' => 'laporan.lihat', 'badge' => 'MAP']),

            // ── Cutoff Jurnal ──────────────────────────────────────────
            $entry(['group' => 'Cutoff Jurnal', 'groupOrder' => 1, 'order' => 1, 'route' => 'cutoff.snapshot-jurnal', 'title' => 'Snapshot Jurnal', 'desc' => 'Ambil jurnal SIRUS satu bulan sebagai versi baru (cutoff) — versi lama tidak ditimpa', 'izin' => 'snapshot.ambil', 'badge' => 'SNP']),
            $entry(['group' => 'Cutoff Jurnal', 'groupOrder' => 1, 'order' => 2, 'route' => 'cutoff.banding-versi', 'title' => 'Bandingkan Versi', 'desc' => 'Cari transaksi yang baru, hilang, atau berubah antara dua versi snapshot', 'izin' => 'laporan.lihat', 'badge' => 'DIF']),

            // ── Buku Besar ─────────────────────────────────────────────
            $entry(['group' => 'Buku Besar', 'groupOrder' => 2, 'order' => 1, 'route' => 'buku-besar.periode', 'title' => 'Periode & Posting', 'desc' => 'Buka tahun buku, sahkan versi snapshot & posting ke buku besar, kunci periode', 'izin' => 'laporan.lihat', 'badge' => 'PER']),
            $entry(['group' => 'Buku Besar', 'groupOrder' => 2, 'order' => 1.5, 'route' => 'buku-besar.saldo-awal', 'title' => 'Saldo Awal', 'desc' => 'Saldo akun per 1 Januari: tarik dari SIRUS, koreksi, seimbangkan, sahkan', 'izin' => 'laporan.lihat', 'badge' => 'SA']),
            $entry(['group' => 'Buku Besar', 'groupOrder' => 2, 'order' => 2, 'route' => 'buku-besar.neraca-saldo', 'title' => 'Neraca Saldo', 'desc' => 'Saldo awal, mutasi & saldo akhir tiap akun per periode — cek D = K', 'izin' => 'laporan.lihat', 'badge' => 'NS']),
            $entry(['group' => 'Buku Besar', 'groupOrder' => 2, 'order' => 3, 'route' => 'buku-besar.buku-besar', 'title' => 'Buku Besar', 'desc' => 'Mutasi & saldo berjalan per akun, rinci sampai transaksi SIRUS', 'izin' => 'laporan.lihat', 'badge' => 'BB']),

            // ── Laporan ────────────────────────────────────────────────
            $entry(['group' => 'Laporan', 'groupOrder' => 3, 'order' => 2, 'route' => 'laporan.neraca', 'title' => 'Neraca', 'desc' => 'Laporan posisi keuangan per akhir bulan vs 1 Januari — cek Aset = Liabilitas + Ekuitas', 'izin' => 'laporan.lihat', 'badge' => 'NRC']),
            $entry(['group' => 'Laporan', 'groupOrder' => 3, 'order' => 1, 'route' => 'laporan.laba-rugi', 'title' => 'Laba Rugi', 'desc' => 'Laporan laba rugi bulan berjalan & kumulatif — susunan dari template laporan', 'izin' => 'laporan.lihat', 'badge' => 'L/R']),

            // ── Panduan ────────────────────────────────────────────────
            $entry(['group' => 'Panduan', 'groupOrder' => 90, 'order' => 1, 'route' => 'panduan.istilah-keuangan', 'title' => 'Istilah Keuangan', 'desc' => 'Glosarium: debit/kredit, aset, liabilitas, ekuitas, laba rugi, akrual, istilah SIKEU — dengan contoh rumah sakit', 'izin' => null, 'badge' => '?']),

            // ── Sistem ─────────────────────────────────────────────────
            $entry(['group' => 'Sistem', 'groupOrder' => 15, 'order' => 0, 'route' => 'sistem.identitas', 'title' => 'Identitas Instansi', 'desc' => 'Logo, nama & profil instansi (kop cetakan, halaman depan)', 'izin' => 'sistem.kelola', 'badge' => 'ID']),
            $entry(['group' => 'Sistem', 'groupOrder' => 15, 'order' => 1, 'route' => 'sistem.user-control', 'title' => 'User Control', 'desc' => 'Kelola user login, role & tanda tangan', 'izin' => 'sistem.kelola', 'badge' => 'USR']),
            $entry(['group' => 'Sistem', 'groupOrder' => 15, 'order' => 2, 'route' => 'sistem.role-control', 'title' => 'Role Control', 'desc' => 'Kelola role & permission', 'izin' => 'sistem.kelola', 'badge' => 'ROL']),
            $entry(['group' => 'Sistem', 'groupOrder' => 15, 'order' => 3, 'route' => 'sistem.sambungan-sirus', 'title' => 'Sambungan SIRUS', 'desc' => 'Uji login API SIRUS (JWT), katalog cabang jurnal & bagan akun', 'izin' => 'sistem.kelola', 'badge' => 'DB']),
        ];
    }
}
