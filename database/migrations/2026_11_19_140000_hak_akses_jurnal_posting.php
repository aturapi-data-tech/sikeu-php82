<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Hak akses `jurnal.posting` (sahkan versi snapshot & posting ke buku besar) — pembagian awal: Admin, Manager Keuangan. */
return new class extends Migration {
    public function up(): void
    {
        $permission = Permission::findOrCreate('jurnal.posting', 'web');
        foreach (['Admin', 'Manager Keuangan'] as $namaRole) {
            $role = Role::where('name', $namaRole)->where('guard_name', 'web')->first();
            if ($role && !$role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'jurnal.posting')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
