<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Data awal SIKEU. Bagan akun, peta akun SIRUS, pengaturan, template laporan, role & hak akses sudah dipasang
 * lewat migrasi (database/sql/01..11 + migrasi role/hak akses); seeder ini hanya membuat akun Admin pertama.
 *
 * Pemasangan server baru: php artisan migrate --seed --force
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdminAwalSeeder::class);
    }
}
