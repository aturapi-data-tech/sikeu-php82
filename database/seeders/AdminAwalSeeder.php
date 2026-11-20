<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\HakAkses;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Akun Admin pertama dari .env ADMIN_AWAL_EMAIL / ADMIN_AWAL_PASSWORD (config app.admin_awal).
 * Bila email sudah ada: sandi TIDAK ditimpa, hanya dipastikan aktif & ber-role Admin.
 */
class AdminAwalSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) config('app.admin_awal.email'));
        $password = (string) config('app.admin_awal.password');

        if ($email === '' || $password === '') {
            throw new RuntimeException('Isi ADMIN_AWAL_EMAIL dan ADMIN_AWAL_PASSWORD di .env (lalu php artisan config:clear) sebelum db:seed.');
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            $this->command?->warn("User {$email} sudah ada — sandi tidak diubah.");
        } else {
            $user = new User();
            $user->name = (string) config('app.admin_awal.nama');
            $user->email = $email;
            $user->password = $password; // cast 'hashed' di model
            $user->email_verified_at = now();
            $user->save();
            $this->command?->info("User {$email} dibuat.");
        }

        if ((string) ($user->active_status ?? '1') !== '1') {
            $user->forceFill(['active_status' => '1'])->save();
        }

        $role = Role::findOrCreate(HakAkses::PERAN_SUPER, 'web');
        if (!$user->hasRole($role)) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
