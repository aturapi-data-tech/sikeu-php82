<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role awal SIKEU. Hak tiap role: App\Services\AppMenu (halaman) & App\Support\AksiRole (aksi).
 * - Admin            : semua, termasuk Sistem
 * - Manager Keuangan : semua menu keuangan + aksi pengesahan (kunci periode, tutup buku)
 * - Staf Akuntansi   : entri & laporan, tanpa pengesahan
 * - Direksi          : baca laporan saja
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['Admin', 'Manager Keuangan', 'Staf Akuntansi', 'Direksi'] as $namaRole) {
            Role::findOrCreate($namaRole, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::whereIn('name', ['Manager Keuangan', 'Staf Akuntansi', 'Direksi'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
