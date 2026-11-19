<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kolom users tambahan (pola SIRUS/SIAP):
 * - myuser_profesi   → label peran tetap untuk user multi-role (User::labelPeran)
 * - active_status    → '1' aktif / '0' nonaktif (diblokir login)
 * - myuser_code / myuser_name / myuser_ttd_image / emp_id → komponen TTD & PDF (App\Support\TtdUser)
 * - last_seen_at / last_seen_route → middleware TrackUserActivity
 *
 * Idempotent: kolom yang sudah ada dilewati.
 */
return new class extends Migration {
    private array $daftarKolom = [
        'MYUSER_PROFESI'   => 'myuser_profesi VARCHAR2(30)',
        'ACTIVE_STATUS'    => "active_status VARCHAR2(1) DEFAULT '1' NOT NULL",
        'MYUSER_CODE'      => 'myuser_code VARCHAR2(50)',
        'MYUSER_NAME'      => 'myuser_name VARCHAR2(200)',
        'MYUSER_TTD_IMAGE' => 'myuser_ttd_image VARCHAR2(255)',
        'EMP_ID'           => 'emp_id VARCHAR2(50)',
        'LAST_SEEN_AT'     => 'last_seen_at TIMESTAMP',
        'LAST_SEEN_ROUTE'  => 'last_seen_route VARCHAR2(255)',
    ];

    public function up(): void
    {
        foreach ($this->daftarKolom as $namaKolom => $definisi) {
            if (!$this->kolomAda($namaKolom)) {
                DB::statement("ALTER TABLE users ADD ({$definisi})");
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->daftarKolom) as $namaKolom) {
            if ($this->kolomAda($namaKolom)) {
                DB::statement('ALTER TABLE users DROP COLUMN ' . strtolower($namaKolom));
            }
        }
    }

    private function kolomAda(string $namaKolom): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            ['USERS', $namaKolom],
        );

        return (int) ($row->c ?? 0) > 0;
    }
};
