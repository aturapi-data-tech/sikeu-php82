<?php

use App\Support\SkripSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pemasangan pembukuan SIKEU: menjalankan database/sql/01..11 berurutan (sumber struktur tetap file SQL itu).
 *
 * Tiap file punya PENANDA "sudah terpasang"; file yang penandanya sudah ada DILEWATI. Jadi aman untuk:
 * - server baru (semua file dijalankan),
 * - server yang sebagian file-nya sudah dijalankan manual lewat SQL*Plus (hanya sisanya yang dijalankan),
 * - Oracle lokal dev yang sudah lengkap (tidak ada yang dijalankan).
 *
 * File DDL baru (12_…) → buat migrasi baru dengan pola yang sama (SkripSql::jalankan + penanda), jangan ubah daftar ini.
 * down() sengaja kosong: membongkar pembukuan = bagian ROLLBACK di masing-masing file, dijalankan manual oleh DBA.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->catat('');
        foreach ($this->daftarFile() as $namaFile => $sudahTerpasang) {
            if ($sudahTerpasang()) {
                $this->catat("{$namaFile} — sudah terpasang, dilewati");
                continue;
            }
            $jumlah = SkripSql::jalankan(database_path("sql/{$namaFile}"));
            $this->catat("{$namaFile} — {$jumlah} pernyataan dijalankan");
        }
    }

    public function down(): void
    {
        // Sengaja kosong — lihat docblock.
    }

    /** @return array<string, \Closure(): bool> */
    private function daftarFile(): array
    {
        return [
            '01_struktur_pembukuan.sql' => fn () => $this->tabelAda('SIKEUMST_AKUNS'),
            '02_bagan_akun.sql' => fn () => $this->hitung('SELECT COUNT(*) AS c FROM sikeumst_akuns') > 0,
            '03_peta_akun_sirus.sql' => fn () => $this->hitung('SELECT COUNT(*) AS c FROM sikeumst_peta_akun_sirus') > 0,
            '04_pengaturan.sql' => fn () => $this->tabelAda('SIKEUMST_PENGATURANS'),
            '05_template_laporan.sql' => fn () => $this->templatePertamaBerisi('LABARUGI'),
            '06_pengaturan_akun_laba.sql' => fn () => $this->hitung(
                "SELECT COUNT(*) AS c FROM sikeumst_pengaturans WHERE kunci = 'akun.laba_ditahan'",
            ) > 0,
            '07_template_neraca.sql' => fn () => $this->templatePertamaBerisi('NERACA'),
            '08_dibukukan_tahun_mulai.sql' => fn () => $this->kolomAda('SIKEUMST_PETA_AKUN_SIRUS', 'DIBUKUKAN'),
            '09_template_bawaan.sql' => fn () => $this->kolomAda('SIKEUMST_LAP_HDRS', 'BAWAAN'),
            '10_template_labarugi_internal.sql' => fn () => $this->hitung(
                "SELECT COUNT(*) AS c FROM sikeumst_lap_hdrs WHERE nama = 'Laba Rugi - Internal (Manajemen)'",
            ) > 0,
            '11_template_neraca_internal.sql' => fn () => $this->hitung(
                "SELECT COUNT(*) AS c FROM sikeumst_lap_hdrs WHERE nama = 'Neraca - Internal (Rinci per Akun)'",
            ) > 0,
        ];
    }

    /** Template pertama (lap_id terkecil) jenis ini sudah punya baris — tanda 05 / 07 sudah dijalankan. */
    private function templatePertamaBerisi(string $jenis): bool
    {
        return $this->hitung(
            'SELECT COUNT(*) AS c FROM sikeumst_lap_dtls
              WHERE lap_id = (SELECT MIN(lap_id) FROM sikeumst_lap_hdrs WHERE jenis = ?)',
            [$jenis],
        ) > 0;
    }

    private function tabelAda(string $namaTabel): bool
    {
        return $this->hitung('SELECT COUNT(*) AS c FROM user_tables WHERE table_name = ?', [$namaTabel]) > 0;
    }

    private function kolomAda(string $namaTabel, string $namaKolom): bool
    {
        return $this->hitung(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            [$namaTabel, $namaKolom],
        ) > 0;
    }

    private function hitung(string $sql, array $binding = []): int
    {
        return (int) (DB::selectOne($sql, $binding)->c ?? 0);
    }

    private function catat(string $pesan): void
    {
        if (app()->runningInConsole()) {
            fwrite(STDOUT, "    {$pesan}\n");
        }
    }
};
