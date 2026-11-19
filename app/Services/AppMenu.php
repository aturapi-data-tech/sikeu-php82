<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * AppMenu — sumber tunggal definisi menu aplikasi (dashboard + sidebar).
 *
 * - `all()` mengembalikan semua entri (defensive: skip route yang tidak terdaftar)
 * - `forRoles($roles)` filter by role names (lowercase)
 * - `grouped(array $roles)` filter + sort + group by 'group' untuk render UI.
 *
 * Tambah/edit menu di method `definitions()` saja. Dashboard & sidebar otomatis ikut.
 */
class AppMenu
{
    /**
     * Penanda 'roles' untuk menu yang boleh dilihat SEMUA role (juga user tanpa role).
     * Dipakai untuk menu yang memang terbuka bagi siapa pun yang sudah login (mis. panduan).
     */
    public const SEMUA_ROLE = '*';

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

    /**
     * Filter entries by role names (case-insensitive).
     * Entri ber-roles ['*'] (SEMUA_ROLE) selalu lolos, apa pun role user.
     *
     * @param  array<int, string>  $userRoles
     * @return array<int, array<string, mixed>>
     */
    public static function forRoles(array $userRoles): array
    {
        // trim() penting: nama role di DB pernah mengandung trailing space ('Manager Umum')
        // → strtolower saja tidak match dan seluruh menu role tsb hilang.
        $roles = array_map(fn($role) => trim(strtolower($role)), $userRoles);

        return array_values(array_filter(
            self::all(),
            fn($menu) => in_array(self::SEMUA_ROLE, $menu['roles'], true) || !empty(array_intersect($menu['roles'], $roles)),
        ));
    }

    /** Halaman di menu hanya untuk role yang tercantum; route di luar menu tidak dibatasi di sini (lihat CekAksesMenu). */
    public static function bolehBuka(string $route, ?object $user): bool
    {
        if (!collect(self::all())->contains('route', $route)) {
            return true;
        }
        $roles = $user ? $user->getRoleNames()->all() : [];

        return collect(self::forRoles($roles))->contains('route', $route);
    }

    /**
     * Sorted + grouped by 'group'. Cocok untuk render sidebar/dashboard.
     *
     * @param  array<int, string>  $userRoles
     */
    public static function grouped(array $userRoles): Collection
    {
        return collect(self::forRoles($userRoles))
            ->sortBy([['groupOrder', 'asc'], ['order', 'asc']])
            ->groupBy('group');
    }

    /**
     * Definisi semua menu. Helper $entry guard route existence + inject 'href'.
     * Setiap entry: ['group','groupOrder','order','route','title','desc','roles','badge'].
     *
     * @param  callable(array):?array  $entry
     * @return array<int, ?array<string, mixed>>
     */
    private static function definitions(callable $entry): array
    {
        return [
            // Role ditulis huruf kecil: 'admin', 'manager keuangan', 'staf akuntansi', 'direksi'.

            // ── Cutoff Jurnal ──────────────────────────────────────────
            $entry(['group' => 'Cutoff Jurnal', 'groupOrder' => 1, 'order' => 1, 'route' => 'cutoff.snapshot-jurnal', 'title' => 'Snapshot Jurnal', 'desc' => 'Ambil jurnal SIRUS satu bulan sebagai versi baru (cutoff) — versi lama tidak ditimpa', 'roles' => ['admin', 'manager keuangan', 'staf akuntansi'], 'badge' => 'SNP']),
            $entry(['group' => 'Cutoff Jurnal', 'groupOrder' => 1, 'order' => 2, 'route' => 'cutoff.banding-versi', 'title' => 'Bandingkan Versi', 'desc' => 'Cari transaksi yang baru, hilang, atau berubah antara dua versi snapshot', 'roles' => ['admin', 'manager keuangan', 'staf akuntansi', 'direksi'], 'badge' => 'DIF']),

            // ── Sistem ─────────────────────────────────────────────────
            $entry(['group' => 'Sistem', 'groupOrder' => 15, 'order' => 0, 'route' => 'sistem.identitas', 'title' => 'Identitas Instansi', 'desc' => 'Logo, nama & profil instansi (kop cetakan, halaman depan)', 'roles' => ['admin'], 'badge' => 'ID']),
            $entry(['group' => 'Sistem', 'groupOrder' => 15, 'order' => 1, 'route' => 'sistem.user-control', 'title' => 'User Control', 'desc' => 'Kelola user login, role & tanda tangan', 'roles' => ['admin'], 'badge' => 'USR']),
            $entry(['group' => 'Sistem', 'groupOrder' => 15, 'order' => 2, 'route' => 'sistem.role-control', 'title' => 'Role Control', 'desc' => 'Kelola role & permission', 'roles' => ['admin'], 'badge' => 'ROL']),
            $entry(['group' => 'Sistem', 'groupOrder' => 15, 'order' => 3, 'route' => 'sistem.sambungan-sirus', 'title' => 'Sambungan SIRUS', 'desc' => 'Uji login API SIRUS (JWT), katalog cabang jurnal & bagan akun', 'roles' => ['admin'], 'badge' => 'DB']),
        ];
    }
}
