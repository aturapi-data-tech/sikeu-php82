<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hak akses awal SIKEU sebagai permission Spatie (sebelumnya daftar role di kode, App\Support\AksiRole).
 * Pembagian awal = sama persis dengan daftar lama, supaya tak ada yang berubah; sesudahnya diatur di Role Control.
 * Daftar ditulis literal di sini (bukan dari App\Support\HakAkses) supaya migrasi tidak ikut berubah bila katalog berubah.
 */
return new class extends Migration {
    private const PEMBAGIAN = [
        'laporan.lihat' => ['Admin', 'Manager Keuangan', 'Staf Akuntansi', 'Direksi'],
        'snapshot.ambil' => ['Admin', 'Manager Keuangan', 'Staf Akuntansi'],
        'jurnal.entri' => ['Admin', 'Manager Keuangan', 'Staf Akuntansi'],
        'periode.kunci' => ['Admin', 'Manager Keuangan'],
        'tutupBuku.jalankan' => ['Admin', 'Manager Keuangan'],
        'akun.kelola' => ['Admin', 'Manager Keuangan'],
        'sistem.kelola' => ['Admin'],
    ];

    public function up(): void
    {
        foreach (self::PEMBAGIAN as $namaPermission => $roleList) {
            $permission = Permission::findOrCreate($namaPermission, 'web');
            foreach ($roleList as $namaRole) {
                $role = Role::where('name', $namaRole)->where('guard_name', 'web')->first();
                if ($role && !$role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_keys(self::PEMBAGIAN))->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
